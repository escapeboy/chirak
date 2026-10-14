<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnSet extends Model
{
    public const LABELLING = 'labelling';
    public const REVIEW = 'review';
    public const FROZEN = 'frozen';

    /** Shares of the upload that may go to training, in percent. */
    public const SHARES = [0, 20, 30, 50];

    protected $fillable = [
        'run_id', 'state', 'train_share', 'requests', 'input_tokens', 'output_tokens',
        'eval_state', 'eval_stage', 'worker_job_id', 'error', 'frozen_at', 'items_deleted_at', 'worker_copy_deleted_at',
    ];

    protected function casts(): array
    {
        return ['frozen_at' => 'datetime', 'items_deleted_at' => 'datetime', 'worker_copy_deleted_at' => 'datetime'];
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
