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

    public function leakageDropRate(): float
    {
        return (float) ($this->stats['leakage_drop_rate'] ?? 0);
    }

    public function leakageDropped(): int
    {
        return (int) (($this->stats['dropped']['leakage_cosine'] ?? 0) + ($this->stats['dropped']['leakage_ngram'] ?? 0));
    }
}
