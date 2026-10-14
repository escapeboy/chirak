<?php

namespace App\Jobs;

use App\Models\Evaluation;
use App\Models\Run;
use App\Services\Worker\WorkerClient;
use App\Services\Worker\WorkerRejected;
use App\Services\Worker\WorkerUnreachable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Puts the frozen own-tickets file on the worker, then scores the base model and the served adapter on it,
 * one worker job at a time, re-dispatching itself while a job runs. Scores already stored are not redone.
 */
class EvaluateOwnSet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $runId, public int $attempt = 0)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('own-eval-'.$this->runId))->releaseAfter(10)->expireAfter(600)];
    }

    public function handle(WorkerClient $worker): void
    {
        $run = Run::find($this->runId);
        $set = $run?->ownSet()->first();
        $dataset = $run?->datasets()->where('purpose', 'eval-own')->first();
        if (! $set?->isFrozen() || ! $dataset || $set->eval_state === 'done') {
            return;
        }

        try {
            if ($set->worker_job_id) {
                $job = $worker->job($set->worker_job_id);
                if (! in_array($job['status'] ?? null, ['succeeded', 'failed'], true)) {
                    $set->update(['eval_state' => 'running', 'error' => null]);
                    $this->again(config('chirak.worker.poll_delay'));

                    return;
                }
                if ($job['status'] === 'failed') {
                    $set->update(['eval_state' => 'failed', 'worker_job_id' => null, 'error' => 'worker job failed: '.($job['error'] ?? 'unknown error')]);

                    return;
                }
                $this->store($run, $dataset, $set->eval_stage, $job['result'] ?? []);
                $set->update(['worker_job_id' => null]);
            }

            $target = collect($this->targets($run))->first(fn ($t) => ! $run->ownEvaluations()->where('iteration', $t['iteration'])->exists());
            if (! $target) {
                $set->update(['eval_state' => 'done', 'eval_stage' => null, 'error' => null]);

                return;
            }

            if (! $dataset->local_path || ! Storage::disk('runs')->exists($dataset->local_path)) {
                $set->update(['eval_state' => 'failed', 'error' => 'the frozen tickets file is gone, so it cannot be evaluated']);

                return;
            }
            $worker->putFile($run->slug, 'eval-own.jsonl', (string) Storage::disk('runs')->get($dataset->local_path));
            $jobId = $worker->evaluate([
                'run_id' => $run->slug, 'eval_file' => $dataset->remote_path, 'task_spec' => $run->task_spec,
                'label' => $target['label'].'.eval-own', 'model' => $target['model'],
            ]);
            $set->update(['eval_state' => 'running', 'eval_stage' => $target['label'], 'worker_job_id' => $jobId, 'error' => null]);
            $this->again(min(5, config('chirak.worker.poll_delay')));
        } catch (WorkerUnreachable $e) {
            $set->update(['error' => 'worker unreachable: '.$e->getMessage()]);
            self::dispatch($run->id, $this->attempt + 1)->delay(now()->addSeconds(min(300, 15 * 2 ** min($this->attempt, 5))));
        } catch (WorkerRejected $e) {
            $set->update(['eval_state' => 'failed', 'worker_job_id' => null, 'error' => 'worker rejected the request ('.$e->status.'): '.$e->getMessage()]);
        }
    }

    /** @return array<int, array{label: string, model: string, iteration: ?int}> */
    private function targets(Run $run): array
    {
        $targets = [['label' => 'base', 'model' => 'base', 'iteration' => null]];
        $adapter = $run->best_iteration ? $run->iterations()->where('n', $run->best_iteration)->value('adapter_path') : null;
        if ($run->served_name && $adapter) {
            $targets[] = ['label' => $run->served_name, 'model' => $adapter, 'iteration' => $run->best_iteration];
        }

        return $targets;
    }

    private function store(Run $run, $dataset, ?string $stage, array $metrics): void
    {
        $target = collect($this->targets($run))->firstWhere('label', $stage);
        if (! $target) {
            throw new WorkerRejected(409, "unexpected evaluation stage $stage");
        }
        if (($metrics['eval_sha256'] ?? null) !== $dataset->sha256) {
            throw new WorkerRejected(409, 'own tickets hash changed: stored '.$dataset->sha256.', evaluation used '.($metrics['eval_sha256'] ?? 'none'));
        }
        unset($metrics['failures']); // they would repeat ticket texts

        Evaluation::updateOrCreate(
            ['run_id' => $run->id, 'slice' => 'own', 'iteration' => $target['iteration']],
            ['dataset_id' => $dataset->id, 'adapter_path' => $target['iteration'] ? $target['model'] : null, 'metrics' => $metrics, 'failures' => [], 'eval_sha256' => $dataset->sha256],
        );
    }

    private function again(int $seconds): void
    {
        self::dispatch($this->runId)->delay(now()->addSeconds($seconds));
    }
}
