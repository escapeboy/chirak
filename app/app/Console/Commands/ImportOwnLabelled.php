<?php

namespace App\Console\Commands;

use App\Models\OwnItem;
use App\Models\Run;
use App\Services\Own\OwnTickets;
use App\Support\ExamplesParser;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Upload tickets that are labelled already (JSONL {input, output, split: eval|train}) as a run's own tickets,
 * e.g. a public dataset labelled by people. They skip the judge and the review and are frozen at once: the eval
 * part becomes the run's own slice, the train part its train-own file for a follow-up run.
 */
class ImportOwnLabelled extends Command
{
    protected $signature = 'chirak:import-own-labelled {slug} {file} {--origin= : who labelled the tickets, shown on the report}';

    protected $description = 'Upload labelled tickets as a run\'s own tickets and freeze them';

    public function handle(OwnTickets $tickets): int
    {
        $run = Run::where('slug', $this->argument('slug'))->firstOrFail();
        if (! $this->option('origin')) {
            $this->error('--origin is required: the report says who labelled the tickets');

            return self::FAILURE;
        }
        $rows = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", file_get_contents($this->argument('file'))))));
        $bad = array_keys(array_filter($rows, fn ($r) => ! in_array($r['split'] ?? null, [OwnItem::EVAL, OwnItem::TRAIN], true)));
        $parsed = ExamplesParser::parse(collect($rows)->map(fn ($r) => json_encode(['input' => $r['input'] ?? null, 'output' => $r['output'] ?? null], JSON_UNESCAPED_UNICODE))->implode("\n"), $run->task_spec);
        if ($bad || $parsed['errors'] || count($parsed['items']) !== count($rows)) {
            $this->error('invalid rows: '.json_encode(array_slice(array_merge(array_map(fn ($i) => 'line '.($i + 1).': split must be eval or train', $bad), $parsed['errors']), 0, 3)));

            return self::FAILURE;
        }

        try {
            $dataset = $tickets->importLabelled($run, $rows, $this->option('origin'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $train = $run->datasets()->where('purpose', 'train-own')->first();
        $this->info(sprintf('eval-own: %d rows, sha256 %s; train-own: %d rows (%d left out for overlap)',
            $dataset->n, $dataset->sha256, $train?->n ?? 0, $dataset->stats['train']['overlap_dropped'] ?? 0));

        return self::SUCCESS;
    }
}
