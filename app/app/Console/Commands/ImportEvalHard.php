<?php

namespace App\Console\Commands;

use App\Models\Dataset;
use App\Models\Evaluation;
use App\Models\Run;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Register a run's frozen eval-hard (worker/chirak_worker/hard_slice.py) and its evaluations from a local
 * copy of /data/runs/<slug>. Additive: an existing eval-hard dataset must have the same sha256, and
 * evaluations already stored are left as they are.
 */
class ImportEvalHard extends Command
{
    protected $signature = 'chirak:import-eval-hard {slug} {--dir= : local copy of /data/runs/<slug>}';

    protected $description = 'Register eval-hard and its hard-slice evaluations for a run';

    public function handle(): int
    {
        $run = Run::where('slug', $this->argument('slug'))->firstOrFail();
        $dir = rtrim((string) $this->option('dir'), '/');
        $body = file_get_contents("$dir/eval-hard.jsonl");
        $freeze = json_decode(file_get_contents("$dir/eval-hard/freeze.json"), true);
        $sha = hash('sha256', $body);
        if ($sha !== $freeze['sha256']) {
            $this->error('eval-hard.jsonl does not match freeze.json');

            return self::FAILURE;
        }

        $dataset = $run->datasets()->where('purpose', 'eval-hard')->first();
        if ($dataset && $dataset->sha256 !== $sha) {
            $this->error("run already has eval-hard {$dataset->sha256}; it is frozen");

            return self::FAILURE;
        }
        $stats = $freeze + ['n_rows' => substr_count($body, "\n")];
        foreach (['sample.stats.json' => 'sample', 'judge.stats.json' => 'judge'] as $file => $key) {
            $stats[$key] = json_decode(file_get_contents("$dir/eval-hard/$file"), true);
        }
        foreach (glob("$dir/eval-hard/leakage-*.json") as $file) {
            $stats['leakage'][basename($file, '.json')] = json_decode(file_get_contents($file), true);
        }
        foreach (['sample.jsonl', 'judged.jsonl', 'for-human-review.jsonl', 'reviewed.jsonl'] as $file) {
            if (is_file("$dir/eval-hard/$file")) {
                Storage::disk('runs')->put("{$run->slug}/eval-hard/$file", file_get_contents("$dir/eval-hard/$file"));
            }
        }
        Storage::disk('runs')->put("{$run->slug}/eval-hard.jsonl", $body);
        $dataset ??= new Dataset(['run_id' => $run->id, 'purpose' => 'eval-hard', 'iteration' => null]);
        $dataset->fill([
            'remote_path' => "/data/runs/{$run->slug}/eval-hard.jsonl", 'local_path' => "{$run->slug}/eval-hard.jsonl",
            'n' => $stats['n_rows'], 'sha256' => $sha, 'frozen_at' => Carbon::parse($freeze['frozen_at']), 'stats' => $stats,
        ])->save();
        $this->info("eval-hard: {$stats['n_rows']} rows, sha256 $sha");

        foreach (glob("$dir/evals/*.eval-hard/metrics.json") as $file) {
            $label = basename(dirname($file), '.eval-hard');
            $iteration = $label === 'base' ? null : (int) str_replace('iter-', '', $label);
            if ($run->hardEvaluations()->where('iteration', $iteration)->exists()) {
                $this->line("$label: already stored, left as is");

                continue;
            }
            $metrics = json_decode(file_get_contents($file), true);
            if ($metrics['eval_sha256'] !== $sha) {
                $this->error("$label: evaluated a different file ({$metrics['eval_sha256']})");

                return self::FAILURE;
            }
            $failures = $metrics['failures'] ?? [];
            unset($metrics['failures']);
            Evaluation::create([
                'run_id' => $run->id, 'dataset_id' => $dataset->id, 'slice' => 'hard', 'iteration' => $iteration,
                'adapter_path' => $iteration === null ? null : $run->iterations()->where('n', $iteration)->value('adapter_path'),
                'metrics' => $metrics, 'failures' => $failures, 'eval_sha256' => $sha,
            ]);
            Storage::disk('runs')->put("{$run->slug}/evals/$label.eval-hard/metrics.json", file_get_contents($file));
            $this->info(sprintf('%s: macro-F1 %.4f on eval-hard', $label, $metrics['macro_f1']));
        }

        return self::SUCCESS;
    }
}
