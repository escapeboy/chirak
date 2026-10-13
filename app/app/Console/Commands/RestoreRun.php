<?php

namespace App\Console\Commands;

use App\Models\Dataset;
use App\Models\Decision;
use App\Models\Evaluation;
use App\Models\Iteration;
use App\Models\Run;
use App\Services\Pipeline\StopRules;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Rebuild one completed run's rows from the worker's job records and the run's artifacts.
 * Written after the local database was wiped on 2026-10-13. It writes exactly what RunPipeline stored
 * (same worker results, same stop-rule code), and marks every rebuilt row with `restored`.
 * Supports runs that finished after iteration 1 without a Claude analysis (nothing else can be rebuilt:
 * Claude's answers lived only in the database).
 */
class RestoreRun extends Command
{
    protected $signature = 'chirak:restore-run {slug} {--jobs=* : worker _jobs directories} {--run-dir= : copy of /data/runs/<slug>}
        {--name=} {--target=} {--eval-n=200} {--train-n=1000} {--holdout= : JSONL of seed tickets with "holdout": true}';

    protected $description = 'Rebuild a run from worker job records and artifacts (after DB loss)';

    private const LORA = ['r' => 16, 'alpha' => 16, 'dropout' => 0.05,
        'target_modules' => ['q_proj', 'k_proj', 'v_proj', 'o_proj', 'gate_proj', 'up_proj', 'down_proj']];

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $runDir = rtrim((string) $this->option('run-dir'), '/');
        if (Run::where('slug', $slug)->exists()) {
            $this->error("run $slug already exists");

            return self::FAILURE;
        }

        $jobs = collect($this->option('jobs'))->flatMap(fn ($d) => glob(rtrim($d, '/').'/job_*.json'))
            ->map(fn ($f) => json_decode(file_get_contents($f), true))
            ->filter(fn ($j) => ($j['run_id'] ?? null) === $slug && $j['status'] === 'succeeded')
            ->sortBy('created_at')->values();
        // The pipeline's jobs are the first of each kind; later evaluate jobs are manual re-scores.
        $first = fn (string $type, ?callable $where = null) => $jobs->first(fn ($j) => $j['type'] === $type && (! $where || $where($j)));
        $genEval = $first('generate', fn ($j) => str_ends_with($j['result']['path'], '/eval.jsonl'));
        $genTrain = $first('generate', fn ($j) => str_ends_with($j['result']['path'], '/train-1.jsonl'));
        $train = $first('train');
        $evBase = $first('evaluate', fn ($j) => $j['result']['label'] === 'base');
        $evIter = $first('evaluate', fn ($j) => $j['result']['label'] === 'iter-1');
        if (! $genEval || ! $genTrain || ! $train || ! $evBase || ! $evIter) {
            $this->error('missing job records for eval/train-1/train/base/iter-1');

            return self::FAILURE;
        }

        $evalRows = $this->jsonl("$runDir/eval.jsonl");
        $seeds = collect($evalRows)->where('source', 'seed')->map(fn ($r) => ['input' => $r['input'], 'output' => $r['output']])->values()->all();
        $holdout = collect($this->jsonl((string) $this->option('holdout')))->where('holdout', true)
            ->map(fn ($r) => ['input' => $r['input'], 'output' => $r['output']])->values()->all();
        $restored = ['at' => now()->toIso8601String(), 'from_jobs' => [$genEval['id'], $genTrain['id'], $train['id'], $evBase['id'], $evIter['id']]];
        $at = fn (array $j, string $k) => Carbon::parse($j[$k]);

        DB::transaction(function () use ($slug, $runDir, $genEval, $genTrain, $train, $evBase, $evIter, $seeds, $holdout, $restored, $at) {
            $run = Run::create([
                'slug' => $slug, 'name' => $this->option('name'),
                'task_spec' => json_decode(file_get_contents("$runDir/task_spec.json"), true),
                'seed_examples' => $seeds, 'holdout_examples' => $holdout,
                'target_metric' => 'macro_f1', 'target_value' => (float) $this->option('target'),
                'eval_n' => (int) $this->option('eval-n'), 'train_n' => (int) $this->option('train-n'),
                'require_review' => true, 'review_approved_at' => $at($train, 'created_at'),
                'status' => Run::COMPLETED, 'stage' => 'done',
            ]);
            $run->forceFill(['created_at' => $at($genEval, 'created_at')])->save();

            foreach ([['eval', null, $genEval], ['train', 1, $genTrain]] as [$purpose, $i, $job]) {
                $name = $purpose === 'eval' ? 'eval.jsonl' : "train-$i.jsonl";
                $body = file_get_contents("$runDir/$name");
                if (hash('sha256', $body) !== $job['result']['sha256']) {
                    throw new \RuntimeException("$name does not match the worker's sha256");
                }
                Storage::disk('runs')->put("$slug/$name", $body);
                Dataset::create([
                    'run_id' => $run->id, 'purpose' => $purpose, 'iteration' => $i,
                    'remote_path' => $job['result']['path'], 'local_path' => "$slug/$name",
                    'n' => substr_count($body, "\n"), 'sha256' => $job['result']['sha256'],
                    'frozen_at' => ($job['result']['frozen'] ?? false) ? $at($job, 'finished_at') : null,
                    'stats' => $job['result'] + ['n_rows' => substr_count($body, "\n"), 'restored' => $restored],
                ]);
            }

            $result = $train['result'];
            Storage::disk('runs')->put("$slug/iter-1/adapter.tar.gz", file_get_contents("$runDir/iter-1/adapter.tar.gz"));
            $iteration = Iteration::create([
                'run_id' => $run->id, 'n' => 1, 'adapter_path' => $result['adapter_path'],
                'adapter_archive_local' => "$slug/iter-1/adapter.tar.gz",
                'train_config' => [
                    'run_id' => $slug, 'iteration' => 1, 'train_files' => [$genTrain['result']['path']],
                    'base_model' => null, 'lora' => self::LORA, 'epochs' => 2, 'lr' => 0.0002, 'max_seq_len' => 1024,
                    'result' => collect($result)->except(['loss_curve'])->all(), 'restored' => $restored,
                ],
                'loss_curve' => $result['loss_curve'] ?? [],
                'started_at' => $at($train, 'started_at'), 'finished_at' => $at($train, 'finished_at'),
            ]);

            foreach ([[null, $evBase, null], [1, $evIter, $iteration->adapter_path]] as [$i, $job, $adapter]) {
                $metrics = $job['result'];
                if ($metrics['eval_sha256'] !== $genEval['result']['sha256']) {
                    throw new \RuntimeException('evaluation did not use the frozen eval');
                }
                $failures = $metrics['failures'] ?? [];
                unset($metrics['failures']);
                Evaluation::create(['run_id' => $run->id, 'iteration' => $i, 'adapter_path' => $adapter,
                    'metrics' => $metrics, 'failures' => $failures, 'eval_sha256' => $metrics['eval_sha256']]);
            }

            $trainSet = $run->datasets()->where('purpose', 'train')->first();
            $verdict = StopRules::evaluate((float) $run->target_value, 1, (float) $run->metricOf($evIter['result']), null, $trainSet->leakageDropRate());
            Decision::create(['run_id' => $run->id, 'iteration' => 1, 'stop_rule' => $verdict + ['restored' => $restored]]);

            $run->update([
                'stop_reason' => $verdict['reason'],
                'served_name' => $verdict['stop'] ? "$slug-iter-1" : null,
                'best_iteration' => 1,
            ]);
        });

        $this->info("restored $slug");

        return self::SUCCESS;
    }

    private function jsonl(string $path): array
    {
        return collect(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->map(fn ($l) => json_decode($l, true))->all();
    }
}
