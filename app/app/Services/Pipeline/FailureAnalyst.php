<?php

namespace App\Services\Pipeline;

use App\Models\Evaluation;
use App\Models\Run;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

/**
 * Asks Claude where the adapter fails and which training slices to generate next.
 * Its answer only steers data generation; termination is decided by StopRules.
 */
class FailureAnalyst
{
    private const SYSTEM = <<<'TXT'
You are the failure analyst of an automated fine-tuning loop. A small model was fine-tuned on synthetic data for the task below and evaluated on a frozen eval set. Study the metrics, the confusion matrix and the failures, then decide which synthetic training slices to generate next.

Reply with STRICT JSON only, no prose and no code fences:
{"stop": boolean, "reason": string, "next_slices": [{"name": string, "n": integer, "guidance": string}]}

Rules:
- At most 6 slices; the sum of all "n" must not exceed the given train_n.
- "name" is a short non-empty label (usually a class or a confusable pair of classes).
- "guidance" describes the kind of situation to generate in general terms. Never quote or paraphrase a specific failing ticket: the eval set must stay unseen.
- "stop" is your opinion on whether more training would help; it is recorded but does not end the loop.
TXT;

    public function analyze(Run $run, Evaluation $latest, array $previousDecisions, ?Evaluation $devEval = null): array
    {
        $input = [
            'model' => config('chirak.orchestrator.model'),
            // The model thinks adaptively; 2000 left too little room for the answer with the eval-hard judge.
            'max_tokens' => 8000,
            'system' => self::SYSTEM,
            'messages' => [['role' => 'user', 'content' => $this->userPrompt($run, $latest, $previousDecisions, $devEval)]],
        ];

        $attempts = [];
        $valid = null;

        for ($try = 1; $try <= 2 && $valid === null; $try++) {
            $messages = $input['messages'];
            if ($attempts) {
                $messages[] = ['role' => 'assistant', 'content' => $attempts[0]['raw'] ?: '(no answer)'];
                $messages[] = ['role' => 'user', 'content' => 'That answer was rejected: '.implode('; ', $attempts[0]['errors']).'. Reply again with the strict JSON only.'];
            }

            $attempt = $this->ask(array_merge($input, ['messages' => $messages]), $run->train_n);
            $attempts[] = $attempt;
            $valid = $attempt['errors'] ? null : $attempt['parsed'];
        }

        $fallback = $valid === null;
        $slices = $fallback ? $this->fallbackSlices($latest, $run->train_n) : $valid['next_slices'];

        return [
            'input' => $input,
            'output' => [
                'used' => $fallback ? 'fallback' : 'claude',
                'claude_stop' => $valid['stop'] ?? null,
                'reason' => $valid['reason'] ?? 'validation failed twice; fell back to the 3 worst-F1 classes',
                'attempts' => $attempts,
            ],
            'next_slices' => $slices,
            'model' => config('chirak.orchestrator.model'),
        ];
    }

    /** @return string[] validation errors, empty when the answer is acceptable */
    public static function validate(mixed $data, int $trainN): array
    {
        if (! is_array($data)) {
            return ['answer is not a JSON object'];
        }

        $validator = Validator::make($data, [
            'stop' => [function ($attribute, $value, $fail) {
                if (! is_bool($value)) {
                    $fail('stop must be a boolean');
                }
            }],
            'reason' => ['required', 'string'],
            'next_slices' => ['required', 'array', 'min:1', 'max:6'],
            'next_slices.*.name' => ['required', 'string', 'min:1'],
            'next_slices.*.n' => ['required', 'integer', 'min:1'],
            'next_slices.*.guidance' => ['required', 'string'],
        ]);

        $errors = $validator->errors()->all();

        if (! $errors && collect($data['next_slices'])->sum('n') > $trainN) {
            $errors[] = 'the sum of next_slices.n exceeds train_n ('.$trainN.')';
        }

        return $errors;
    }

    public function fallbackSlices(Evaluation $latest, int $trainN): array
    {
        $perClass = $latest->metrics['per_class'] ?? [];
        uasort($perClass, fn ($a, $b) => ($a['f1'] ?? 0) <=> ($b['f1'] ?? 0));
        $worst = array_slice(array_keys($perClass), 0, 3);

        if (! $worst) {
            return [];
        }

        $each = intdiv($trainN, count($worst));
        $rest = $trainN - $each * count($worst);

        return array_map(fn ($class, $i) => [
            'name' => (string) $class,
            'n' => $each + ($i === 0 ? $rest : 0),
            'guidance' => 'More varied examples of this class; the model scored lowest on it.',
        ], $worst, array_keys($worst));
    }

    private function ask(array $request, int $trainN): array
    {
        $attempt = ['raw' => null, 'parsed' => null, 'errors' => []];

        if (! config('chirak.orchestrator.api_key')) {
            $attempt['errors'][] = 'ANTHROPIC_API_KEY is not configured';

            return $attempt;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('chirak.orchestrator.api_key'),
                'anthropic-version' => '2023-06-01',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', $request);
        } catch (ConnectionException $e) {
            $attempt['errors'][] = 'Anthropic API unreachable';

            return $attempt;
        }

        if ($response->failed()) {
            $attempt['errors'][] = 'Anthropic API answered HTTP '.$response->status();

            return $attempt;
        }

        $attempt['raw'] = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        $attempt['parsed'] = $this->decode($attempt['raw']);
        $attempt['errors'] = self::validate($attempt['parsed'], $trainN);

        return $attempt;
    }

    private function decode(string $raw): mixed
    {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        return $start !== false && $end > $start ? json_decode(substr($raw, $start, $end - $start + 1), true) : null;
    }

    private function userPrompt(Run $run, Evaluation $latest, array $previousDecisions, ?Evaluation $devEval = null): string
    {
        $source = $devEval ?? $latest;
        $metrics = $source->metrics;
        $failures = array_map(fn ($f) => [
            'input' => mb_substr((string) ($f['input'] ?? ''), 0, 400),
            'expected' => $f['expected'] ?? null,
            'got' => $f['got'] ?? null,
            'error' => $f['error'] ?? null,
        ], array_slice($source->failures ?? [], 0, 50));

        $formattedDecisions = array_map(function ($d) {
            $entry = [
                'iteration' => $d['iteration'],
                'reason' => $d['reason'],
                'next_slices' => $d['next_slices'],
            ];
            if (isset($d['delta_metrics'])) {
                $entry['delta_metrics'] = $d['delta_metrics'];
            }

            return $entry;
        }, $previousDecisions);

        return json_encode([
            'task_spec' => $run->task_spec,
            'target' => ['metric' => $run->target_metric, 'value' => $run->target_value],
            'train_n' => $run->train_n,
            'iteration' => $latest->iteration,
            'metrics' => collect($metrics)->except(['confusion', 'failures'])->all(),
            'confusion' => $metrics['confusion'] ?? null,
            'failures' => $failures,
            'previous_decisions' => $formattedDecisions,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
