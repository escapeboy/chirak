<?php

namespace App\Services\Own;

use App\Jobs\DeleteOwnWorkerCopy;
use App\Jobs\EvaluateOwnSet;
use App\Jobs\LabelOwnItem;
use App\Models\Dataset;
use App\Models\OwnItem;
use App\Models\OwnSet;
use App\Models\Run;
use App\Support\PiiMasker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/** The lifecycle of a run's own tickets: upload (masked), label, review, freeze, delete. */
class OwnTickets
{
    public const MAX_PUBLIC = 5;

    /** @param string[] $texts raw ticket texts; only their masked form is stored */
    public function upload(Run $run, array $texts): OwnSet
    {
        if ($run->ownSet()->exists()) {
            throw new InvalidArgumentException('This run already has uploaded tickets; delete them first.');
        }

        $set = DB::transaction(function () use ($run, $texts) {
            $set = OwnSet::create(['run_id' => $run->id, 'state' => OwnSet::LABELLING]);
            $now = now();
            foreach (array_chunk($texts, 100, true) as $chunk) {
                OwnItem::insert(array_map(fn ($text, $k) => [
                    'run_id' => $run->id, 'position' => $k + 1, 'text' => PiiMasker::mask($text),
                    'status' => OwnItem::NEW, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk, array_keys($chunk)));
            }

            return $set;
        });
        $this->label($run);

        return $set;
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
            && $run->ownItems()->where('status', OwnItem::LABELLED)->exists();
    }

    /** Writes the canonical eval file, registers it as the run's eval-own dataset and queues its evaluation. */
    public function freeze(Run $run): Dataset
    {
        $dataset = DB::transaction(function () use ($run) {
            $set = OwnSet::where('run_id', $run->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->freezable($run), 422, 'Resolve every unstable ticket before freezing.');

            $items = $run->ownItems()->where('status', OwnItem::LABELLED)->get();
            $body = $items->map(fn (OwnItem $i) => json_encode([
                'id' => sprintf('own-%04d', $i->position), 'input' => $i->text, 'output' => $i->label,
                'source' => 'own', 'label_source' => $i->label_source,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n")->implode('');
            $sha = hash('sha256', $body);

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
                ],
            ]);
            $set->update(['state' => OwnSet::FROZEN, 'frozen_at' => $dataset->frozen_at, 'eval_state' => 'pending', 'error' => null]);

            return $dataset;
        });
        EvaluateOwnSet::dispatch($run->id);

        return $dataset;
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
            $set->update(['items_deleted_at' => now()]);
            DeleteOwnWorkerCopy::dispatch($run->id);
        } else {
            $set->delete();
        }
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
