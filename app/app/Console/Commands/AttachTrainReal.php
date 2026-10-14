<?php

namespace App\Console\Commands;

use App\Models\Dataset;
use App\Models\Run;
use App\Support\ExamplesParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Attach labelled real tickets (JSONL {input, output, ...}) to a run as training data for every
 * iteration (purpose train-real). Only before the first training step, so all iterations see the
 * same data. The file must also be copied to the worker at /data/runs/<slug>/train-real.jsonl.
 */
class AttachTrainReal extends Command
{
    protected $signature = 'chirak:attach-train-real {slug} {file} {--source= : where the tickets come from, shown in the report}';

    protected $description = 'Attach labelled real tickets as training data of a run';

    public function handle(): int
    {
        $run = Run::where('slug', $this->argument('slug'))->firstOrFail();
        if ($run->iterations()->exists()) {
            $this->error('training has started; real tickets must be attached before iteration 1');

            return self::FAILURE;
        }
        if ($run->datasets()->where('purpose', 'train-real')->exists()) {
            $this->error('run already has train-real');

            return self::FAILURE;
        }

        $body = file_get_contents($this->argument('file'));
        $rows = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", $body))));
        $parsed = ExamplesParser::parse(collect($rows)->map(fn ($r) => json_encode(['input' => $r['input'], 'output' => $r['output']], JSON_UNESCAPED_UNICODE))->implode("\n"), $run->task_spec);
        if ($parsed['errors']) {
            $this->error('invalid rows: '.json_encode(array_slice($parsed['errors'], 0, 3)));

            return self::FAILURE;
        }

        Storage::disk('runs')->put("{$run->slug}/train-real.jsonl", $body);
        $categories = collect($rows)->countBy(fn ($r) => $r['output'][collect($run->task_spec['fields'])->firstWhere('primary', true)['name']]);
        Dataset::create([
            'run_id' => $run->id, 'purpose' => 'train-real', 'iteration' => null,
            'remote_path' => "/data/runs/{$run->slug}/train-real.jsonl", 'local_path' => "{$run->slug}/train-real.jsonl",
            'n' => count($rows), 'sha256' => hash('sha256', $body), 'frozen_at' => now(),
            'stats' => ['source' => $this->option('source'), 'by_category' => $categories->sortKeys()->all()],
        ]);
        $this->info(sprintf('train-real: %d rows, sha256 %s', count($rows), hash('sha256', $body)));

        return self::SUCCESS;
    }
}
