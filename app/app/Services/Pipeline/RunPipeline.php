<?php

namespace App\Services\Pipeline;

use App\Jobs\AdvanceRun;
use App\Jobs\PollWorkerJob;
use App\Models\Dataset;
use App\Models\Decision;
use App\Models\Evaluation;
use App\Models\Iteration;
use App\Models\Run;
use App\Services\Worker\WorkerClient;
use App\Services\Worker\WorkerRejected;
use App\Services\Worker\WorkerUnreachable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The orchestrator loop as a state machine. nextStep() derives the next action from DB state alone;
 * advance() performs it, poll() collects the result of a worker job. Both are driven by small queued jobs.
 */
class RunPipeline
{
    private const LORA = [
        'r' => 16, 'alpha' => 16, 'dropout' => 0.05,
        'target_modules' => ['q_proj', 'k_proj', 'v_proj', 'o_proj', 'gate_proj', 'up_proj', 'down_proj'],
    ];

    public function __construct(private WorkerClient $worker, private FailureAnalyst $analyst)
    {
    }

    public function start(Run $run): void
    {
        $run->update(['status' => Run::RUNNING, 'error' => null]);
        AdvanceRun::dispatch($run->id);
    }

    public function approve(Run $run): void
    {
        if ($run->status !== Run::AWAITING_REVIEW) {
            return;
        }

        $run->update(['review_approved_at' => now(), 'status' => Run::RUNNING]);
        AdvanceRun::dispatch($run->id);
    }

    /**
     * The next action, derived only from the database.
     *
     * @return array{step: string, iteration: ?int}
     */
    public function nextStep(Run $run): array
    {
        $eval = $this->dataset($run, 'eval');
        if (! $eval) {
            return ['step' => 'generate_eval', 'iteration' => null];
        }

        $baseline = $this->evaluation($run, null);
        if (! $baseline) {
            return ['step' => 'evaluate_base', 'iteration' => null];
        }

        // With an eval-hard set the target, the analysis and the stop rules use the hard slice;
        // every model is still scored on both.
        $slice = $this->targetSlice($run);
        if ($slice === 'hard' && ! $this->evaluation($run, null, 'hard')) {
            return ['step' => 'evaluate_hard', 'iteration' => null];
        }

        if ($run->metricOf($this->evaluation($run, null, $slice)->metrics) >= $run->target_value) {
            return ['step' => 'not_worth_it', 'iteration' => null];
        }

        for ($i = 1; $i <= config('chirak.stop.max_iterations'); $i++) {
            $train = $this->dataset($run, 'train', $i);
            if (! $train) {
                return ['step' => 'generate_train', 'iteration' => $i];
            }

            if (StopRules::leakageExceeded($train->leakageDropRate())) {
                return ['step' => 'leakage_stop', 'iteration' => $i];
            }

            if ($i === 1 && $run->require_review && ! $run->review_approved_at) {
                return ['step' => 'await_review', 'iteration' => $i];
            }

            if (! $run->iterations()->where('n', $i)->whereNotNull('finished_at')->exists()) {
                return ['step' => 'train', 'iteration' => $i];
            }

            if (! $this->evaluation($run, $i)) {
                return ['step' => 'evaluate', 'iteration' => $i];
            }

            if ($slice === 'hard' && ! $this->evaluation($run, $i, 'hard')) {
                return ['step' => 'evaluate_hard', 'iteration' => $i];
            }

            $decision = $this->decision($run, $i, $slice);
            if (! $decision) {
                return ['step' => 'decide', 'iteration' => $i];
            }

            if ($decision->stop_rule['stop']) {
                break;
            }
        }

        return ['step' => 'finalize', 'iteration' => null];
    }

    public function advance(Run $run, int $attempt = 0): void
    {
        $this->guarded($run, $attempt, function () use ($run) {
            $run->refresh();
            if ($run->isTerminal() || $run->current_worker_job_id || $this->isWaitingForReview($run)) {
                return;
            }

            $this->recover($run);

            while (true) {
                $next = $this->nextStep($run);
                $i = $next['iteration'];

                switch ($next['step']) {
                    case 'not_worth_it':
                        $run->update(['status' => Run::NOT_WORTH_IT, 'stage' => 'done', 'stop_reason' => 'baseline already meets target']);

                        return;
                    case 'leakage_stop':
                        $this->stopOnLeakage($run, $i);

                        return;
                    case 'await_review':
                        $run->update(['status' => Run::AWAITING_REVIEW, 'stage' => 'awaiting_review']);

                        return;
                    case 'decide':
                        $this->decide($run, $i);

                        break;
                    case 'finalize':
                        $this->finalize($run);

                        return;
                    default:
                        $this->submit($run, $next['step'], $i);

                        return;
                }
            }
        });
    }

    public function poll(Run $run, int $attempt = 0): void
    {
        $this->guarded($run, $attempt, function () use ($run) {
            $run->refresh();
            if ($run->isTerminal()) {
                return;
            }
            if (! $run->current_worker_job_id) {
                $this->advance($run);

                return;
            }

            $job = $this->worker->job($run->current_worker_job_id);
            $this->recover($run);

            switch ($job['status'] ?? null) {
                case 'succeeded':
                    $this->handleResult($run, $job['result'] ?? []);
                    $run->update(['current_worker_job_id' => null]);
                    Cache::forget($this->progressKey($run));
                    $this->advance($run);
                    break;
                case 'failed':
                    $this->fail($run, 'worker job failed at '.$run->stage.': '.($job['error'] ?? 'unknown error'));
                    break;
                default:
                    Cache::put($this->progressKey($run), $job['progress'] ?? null, 600);
                    PollWorkerJob::dispatch($run->id)->delay(now()->addSeconds(config('chirak.worker.poll_delay')));
            }
        });
    }

    public function progress(Run $run): ?array
    {
        return Cache::get($this->progressKey($run));
    }

    private function progressKey(Run $run): string
    {
        return 'run.'.$run->id.'.progress';
    }

    private function isWaitingForReview(Run $run): bool
    {
        return $run->status === Run::AWAITING_REVIEW && ! $run->review_approved_at;
    }

    /** Worker answered again after an outage. */
    private function recover(Run $run): void
    {
        if ($run->status === Run::WAITING_FOR_WORKER) {
            $run->update(['status' => Run::RUNNING, 'error' => null]);
        }
    }

    private function guarded(Run $run, int $attempt, callable $work): void
    {
        try {
            $work();
        } catch (WorkerUnreachable $e) {
            $run->update(['status' => Run::WAITING_FOR_WORKER, 'error' => 'worker unreachable: '.$e->getMessage()]);
            $delay = now()->addSeconds(min(300, 15 * 2 ** min($attempt, 5)));
            $run->current_worker_job_id
                ? PollWorkerJob::dispatch($run->id, $attempt + 1)->delay($delay)
                : AdvanceRun::dispatch($run->id, $attempt + 1)->delay($delay);
        } catch (WorkerRejected $e) {
            if ($e->status !== 409 || ! str_starts_with($e->getMessage(), 'GPU is busy')) {
                $this->fail($run, 'worker rejected the request ('.$e->status.'): '.$e->getMessage());

                return;
            }
            // another run holds the GPU (the worker runs one generate or train job at a time): wait like for an outage
            $run->update(['status' => Run::WAITING_FOR_WORKER, 'error' => $e->getMessage()]);
            $delay = now()->addSeconds(min(300, 15 * 2 ** min($attempt, 5)));
            $run->current_worker_job_id
                ? PollWorkerJob::dispatch($run->id, $attempt + 1)->delay($delay)
                : AdvanceRun::dispatch($run->id, $attempt + 1)->delay($delay);
        }
    }

    private function fail(Run $run, string $error): void
    {
        $run->update(['status' => Run::FAILED, 'error' => $error, 'current_worker_job_id' => null]);
    }

    private function submit(Run $run, string $step, ?int $i): void
    {
        $spec = $run->task_spec;
        $evalPath = $this->dataset($run, 'eval')?->remote_path;

        switch ($step) {
            case 'generate_eval':
                $jobId = $this->worker->generate([
                    'run_id' => $run->slug, 'purpose' => 'eval', 'task_spec' => $spec,
                    'seed_examples' => $run->seed_examples, 'n' => $run->eval_n,
                ]);
                $stage = 'generate_eval';
                break;
            case 'evaluate_base':
                $jobId = $this->worker->evaluate([
                    'run_id' => $run->slug, 'eval_file' => $evalPath, 'model' => 'base',
                    'task_spec' => $spec, 'label' => 'base',
                ]);
                $stage = 'evaluate_base';
                break;
            case 'generate_train':
                $hardPath = $this->dataset($run, 'eval-hard')?->remote_path;
                $slices = $i > 1 ? ($this->decision($run, $i - 1, $this->targetSlice($run))?->next_slices ?: null) : null;
                $payload = [
                    'run_id' => $run->slug, 'purpose' => 'train', 'iteration' => $i, 'task_spec' => $spec,
                    'seed_examples' => [], 'n' => $slices ? (int) collect($slices)->sum('n') : $run->train_n,
                    'avoid_near' => array_values(array_filter([$evalPath, $hardPath])),
                ];
                if ($slices) {
                    $payload['target_slices'] = $slices;
                }
                if ($slices && $hardPath) {
                    // Targeted slices aim at the ambiguous cases the label check rejects; the judge may keep some.
                    $payload['judge_cap'] = config('chirak.judge_cap');
                }
                $jobId = $this->worker->generate($payload);
                $stage = "generate_train:$i";
                break;
            case 'train':
                // write-once and idempotent on the worker, so a retry or a hand-made copy of the same file is fine
                foreach ($run->datasets()->where('purpose', 'train-real')->get() as $real) {
                    if ($real->local_path && Storage::disk('runs')->exists($real->local_path)) {
                        $this->worker->putFile($run->slug, basename($real->remote_path), (string) Storage::disk('runs')->get($real->local_path));
                    }
                }
                $config = [
                    'run_id' => $run->slug, 'iteration' => $i,
                    // real labelled tickets (purpose train-real) go into every iteration next to the synthetic files
                    'train_files' => $run->datasets()->where('purpose', 'train')->where('iteration', '<=', $i)
                        ->orderBy('iteration')->pluck('remote_path')
                        ->merge($run->datasets()->where('purpose', 'train-real')->pluck('remote_path'))->all(),
                    'base_model' => $run->base_model, 'lora' => self::LORA, 'epochs' => 2, 'lr' => 0.0002, 'max_seq_len' => 1024,
                ];
                Iteration::updateOrCreate(
                    ['run_id' => $run->id, 'n' => $i],
                    ['train_config' => $config, 'started_at' => now(), 'finished_at' => null],
                );
                $jobId = $this->worker->train($config + ['task_spec' => $spec]);
                $stage = "train:$i";
                break;
            case 'evaluate':
                $jobId = $this->worker->evaluate([
                    'run_id' => $run->slug, 'eval_file' => $evalPath, 'task_spec' => $spec, 'label' => "iter-$i",
                    'model' => $run->iterations()->where('n', $i)->value('adapter_path'),
                ]);
                $stage = "evaluate:$i";
                break;
            case 'evaluate_hard':
                $label = $i === null ? 'base' : "iter-$i";
                $jobId = $this->worker->evaluate([
                    'run_id' => $run->slug, 'eval_file' => $this->dataset($run, 'eval-hard')->remote_path,
                    'task_spec' => $spec, 'label' => "$label.eval-hard",
                    'model' => $i === null ? 'base' : $run->iterations()->where('n', $i)->value('adapter_path'),
                ]);
                $stage = 'evaluate_hard:'.($i ?? 'base');
                break;
        }

        $run->update(['current_worker_job_id' => $jobId, 'stage' => $stage, 'status' => Run::RUNNING, 'error' => null]);
        PollWorkerJob::dispatch($run->id)->delay(now()->addSeconds(min(5, config('chirak.worker.poll_delay'))));
    }

    private function handleResult(Run $run, array $result): void
    {
        [$step, $i] = array_pad(explode(':', $run->stage), 2, null);
        $i = $i === null || $i === 'base' ? null : (int) $i;

        switch ($step) {
            case 'generate_eval':
            case 'generate_train':
                $this->storeDataset($run, $step === 'generate_eval' ? 'eval' : 'train', $i, $result);
                break;
            case 'evaluate_base':
            case 'evaluate':
                $this->storeEvaluation($run, $i, $result);
                break;
            case 'evaluate_hard':
                $this->storeEvaluation($run, $i, $result, 'hard');
                break;
            case 'train':
                $this->storeTraining($run, $i, $result);
                break;
        }
    }

    private function storeDataset(Run $run, string $purpose, ?int $iteration, array $result): void
    {
        $name = $purpose === 'eval' ? 'eval.jsonl' : "train-$iteration.jsonl";
        $this->worker->downloadArtifact($run->slug, $this->relative($run, $result['path']), "{$run->slug}/$name");

        $body = (string) Storage::disk('runs')->get("{$run->slug}/$name");
        if (hash('sha256', $body) !== $result['sha256']) {
            throw new WorkerRejected(409, "downloaded $name does not match the worker's sha256");
        }
        // The worker's `n` counts synthetic items only; the eval file also holds the seed tickets.
        $result['n_rows'] = substr_count($body, "\n");

        Dataset::updateOrCreate(
            ['run_id' => $run->id, 'purpose' => $purpose, 'iteration' => $iteration],
            [
                'remote_path' => $result['path'],
                'local_path' => "{$run->slug}/$name",
                'n' => $result['n_rows'],
                'sha256' => $result['sha256'],
                'frozen_at' => ($result['frozen'] ?? false) ? now() : null,
                'stats' => $result,
            ],
        );
    }

    private function storeEvaluation(Run $run, ?int $iteration, array $metrics, string $slice = 'clean'): void
    {
        $evalSet = $this->dataset($run, $slice === 'hard' ? 'eval-hard' : 'eval');
        $evalSha = $evalSet->sha256;
        if (($metrics['eval_sha256'] ?? null) !== $evalSha) {
            throw new WorkerRejected(409, 'eval set hash changed: stored '.$evalSha.', evaluation used '.($metrics['eval_sha256'] ?? 'none'));
        }

        $failures = $metrics['failures'] ?? [];
        unset($metrics['failures']);

        Evaluation::updateOrCreate(
            ['run_id' => $run->id, 'slice' => $slice, 'iteration' => $iteration],
            [
                'dataset_id' => $evalSet->id,
                'adapter_path' => $iteration === null ? null : $run->iterations()->where('n', $iteration)->value('adapter_path'),
                'metrics' => $metrics,
                'failures' => $failures,
                'eval_sha256' => $evalSha,
            ],
        );

        Storage::disk('runs')->put("{$run->slug}/evals/".($iteration === null ? 'base' : "iter-$iteration").($slice === 'hard' ? '.eval-hard' : '').'/metrics.json', json_encode($metrics + ['failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function storeTraining(Run $run, int $i, array $result): void
    {
        $local = "{$run->slug}/iter-$i/adapter.tar.gz";
        $this->worker->downloadArtifact($run->slug, $this->relative($run, $result['adapter_archive']), $local);

        $iteration = Iteration::where('run_id', $run->id)->where('n', $i)->firstOrFail();
        $iteration->update([
            'adapter_path' => $result['adapter_path'],
            'adapter_archive_local' => $local,
            'loss_curve' => $result['loss_curve'] ?? [],
            'train_config' => ($iteration->train_config ?? []) + ['result' => collect($result)->except(['loss_curve'])->all()],
            'finished_at' => now(),
        ]);
    }

    private function relative(Run $run, string $remotePath): string
    {
        $prefix = "/data/runs/{$run->slug}/";
        if (! str_starts_with($remotePath, $prefix)) {
            throw new WorkerRejected(422, "unexpected artifact path $remotePath");
        }

        return Str::after($remotePath, $prefix);
    }

    private function decide(Run $run, int $i): void
    {
        $slice = $this->targetSlice($run);
        $latest = $this->evaluation($run, $i, $slice);
        $previous = $i > 1 ? $this->evaluation($run, $i - 1, $slice) : null;
        $train = $this->dataset($run, 'train', $i);

        $verdict = StopRules::evaluate(
            $run->target_value,
            $i,
            (float) $run->metricOf($latest->metrics),
            $previous ? $run->metricOf($previous->metrics) : null,
            $train->leakageDropRate(),
        ) + ['slice' => $slice];

        $data = ['stop_rule' => $verdict, 'orchestrator_input' => null, 'orchestrator_output' => null, 'next_slices' => null, 'model' => null];

        if (! $verdict['stop']) {
            $run->update(['stage' => "analyze:$i"]);
            $earlier = $run->decisions()->where('slice', $slice)->where('iteration', '<', $i)->get()->map(fn ($d) => [
                'iteration' => $d->iteration,
                'reason' => $d->orchestrator_output['reason'] ?? '',
                'next_slices' => $d->next_slices,
            ])->all();

            $analysis = $this->analyst->analyze($run, $latest, $earlier);
            $data = array_merge($data, [
                'orchestrator_input' => $analysis['input'],
                'orchestrator_output' => $analysis['output'],
                'next_slices' => $analysis['next_slices'],
                'model' => $analysis['model'],
            ]);
        }

        Decision::updateOrCreate(['run_id' => $run->id, 'slice' => $slice, 'iteration' => $i], $data);
    }

    private function stopOnLeakage(Run $run, int $i): void
    {
        $reason = 'leakage drop rate above 30%';

        if ($run->evaluations()->whereNotNull('iteration')->exists()) {
            $this->finalize($run, $reason);

            return;
        }

        $run->update(['status' => Run::FAILED, 'stage' => 'done', 'stop_reason' => $reason, 'error' => "train-$i.jsonl dropped too many candidates for leakage"]);
    }

    private function finalize(Run $run, ?string $stopReason = null): void
    {
        $slice = $this->targetSlice($run);
        $best = ($slice === 'hard' ? $run->hardEvaluations() : $run->evaluations())->whereNotNull('iteration')->get()
            ->sortBy([fn ($a, $b) => $run->metricOf($b->metrics) <=> $run->metricOf($a->metrics), fn ($a, $b) => $a->iteration <=> $b->iteration])
            ->first();

        $name = null;
        if ($best) {
            $name = "{$run->slug}-iter-{$best->iteration}";
            $this->worker->serve($name, $best->adapter_path);
        }

        $run->update([
            'status' => Run::COMPLETED,
            'stage' => 'done',
            'served_name' => $name,
            'best_iteration' => $best?->iteration,
            'stop_reason' => $stopReason ?? $run->decisions()->where('slice', $slice)->reorder('iteration', 'desc')->first()?->stop_rule['reason'],
        ]);
    }

    private function dataset(Run $run, string $purpose, ?int $iteration = null): ?Dataset
    {
        return $run->datasets()->where('purpose', $purpose)->where('iteration', $iteration)->first();
    }

    private function evaluation(Run $run, ?int $iteration, string $slice = 'clean'): ?Evaluation
    {
        return ($slice === 'hard' ? $run->hardEvaluations() : $run->evaluations())->where('iteration', $iteration)->first();
    }

    private function decision(Run $run, int $iteration, string $slice): ?Decision
    {
        return $run->decisions()->where('slice', $slice)->where('iteration', $iteration)->first();
    }

    /** "hard" once the run has an eval-hard set: then it measures the target. */
    public function targetSlice(Run $run): string
    {
        return $this->dataset($run, 'eval-hard') ? 'hard' : 'clean';
    }
}
