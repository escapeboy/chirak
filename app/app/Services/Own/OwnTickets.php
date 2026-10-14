<?php

namespace App\Services\Own;

use App\Jobs\DeleteOwnWorkerCopy;
use App\Jobs\EvaluateOwnSet;
use App\Jobs\LabelOwnItem;
use App\Models\Dataset;
use App\Models\OwnItem;
use App\Models\OwnSet;
use App\Models\Run;
use App\Services\Pipeline\RunPipeline;
use App\Support\PiiMasker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** The lifecycle of a run's own tickets: upload (masked), label, review, freeze, delete. */
class OwnTickets
{
    public const MAX_PUBLIC = 5;

    /**
     * @param string[] $texts raw ticket texts; only their masked form is stored
     * @param int $trainShare percent of the tickets set aside for training before any labelling, one of OwnSet::SHARES
     */
    public function upload(Run $run, array $texts, int $trainShare = 0): OwnSet
    {
        if ($run->ownSet()->exists()) {
            throw new InvalidArgumentException('This run already has uploaded tickets; delete them first.');
        }
        if (! in_array($trainShare, OwnSet::SHARES, true)) {
            throw new InvalidArgumentException('The training share must be one of '.implode(', ', OwnSet::SHARES).' percent.');
        }
        $train = array_flip($this->trainPositions($run->slug, count($texts), $trainShare));

        $set = DB::transaction(function () use ($run, $texts, $trainShare, $train) {
            $set = OwnSet::create(['run_id' => $run->id, 'state' => OwnSet::LABELLING, 'train_share' => $trainShare]);
            $now = now();
            foreach (array_chunk($texts, 100, true) as $chunk) {
                OwnItem::insert(array_map(fn ($text, $k) => [
                    'run_id' => $run->id, 'position' => $k + 1, 'text' => PiiMasker::mask($text),
                    'split' => isset($train[$k + 1]) ? OwnItem::TRAIN : OwnItem::EVAL,
                    'status' => OwnItem::NEW, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk, array_keys($chunk)));
            }

            return $set;
        });
        $this->label($run);

        return $set;
    }

    /** The same slug and count always give the same positions, so the split can be reproduced. @return int[] */
    private function trainPositions(string $slug, int $n, int $share): array
    {
        $positions = range(1, $n);
        usort($positions, fn ($a, $b) => strcmp(md5("$slug|$a"), md5("$slug|$b")));

        return array_slice($positions, 0, (int) round($n * $share / 100));
    }

    /** Queues the judge for every ticket without a result; safe to call again after a failure. */
    public function label(Run $run): void
    {
        $run->ownItems()->where('status', OwnItem::NEW)->pluck('id')->each(fn ($id) => LabelOwnItem::dispatch($id));
    }

    /** The judge's label confirmed by a person. */
    // $by: who decided. The review screen is used by a person; a delegated agent records itself instead.
    public function accept(OwnItem $item, string $by = 'human'): void
    {
        $this->assertOpen($item);
        abort_unless($item->status === OwnItem::LABELLED, 422, 'Only a labelled item can be accepted.');
        $item->update(['label_source' => $by, 'decided_at' => now()]);
    }

    /** @param array<string, mixed> $values one value per field of the task spec */
    public function change(OwnItem $item, array $values, string $by = 'human'): void
    {
        $this->assertOpen($item);
        $label = [];
        foreach ($item->run->task_spec['fields'] as $field) {
            $value = $values[$field['name']] ?? null;
            if ($field['type'] === 'bool') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $valid = $value !== null;
            } else {
                $valid = is_string($value) && in_array($value, $field['values'], true);
            }
            if (! $valid) {
                throw new InvalidArgumentException('Pick a value for '.$field['name'].'.');
            }
            $label[$field['name']] = $value;
        }
        $item->update(['status' => OwnItem::LABELLED, 'label' => $label, 'label_source' => $by, 'decided_at' => now()]);
    }

    public function drop(OwnItem $item, string $by = 'human'): void
    {
        $this->assertOpen($item);
        $item->update(['status' => OwnItem::DROPPED, 'label' => null, 'label_source' => $by, 'decided_at' => now()]);
    }

    /** Whether the set can be frozen: judged in full, nothing unstable, at least one ticket kept. */
    public function freezable(Run $run): bool
    {
        $set = $run->ownSet()->first();

        return $set && ! $set->isFrozen()
            && ! $run->ownItems()->whereIn('status', [OwnItem::NEW, OwnItem::UNSTABLE])->exists()
            && $run->ownItems()->where('status', OwnItem::LABELLED)->where('split', OwnItem::EVAL)->exists();
    }

    /** Writes the canonical eval file, registers it as the run's eval-own dataset and queues its evaluation. */
    public function freeze(Run $run): Dataset
    {
        $dataset = DB::transaction(function () use ($run) {
            $set = OwnSet::where('run_id', $run->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->freezable($run), 422, 'Resolve every unstable ticket before freezing.');

            $labelled = $run->ownItems()->where('status', OwnItem::LABELLED)->get();
            $items = $labelled->where('split', OwnItem::EVAL)->values();
            $body = $this->jsonl($items);
            $sha = hash('sha256', $body);
            $train = $labelled->where('split', OwnItem::TRAIN)->values();
            $kept = $this->withoutOverlap($train, $items);

            Storage::disk('runs')->put("{$run->slug}/eval-own.jsonl", $body);
            $dataset = Dataset::create([
                'run_id' => $run->id, 'purpose' => 'eval-own', 'iteration' => null,
                'remote_path' => "/data/runs/{$run->slug}/eval-own.jsonl", 'local_path' => "{$run->slug}/eval-own.jsonl",
                'n' => $items->count(), 'sha256' => $sha, 'frozen_at' => now(),
                'stats' => [
                    'n_rows' => $items->count(),
                    'label_sources' => $items->countBy('label_source')->all() + ['judge' => 0, 'human' => 0],
                    'dropped' => $run->ownItems()->where('status', OwnItem::DROPPED)->count(),
                    'judge_usage' => $set->only(['requests', 'input_tokens', 'output_tokens']),
                ] + ($set->train_share > 0 ? ['train' => [
                    'share' => $set->train_share, 'n' => $kept->count(), 'overlap_dropped' => $train->count() - $kept->count(),
                ]] : []),
            ]);
            if ($kept->isNotEmpty()) {
                $this->storeTrainFile($run, $kept, $train->count() - $kept->count());
            }
            $set->update(['state' => OwnSet::FROZEN, 'frozen_at' => $dataset->frozen_at, 'eval_state' => 'pending', 'error' => null]);

            return $dataset;
        });
        EvaluateOwnSet::dispatch($run->id);

        return $dataset;
    }

    private function jsonl(Collection $items): string
    {
        return $items->map(fn (OwnItem $i) => json_encode([
            'id' => sprintf('own-%04d', $i->position), 'input' => $i->text, 'output' => $i->label,
            'source' => 'own', 'label_source' => $i->label_source,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n")->implode('');
    }

    private function storeTrainFile(Run $run, Collection $kept, int $overlapDropped): void
    {
        $body = $this->jsonl($kept);
        $primary = $run->primaryField()['name'];
        Storage::disk('runs')->put("{$run->slug}/train-own.jsonl", $body);
        Dataset::create([
            'run_id' => $run->id, 'purpose' => 'train-own', 'iteration' => null,
            'remote_path' => "/data/runs/{$run->slug}/train-own.jsonl", 'local_path' => "{$run->slug}/train-own.jsonl",
            'n' => $kept->count(), 'sha256' => hash('sha256', $body), 'frozen_at' => now(),
            'stats' => [
                'n_rows' => $kept->count(),
                'label_sources' => $kept->countBy('label_source')->all(),
                'by_category' => $kept->countBy(fn (OwnItem $i) => is_bool($v = $i->label[$primary]) ? ($v ? 'true' : 'false') : $v)->sortKeys()->all(),
                'overlap_dropped' => $overlapDropped,
            ],
        ]);
    }

    /** Training tickets that repeat an evaluation ticket or share a word 8-gram with one are left out. */
    private function withoutOverlap(Collection $train, Collection $eval): Collection
    {
        $texts = [];
        $grams = [];
        foreach ($eval as $item) {
            $norm = $this->normalize($item->text);
            $texts[$norm] = true;
            foreach ($this->ngrams($norm) as $gram) {
                $grams[$gram] = true;
            }
        }

        return $train->reject(function (OwnItem $item) use ($texts, $grams) {
            $norm = $this->normalize($item->text);

            return isset($texts[$norm]) || array_filter($this->ngrams($norm), fn ($g) => isset($grams[$g])) !== [];
        })->values();
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower($text))));
    }

    /** @return string[] */
    private function ngrams(string $normalized, int $n = 8): array
    {
        $words = $normalized === '' ? [] : explode(' ', $normalized);

        return count($words) < $n ? [] : array_map(fn ($i) => implode(' ', array_slice($words, $i, $n)), range(0, count($words) - $n));
    }

    /** Queues the evaluation again, e.g. after a failure or once the run has a served adapter. */
    public function evaluate(Run $run): void
    {
        $set = $run->ownSet()->first();
        abort_unless($set?->isFrozen() && ! $set->items_deleted_at && $set->eval_state !== 'running', 422, 'Nothing to evaluate.');

        $set->update(['eval_state' => 'pending', 'error' => null, 'worker_job_id' => null]);
        EvaluateOwnSet::dispatch($run->id);
    }

    /** Removes the uploaded tickets and the eval file copy. Scores and counts stay. */
    public function deleteItems(Run $run): void
    {
        $set = $run->ownSet()->first();
        abort_unless($set, 404);
        abort_if(in_array($set->eval_state, ['pending', 'running'], true), 422, 'Wait for the evaluation to finish first.');

        $run->ownItems()->delete();
        if ($set->isFrozen()) {
            $dataset = $run->datasets()->where('purpose', 'eval-own')->first();
            if ($dataset?->local_path) {
                Storage::disk('runs')->delete($dataset->local_path);
                $dataset->update(['local_path' => null]);
            }
            $train = $run->datasets()->where('purpose', 'train-own')->first();
            if ($train?->local_path) {
                Storage::disk('runs')->delete($train->local_path);
                $train->update(['local_path' => null]);
            }
            $set->update(['items_deleted_at' => now()]);
            DeleteOwnWorkerCopy::dispatch($run->id);
        } else {
            $set->delete();
        }
    }

    /** Whether the frozen set has training tickets that can still start a follow-up run. */
    public function canTrainNewRun(Run $run): bool
    {
        $set = $run->ownSet()->first();
        $train = $run->datasets()->where('purpose', 'train-own')->first();

        return $set?->isFrozen() && ! $set->items_deleted_at && $train?->local_path && ! $run->followUp()->exists();
    }

    /**
     * Starts a new run with the source run's task, seeds, hold-out and target, the training tickets as its
     * train-real data and a copy of the frozen evaluation tickets as its own slice. One follow-up per source run.
     */
    public function trainNewRun(Run $source): Run
    {
        $run = DB::transaction(function () use ($source) {
            OwnSet::where('run_id', $source->id)->lockForUpdate()->first();
            abort_unless($this->canTrainNewRun($source), 422, 'There are no training tickets to start a run with, or a follow-up run was already started.');
            $set = $source->ownSet()->first();
            $train = $source->datasets()->where('purpose', 'train-own')->first();
            $eval = $source->datasets()->where('purpose', 'eval-own')->first();
            $disk = Storage::disk('runs');
            abort_unless($eval?->local_path && $disk->exists($train->local_path) && $disk->exists($eval->local_path), 422, 'The ticket files are gone.');

            $run = Run::create([
                'slug' => (Str::slug(Str::limit($source->name.' own tickets', 40, ''), '-') ?: 'run').'-'.Str::lower(Str::random(6)),
                'name' => $source->name.' + own tickets',
                'task_spec' => $source->task_spec, 'seed_examples' => $source->seed_examples, 'holdout_examples' => $source->holdout_examples,
                'base_model' => $source->base_model, 'target_metric' => $source->target_metric, 'target_value' => $source->target_value,
                'eval_n' => $source->eval_n, 'train_n' => $source->train_n, 'require_review' => $source->require_review,
                'status' => Run::QUEUED, 'trained_from_run_id' => $source->id,
            ]);

            $disk->put("{$run->slug}/train-real.jsonl", $disk->get($train->local_path));
            Dataset::create([
                'run_id' => $run->id, 'purpose' => 'train-real', 'iteration' => null,
                'remote_path' => "/data/runs/{$run->slug}/train-real.jsonl", 'local_path' => "{$run->slug}/train-real.jsonl",
                'n' => $train->n, 'sha256' => $train->sha256, 'frozen_at' => now(),
                'stats' => ['source' => "own tickets of run {$source->slug}", 'by_category' => $train->stats['by_category'] ?? []],
            ]);

            $disk->put("{$run->slug}/eval-own.jsonl", $disk->get($eval->local_path));
            Dataset::create([
                'run_id' => $run->id, 'purpose' => 'eval-own', 'iteration' => null,
                'remote_path' => "/data/runs/{$run->slug}/eval-own.jsonl", 'local_path' => "{$run->slug}/eval-own.jsonl",
                'n' => $eval->n, 'sha256' => $eval->sha256, 'frozen_at' => $eval->frozen_at,
                'stats' => array_diff_key($eval->stats, ['train' => 1]),
            ]);
            OwnSet::create(['run_id' => $run->id, 'state' => OwnSet::FROZEN, 'frozen_at' => $set->frozen_at]);

            $now = now();
            foreach ($source->ownItems()->where('split', OwnItem::EVAL)->get()->chunk(100) as $chunk) {
                OwnItem::insert($chunk->map(fn (OwnItem $i) => [
                    'run_id' => $run->id, 'position' => $i->position, 'split' => OwnItem::EVAL, 'text' => $i->text, 'status' => $i->status,
                    'passes' => $i->getRawOriginal('passes'), 'label' => $i->getRawOriginal('label'), 'label_source' => $i->label_source,
                    'decided_at' => $i->getRawOriginal('decided_at'), 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            }

            return $run;
        });
        app(RunPipeline::class)->start($run);

        return $run;
    }

    /** Approves or withdraws a labelled ticket as an example on the public report; at most MAX_PUBLIC per run. */
    public function setPublic(OwnItem $item, bool $public): void
    {
        DB::transaction(function () use ($item, $public) {
            $set = OwnSet::where('run_id', $item->run_id)->lockForUpdate()->first();
            abort_unless($set?->isFrozen() && ! $set->items_deleted_at, 422, 'Examples can be chosen after the freeze, while the tickets exist.');
            abort_unless($item->status === OwnItem::LABELLED, 422, 'Only a labelled ticket can be shown.');

            if ($public && ! $item->public && OwnItem::where('run_id', $item->run_id)->where('public', true)->count() >= self::MAX_PUBLIC) {
                throw new InvalidArgumentException('At most '.self::MAX_PUBLIC.' examples can be shown on the public report. Hide one first.');
            }
            $item->update(['public' => $public]);
        });
    }

    private function assertOpen(OwnItem $item): void
    {
        $set = OwnSet::where('run_id', $item->run_id)->first();
        abort_unless($set && $set->state === OwnSet::REVIEW, 422, 'The set is not open for review.');
    }
}
