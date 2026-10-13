<?php

namespace App\Services\Worker;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/** HTTP client for the GPU worker. Contract: docs/worker-api.md. */
class WorkerClient
{
    public function generate(array $payload): string
    {
        return $this->post('/generate', $payload)->json('job_id');
    }

    public function train(array $payload): string
    {
        return $this->post('/train', $payload)->json('job_id');
    }

    public function evaluate(array $payload): string
    {
        return $this->post('/evaluate', $payload)->json('job_id');
    }

    public function job(string $jobId): array
    {
        return $this->get('/jobs/'.$jobId)->json();
    }

    public function serve(string $name, string $adapterPath): array
    {
        return $this->post('/serve', ['name' => $name, 'adapter_path' => $adapterPath])->json();
    }

    public function predict(string $model, array $taskSpec, string $input): array
    {
        return $this->post('/predict', ['model' => $model, 'task_spec' => $taskSpec, 'input' => $input], 90)->json();
    }

    /** Full health body, or null when the worker cannot be reached or rejects the token. */
    public function health(): ?array
    {
        try {
            $response = $this->request(5)->get('/health');
        } catch (ConnectionException) {
            return null;
        }

        $body = $response->successful() ? $response->json() : null;

        return isset($body['gpu']) ? $body : null;
    }

    /** Copies /data/runs/<run>/<path> from the worker into the `runs` disk at $localPath. */
    public function downloadArtifact(string $runId, string $path, string $localPath): void
    {
        $disk = Storage::disk('runs');
        $tmp = tempnam(sys_get_temp_dir(), 'chirak-artifact-');

        try {
            $response = $this->request(900)->withOptions(['sink' => $tmp])->get('/artifacts/'.$runId.'/'.$path);
            $this->check($response);
            $disk->put($localPath, fopen($tmp, 'rb'));
        } catch (ConnectionException $e) {
            throw new WorkerUnreachable($e->getMessage(), 0, $e);
        } finally {
            @unlink($tmp);
        }
    }

    private function request(int $timeout = 30): PendingRequest
    {
        return Http::baseUrl(rtrim(config('chirak.worker.base_url'), '/'))
            ->withToken((string) config('chirak.worker.token'))
            ->acceptJson()
            ->timeout($timeout);
    }

    private function get(string $path): Response
    {
        return $this->send(fn () => $this->request()->get($path));
    }

    private function post(string $path, array $payload, int $timeout = 30): Response
    {
        return $this->send(fn () => $this->request($timeout)->post($path, $payload));
    }

    private function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new WorkerUnreachable($e->getMessage(), 0, $e);
        }

        return $this->check($response);
    }

    private function check(Response $response): Response
    {
        if (in_array($response->status(), [502, 503, 504], true)) {
            throw new WorkerUnreachable('worker answered HTTP '.$response->status());
        }

        if ($response->failed()) {
            throw new WorkerRejected($response->status(), (string) ($response->json('detail') ?? $response->body()));
        }

        return $response;
    }
}
