<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dataset extends Model
{
    protected $fillable = [
        'run_id', 'purpose', 'iteration', 'remote_path', 'local_path', 'n', 'sha256', 'frozen_at', 'stats',
    ];

    protected function casts(): array
    {
        return ['stats' => 'array', 'frozen_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /** Rows in the file. Datasets stored before n_rows existed kept the worker's synthetic-only count in `n`. */
    public function rows(): int
    {
        if (isset($this->stats['n_rows'])) {
            return (int) $this->stats['n_rows'];
        }

        return $this->purpose === 'eval' ? $this->n + count($this->run->seed_examples ?? []) : $this->n;
    }

    public function syntheticRows(): int
    {
        return (int) ($this->stats['n'] ?? $this->n);
    }

    public function leakageDropRate(): float
    {
        return (float) ($this->stats['leakage_drop_rate'] ?? 0);
    }

    public function leakageDropped(): int
    {
        return (int) (($this->stats['dropped']['leakage_cosine'] ?? 0) + ($this->stats['dropped']['leakage_ngram'] ?? 0));
    }
}
