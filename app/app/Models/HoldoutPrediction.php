<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoldoutPrediction extends Model
{
    protected $fillable = ['run_id', 'iteration', 'model', 'position', 'input', 'expected', 'raw', 'parsed', 'valid', 'error', 'latency_ms'];

    protected function casts(): array
    {
        return ['expected' => 'array', 'parsed' => 'array', 'valid' => 'boolean'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
