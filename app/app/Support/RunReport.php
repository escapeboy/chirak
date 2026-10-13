<?php

namespace App\Support;

use App\Models\Dataset;
use App\Models\Evaluation;
use App\Models\Run;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Everything the dashboard and the public report show, read from the database tables only.
 * A number that is not stored is returned as null and rendered as a dash, never estimated.
 */
class RunReport
{
    public Collection $evaluations;

    public Collection $iterations;

    public Collection $datasets;

    public Collection $decisions;

    public function __construct(public Run $run)
    {
        $this->evaluations = $run->evaluations()->get()->keyBy(fn ($e) => $e->iteration ?? 'base');
        $this->iterations = $run->iterations()->get();
        $this->datasets = $run->datasets()->get();
        $this->decisions = $run->decisions()->get();
    }

    public function baseline(): ?Evaluation
    {
        return $this->evaluations->get('base');
    }

    /** The adapter the run picked, or the newest evaluated one while the run is still going. */
    public function final(): ?Evaluation
    {
        if ($this->run->best_iteration) {
            return $this->evaluations->get($this->run->best_iteration);
        }

        return $this->evaluations->filter(fn ($e) => $e->iteration !== null)->sortByDesc('iteration')->first();
    }

    /** @return array<int, array{label: string, iteration: ?int, accuracy: ?float, macro_f1: ?float, json_valid_rate: ?float, exact_match: ?float, is_best: bool}> */
    public function resultRows(): array
    {
        $rows = [];
        foreach ($this->evaluations->sortBy(fn ($e) => $e->iteration ?? 0) as $e) {
            $m = $e->metrics;
            $rows[] = [
                'label' => $e->iteration === null ? 'Baseline (base model)' : 'Iteration '.$e->iteration,
                'iteration' => $e->iteration,
                'accuracy' => $m['accuracy'] ?? null,
                'macro_f1' => $m['macro_f1'] ?? null,
                'json_valid_rate' => $m['json_valid_rate'] ?? null,
                'exact_match' => $m['exact_match'] ?? null,
                'is_best' => $e->iteration !== null && $e->iteration === $this->run->best_iteration,
            ];
        }

        return $rows;
    }

    /** @return array<string, array{base: ?array, final: ?array, delta_f1: ?float}> */
    public function perClass(): array
    {
        $base = $this->baseline()?->metrics['per_class'] ?? [];
        $final = $this->final()?->metrics['per_class'] ?? [];
        $out = [];

        foreach (array_unique([...array_keys($base), ...array_keys($final)]) as $class) {
            $b = $base[$class] ?? null;
            $f = $final[$class] ?? null;
            $out[$class] = [
                'base' => $b,
                'final' => $f,
                'delta_f1' => ($b && $f) ? $f['f1'] - $b['f1'] : null,
            ];
        }

        return $out;
    }

    public function confusion(): ?array
    {
        return $this->final()?->metrics['confusion'] ?? $this->baseline()?->metrics['confusion'] ?? null;
    }

    public function confusionSource(): ?string
    {
        if ($this->final()?->metrics['confusion'] ?? null) {
            return 'Iteration '.$this->final()->iteration;
        }

        return $this->baseline()?->metrics['confusion'] ?? null ? 'Baseline' : null;
    }

    /** @return array<int, array{iteration: int, dropped: int, label_mismatch: ?int, candidates: ?int, rate: float, stopped: bool}> */
    public function leakage(): array
    {
        return $this->datasets->where('purpose', 'train')->sortBy('iteration')->map(fn (Dataset $d) => [
            'iteration' => $d->iteration,
            'dropped' => $d->leakageDropped(),
            'label_mismatch' => $d->stats['dropped']['label_mismatch'] ?? null,
            'candidates' => $d->stats['n_candidates'] ?? null,
            'rate' => $d->leakageDropRate(),
            'stopped' => \App\Services\Pipeline\StopRules::leakageExceeded($d->leakageDropRate()),
        ])->values()->all();
    }

    public function evalDataset(): ?Dataset
    {
        return $this->datasets->firstWhere('purpose', 'eval');
    }

    /** @return array<int, array<int, array{step: int, loss: float}>> */
    public function lossCurves(): array
    {
        return $this->iterations->filter(fn ($i) => $i->loss_curve)->mapWithKeys(fn ($i) => [$i->n => $i->loss_curve])->all();
    }

    public function baselineValue(): ?float
    {
        return $this->baseline() ? $this->run->metricOf($this->baseline()->metrics) : null;
    }

    public function finalValue(): ?float
    {
        return $this->final() ? $this->run->metricOf($this->final()->metrics) : null;
    }

    /**
     * Pipeline stages with their state, derived from what exists in the database.
     *
     * @return array<int, array{label: string, state: string, detail: ?string}>
     */
    public function stages(?array $progress = null): array
    {
        $run = $this->run;
        $maxIteration = max(1, (int) $this->datasets->where('purpose', 'train')->max('iteration'), (int) $this->iterations->max('n'));

        $defs = [
            ['Eval set generated and frozen', $this->evalDataset() !== null, 'generate_eval'],
            ['Baseline evaluation (base model)', $this->baseline() !== null, 'evaluate_base'],
        ];

        for ($i = 1; $i <= $maxIteration; $i++) {
            $defs[] = ["Training data, iteration $i", $this->datasets->where('purpose', 'train')->where('iteration', $i)->isNotEmpty(), "generate_train:$i"];
            if ($i === 1 && $run->require_review) {
                $defs[] = ['Human review of samples', $run->review_approved_at !== null, 'awaiting_review'];
            }
            $defs[] = ["LoRA training, iteration $i", $this->iterations->where('n', $i)->whereNotNull('finished_at')->isNotEmpty(), "train:$i"];
            $defs[] = ["Evaluation, iteration $i", $this->evaluations->has($i), "evaluate:$i"];
            $defs[] = ["Failure analysis and stop rules, iteration $i", $this->decisions->where('iteration', $i)->isNotEmpty(), "analyze:$i"];
        }

        if ($run->status === Run::NOT_WORTH_IT) {
            $defs = array_slice($defs, 0, 2);
        } else {
            $defs[] = ['Serve best adapter', $run->status === Run::COMPLETED, 'finalize'];
        }

        $stages = [];
        $activeFound = $run->isTerminal();
        foreach ($defs as [$label, $done, $key]) {
            if ($done) {
                $state = 'done';
            } elseif (! $activeFound) {
                $activeFound = true;
                $state = $run->status === Run::WAITING_FOR_WORKER ? 'waiting' : 'active';
            } else {
                $state = 'pending';
            }

            $detail = null;
            if ($state === 'active' && $progress && $key === $run->stage) {
                $detail = ($progress['stage'] ?? '').' '.($progress['done'] ?? '?').'/'.($progress['total'] ?? '?');
            }

            $stages[] = ['label' => $label, 'state' => $state, 'detail' => $detail];
        }

        return $stages;
    }

    /** Deterministic pseudo-random sample, so the preview does not reshuffle on every poll. */
    public static function sampleDataset(?Dataset $dataset, int $n = 10): array
    {
        if (! $dataset?->local_path || ! Storage::disk('runs')->exists($dataset->local_path)) {
            return [];
        }

        $items = collect(preg_split('/\r\n|\n|\r/', trim(Storage::disk('runs')->get($dataset->local_path))))
            ->filter()->map(fn ($l) => json_decode($l, true))->filter()->values();

        return $items->sortBy(fn ($item, $k) => crc32($dataset->sha256.$k))->take($n)->values()->all();
    }
}
