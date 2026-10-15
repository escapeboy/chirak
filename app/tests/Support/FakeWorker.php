<?php

namespace Tests\Support;

use App\Models\Run;
use App\Support\TaskTemplate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** In-memory stand-in for the GPU worker and the Anthropic API. Records every outgoing request. */
class FakeWorker
{
    public const SHA = '694061131c4d9263dd454d770ca82bfa543f4c683d262e56e9dc37d099d17297'; // sha256 of the artifact body below

    public bool $offline = false;

    /** Type of another run's job that holds the GPU: GPU calls answer 409 "GPU is busy" while set. */
    public ?string $busyWith = null;

    /** Error message for the next accepted job, which then ends as failed. */
    public ?string $failNextJob = null;

    /** Makes every Anthropic call answer with this HTTP status. */
    public ?int $claudeHttpStatus = null;

    public float $baseline = 0.50;

    /** macro_f1 of iteration 1, 2, 3 */
    public array $iterations = [1 => 0.60, 2 => 0.70, 3 => 0.80];

    /** macro_f1 on eval-hard: 'base', 1, 2, 3 */
    public array $hard = ['base' => 0.40, 1 => 0.50, 2 => 0.60, 3 => 0.70];

    /** leakage_drop_rate of train iteration 1, 2, 3 */
    public array $leakage = [1 => 0.10, 2 => 0.10, 3 => 0.10];

    /** Queue of Anthropic answers: 'valid' | 'garbage' | 'too_many' | 'stop_true' */
    public array $claude = [];

    /** Answers a tagged Anthropic call: closure(array $request): string raw text, array{text, stop_reason}, or int for an HTTP error status. */
    public ?\Closure $labeler = null;

    /** macro_f1 of the own-tickets slice for the base model and the adapter */
    public array $own = ['base' => 0.55, 'adapter' => 0.85];

    /** Content the worker holds per uploaded file name */
    public array $files = [];

    /** @var array<int, array{method: string, path: string, body: array}> */
    public array $requests = [];

    private array $jobs = [];

    public function install(): self
    {
        Http::fake(fn (Request $request) => $this->handle($request));

        return $this;
    }

    public function sent(string $method, string $path): array
    {
        return array_values(array_filter($this->requests, fn ($r) => $r['method'] === $method && $r['path'] === $path));
    }

    private function handle(Request $request)
    {
        $url = parse_url($request->url());

        if ($url['host'] === 'api.anthropic.com') {
            $this->requests[] = ['method' => 'POST', 'path' => '/anthropic', 'body' => $request->data()];

            if ($this->claudeHttpStatus) {
                return Http::response(['error' => 'overloaded'], $this->claudeHttpStatus);
            }
            if ($this->labeler) {
                $answer = ($this->labeler)($request->data());
                if (is_int($answer)) {
                    return Http::response(['error' => 'overloaded'], $answer);
                }

                $answer = is_array($answer) ? $answer : ['text' => $answer];

                return Http::response(['content' => [['type' => 'text', 'text' => $answer['text']]], 'stop_reason' => $answer['stop_reason'] ?? 'end_turn', 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]]);
            }

            return Http::response(['content' => [['type' => 'text', 'text' => $this->claudeAnswer($request->data())]]]);
        }

        if ($this->offline) {
            throw new ConnectionException('cURL error 7: connection refused');
        }

        $path = $url['path'];
        $body = $request->data();
        $this->requests[] = ['method' => $request->method(), 'path' => $path, 'body' => $body];

        if ($this->busyWith && in_array($path, ['/generate', '/train', '/evaluate', '/serve'], true)) {
            return Http::response(['detail' => "GPU is busy with a {$this->busyWith} job"], 409);
        }

        return match (true) {
            $path === '/generate' => $this->accept('generate', $this->generated($body)),
            $path === '/train' => $this->accept('train', $this->trained($body)),
            $path === '/evaluate' => $this->accept('evaluate', $this->evaluated($body)),
            str_starts_with($path, '/jobs/') => $this->job(substr($path, 6)),
            $path === '/serve' => Http::response(['name' => $body['name'], 'loaded' => true]),
            $path === '/predict' => Http::response(['raw' => '{"category":"returns"}', 'parsed' => ['category' => 'returns'], 'valid' => true, 'error' => null, 'latency_ms' => 120]),
            $path === '/health' => Http::response(['status' => 'ok', 'gpu' => ['visible' => true, 'name' => 'AMD Instinct MI300X'], 'queue_length' => 0]),
            $request->method() === 'DELETE' && preg_match('#^/runs/[^/]+/own$#', $path) === 1 => $this->deletedOwn(str_contains($url['query'] ?? '', 'train_real=1')),
            preg_match('#^/runs/[^/]+/files/([^/]+)$#', $path, $m) === 1 => $this->stored($m[1], $request->body()),
            str_starts_with($path, '/artifacts/') => Http::response("{\"id\":\"x-1\",\"input\":\"Sample ticket\",\"output\":{\"category\":\"orders\"},\"source\":\"synthetic\"}\n"),
            default => Http::response(['detail' => 'not found'], 404),
        };
    }

    private function deletedOwn(bool $trainReal)
    {
        $names = $trainReal ? ['eval-own.jsonl', 'train-real.jsonl'] : ['eval-own.jsonl'];
        $deleted = array_values(array_filter($names, fn ($n) => isset($this->files[$n])));
        foreach ($names as $n) {
            unset($this->files[$n]);
        }

        return Http::response(['deleted' => $deleted]);
    }

    private function stored(string $name, string $content)
    {
        if (isset($this->files[$name]) && $this->files[$name] !== $content) {
            return Http::response(['detail' => "$name already exists with different content"], 409);
        }
        $this->files[$name] = $content;

        return Http::response(['path' => "/data/runs/x/$name", 'sha256' => hash('sha256', $content), 'bytes' => strlen($content)]);
    }

    private function accept(string $type, array $result)
    {
        $id = 'job_'.(count($this->jobs) + 1);
        $this->jobs[$id] = ['id' => $id, 'type' => $type, 'status' => 'succeeded', 'result' => $result, 'error' => null, 'progress' => null];
        if ($this->failNextJob) {
            $this->jobs[$id] = ['status' => 'failed', 'result' => null, 'error' => $this->failNextJob] + $this->jobs[$id];
            $this->failNextJob = null;
        }

        return Http::response(['job_id' => $id], 202);
    }

    private function job(string $id)
    {
        return isset($this->jobs[$id]) ? Http::response($this->jobs[$id]) : Http::response(['detail' => 'unknown job'], 404);
    }

    private function generated(array $b): array
    {
        $i = $b['iteration'] ?? null;
        $file = $b['purpose'] === 'eval' ? 'eval.jsonl' : "train-$i.jsonl";
        $rate = $b['purpose'] === 'eval' ? 0 : ($this->leakage[$i] ?? 0.1);
        $candidates = 1000;

        return [
            'path' => "/data/runs/{$b['run_id']}/$file",
            'sha256' => self::SHA,
            'n' => $b['n'],
            'n_candidates' => $candidates,
            'by_slice' => ['orders' => 10],
            'dropped' => ['duplicate' => 5, 'leakage_cosine' => (int) ($rate * $candidates * 0.8), 'leakage_ngram' => (int) ($rate * $candidates * 0.2)],
            'leakage_drop_rate' => $rate,
            'frozen' => $b['purpose'] === 'eval',
        ];
    }

    private function trained(array $b): array
    {
        $dir = "/data/runs/{$b['run_id']}/iter-{$b['iteration']}";

        return [
            'adapter_path' => "$dir/adapter",
            'adapter_archive' => "$dir/adapter.tar.gz",
            'loss_curve' => [['step' => 10, 'loss' => 1.2], ['step' => 20, 'loss' => 0.7]],
            'n_examples' => 1000, 'epochs' => 2, 'wall_s' => 300.0, 'peak_vram_gb' => 14.2,
        ];
    }

    private function evaluated(array $b): array
    {
        $run = Run::where('slug', $b['run_id'])->firstOrFail();
        if (str_ends_with($b['label'], '.eval-own')) {
            return $this->evaluatedOwn($b);
        }
        $hard = str_ends_with($b['label'], '.eval-hard');
        $label = $hard ? substr($b['label'], 0, -strlen('.eval-hard')) : $b['label'];
        $i = $label === 'base' ? 'base' : (int) substr($label, 5);
        $f1 = $hard ? $this->hard[$i] : ($i === 'base' ? $this->baseline : $this->iterations[$i]);
        $labels = $run->primaryField()['values'];

        $perClass = [];
        foreach ($labels as $k => $label) {
            $perClass[$label] = ['precision' => $f1, 'recall' => $f1, 'f1' => round($f1 + ($k - 3) * 0.02, 3), 'support' => 30];
        }

        return [
            'label' => $b['label'], 'model' => $b['model'], 'eval_sha256' => self::SHA, 'n' => 215,
            'json_valid_rate' => 0.97, 'accuracy' => $f1 + 0.02, 'macro_f1' => $f1,
            'per_class' => $perClass,
            'confusion' => ['labels' => [...$labels, '__invalid__'], 'matrix' => array_map(fn () => array_fill(0, count($labels) + 1, 1), $labels)],
            'fields' => ['urgency' => ['accuracy' => 0.7]],
            'exact_match' => 0.5, 'latency_ms' => ['p50' => 180, 'p95' => 410],
            'failures' => [['id' => 'eval-0007', 'input' => 'Failing ticket', 'expected' => ['category' => 'returns'], 'got' => null, 'raw' => 'oops', 'error' => 'invalid json']],
        ];
    }

    private function evaluatedOwn(array $b): array
    {
        $f1 = $b['label'] === 'base.eval-own' ? $this->own['base'] : $this->own['adapter'];

        return [
            'label' => $b['label'], 'model' => $b['model'], 'eval_sha256' => hash('sha256', $this->files['eval-own.jsonl'] ?? ''),
            'n' => substr_count($this->files['eval-own.jsonl'] ?? '', "\n"), 'json_valid_rate' => 1.0, 'accuracy' => $f1 + 0.02, 'macro_f1' => $f1,
            'per_class' => [], 'confusion' => ['labels' => [], 'matrix' => []],
            'fields' => ['category' => ['accuracy' => $f1 + 0.02], 'urgency' => ['accuracy' => 0.7], 'needs_human' => ['accuracy' => 0.9]],
            'exact_match' => 0.5, 'latency_ms' => ['p50' => 180, 'p95' => 410],
            'failures' => [['id' => 'own-0001', 'input' => 'Private ticket text', 'expected' => ['category' => 'returns'], 'got' => null, 'raw' => 'oops', 'error' => 'invalid json']],
        ];
    }

    private function claudeAnswer(array $request): string
    {
        $mode = array_shift($this->claude) ?? 'valid';

        return match ($mode) {
            'garbage' => 'I think you should focus on returns.',
            'too_many' => json_encode(['stop' => false, 'reason' => 'x', 'next_slices' => [['name' => 'returns', 'n' => 99999, 'guidance' => 'g']]]),
            'stop_true' => json_encode(['stop' => true, 'reason' => 'enough', 'next_slices' => [['name' => 'returns', 'n' => 300, 'guidance' => 'Returns mixed with payments.']]]),
            default => "```json\n".json_encode(['stop' => false, 'reason' => 'returns confused with payments', 'next_slices' => [
                ['name' => 'returns', 'n' => 300, 'guidance' => 'Returns mixed with payment questions.'],
                ['name' => 'complaints', 'n' => 200, 'guidance' => 'Angry but polite tone.'],
            ]])."\n```",
        };
    }
}
