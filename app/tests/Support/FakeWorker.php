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

    /** Error message for the next accepted job, which then ends as failed. */
    public ?string $failNextJob = null;

    /** Makes every Anthropic call answer with this HTTP status. */
    public ?int $claudeHttpStatus = null;

    public float $baseline = 0.50;

    /** macro_f1 of iteration 1, 2, 3 */
    public array $iterations = [1 => 0.60, 2 => 0.70, 3 => 0.80];

    /** leakage_drop_rate of train iteration 1, 2, 3 */
    public array $leakage = [1 => 0.10, 2 => 0.10, 3 => 0.10];

    /** Queue of Anthropic answers: 'valid' | 'garbage' | 'too_many' | 'stop_true' */
    public array $claude = [];

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

            return Http::response(['content' => [['type' => 'text', 'text' => $this->claudeAnswer($request->data())]]]);
        }

        if ($this->offline) {
            throw new ConnectionException('cURL error 7: connection refused');
        }

        $path = $url['path'];
        $body = $request->data();
        $this->requests[] = ['method' => $request->method(), 'path' => $path, 'body' => $body];

        return match (true) {
            $path === '/generate' => $this->accept('generate', $this->generated($body)),
            $path === '/train' => $this->accept('train', $this->trained($body)),
            $path === '/evaluate' => $this->accept('evaluate', $this->evaluated($body)),
            str_starts_with($path, '/jobs/') => $this->job(substr($path, 6)),
            $path === '/serve' => Http::response(['name' => $body['name'], 'loaded' => true]),
            $path === '/predict' => Http::response(['raw' => '{"category":"returns"}', 'parsed' => ['category' => 'returns'], 'valid' => true, 'error' => null, 'latency_ms' => 120]),
            $path === '/health' => Http::response(['status' => 'ok', 'gpu' => ['visible' => true, 'name' => 'AMD Instinct MI300X'], 'queue_length' => 0]),
            str_starts_with($path, '/artifacts/') => Http::response("{\"id\":\"x-1\",\"input\":\"Sample ticket\",\"output\":{\"category\":\"orders\"},\"source\":\"synthetic\"}\n"),
            default => Http::response(['detail' => 'not found'], 404),
        };
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
        $f1 = $b['label'] === 'base' ? $this->baseline : $this->iterations[(int) substr($b['label'], 5)];
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
