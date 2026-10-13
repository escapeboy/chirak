<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const WAITING_FOR_WORKER = 'waiting_for_worker';
    public const AWAITING_REVIEW = 'awaiting_review';
    public const COMPLETED = 'completed';
    public const NOT_WORTH_IT = 'not_worth_it';
    public const FAILED = 'failed';

    protected $fillable = [
        'slug', 'name', 'task_spec', 'seed_examples', 'holdout_examples', 'base_model',
        'target_metric', 'target_value', 'eval_n', 'train_n', 'require_review', 'review_approved_at',
        'status', 'stage', 'stop_reason', 'current_worker_job_id', 'served_name', 'best_iteration', 'error',
    ];

    protected function casts(): array
    {
        return [
            'task_spec' => 'array',
            'seed_examples' => 'array',
            'holdout_examples' => 'array',
            'target_value' => 'float',
            'require_review' => 'boolean',
            'review_approved_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(Dataset::class);
    }

    public function iterations(): HasMany
    {
        return $this->hasMany(Iteration::class)->orderBy('n');
    }

    /** Scores on the frozen eval (the "clean" slice). */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class)->where('slice', 'clean');
    }

    /** Scores on eval-hard (the human-arbitrated "hard" slice), when the run has one. */
    public function hardEvaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class)->where('slice', 'hard');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class)->orderBy('iteration')->orderBy('id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::NOT_WORTH_IT, self::FAILED], true);
    }

    public function primaryField(): array
    {
        foreach ($this->task_spec['fields'] as $field) {
            if (! empty($field['primary'])) {
                return $field;
            }
        }

        return $this->task_spec['fields'][0];
    }

    public function metricOf(array $metrics): ?float
    {
        $value = $metrics[$this->target_metric] ?? null;

        return $value === null ? null : (float) $value;
    }
}
