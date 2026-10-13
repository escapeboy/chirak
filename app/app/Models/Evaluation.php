<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    protected $fillable = ['run_id', 'iteration', 'adapter_path', 'metrics', 'failures', 'eval_sha256'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'failures' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
