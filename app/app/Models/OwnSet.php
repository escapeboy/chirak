<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnSet extends Model
{
    public const LABELLING = 'labelling';
    public const REVIEW = 'review';
    public const FROZEN = 'frozen';

    protected $fillable = [
        'run_id', 'state', 'requests', 'input_tokens', 'output_tokens',
        'eval_state', 'eval_stage', 'worker_job_id', 'error', 'frozen_at', 'items_deleted_at',
    ];

    protected function casts(): array
    {
        return ['frozen_at' => 'datetime', 'items_deleted_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function isFrozen(): bool
    {
        return $this->state === self::FROZEN;
    }
}
