<?php

namespace App\Console\Commands;

use App\Models\HoldoutPrediction;
use App\Models\Run;
use App\Services\Worker\WorkerClient;
use Illuminate\Console\Command;

/**
 * Send the run's held-out tickets (never in any dataset file) to the base model and to the served adapter
 * through /predict, the playground's path, and store the answers. Answers already stored are kept.
 */
class PredictHoldout extends Command
{
    protected $signature = 'chirak:holdout {slug}';

    protected $description = 'Store base and adapter answers to the held-out tickets';

    public function handle(WorkerClient $worker): int
    {
        $run = Run::where('slug', $this->argument('slug'))->firstOrFail();
        if (! $run->served_name || ! $run->best_iteration) {
            $this->error('the run has no served adapter');

            return self::FAILURE;
        }

        foreach ([[null, 'base'], [$run->best_iteration, $run->served_name]] as [$iteration, $model]) {
            foreach ($run->holdout_examples ?? [] as $k => $example) {
                if ($run->holdoutPredictions()->where('iteration', $iteration)->where('position', $k + 1)->exists()) {
                    continue;
                }
                $answer = $worker->predict($model, $run->task_spec, $example['input']);
                HoldoutPrediction::create([
                    'run_id' => $run->id, 'iteration' => $iteration, 'model' => $model, 'position' => $k + 1,
                    'input' => $example['input'], 'expected' => $example['output'],
                    'raw' => $answer['raw'] ?? null, 'parsed' => $answer['parsed'] ?? null, 'valid' => (bool) ($answer['valid'] ?? false),
                    'error' => $answer['error'] ?? null, 'latency_ms' => $answer['latency_ms'] ?? null,
                ]);
                $this->line(sprintf('%s #%d: %s', $model, $k + 1, json_encode($answer['parsed'] ?? $answer['raw'] ?? null, JSON_UNESCAPED_UNICODE)));
            }
        }

        return self::SUCCESS;
    }
}
