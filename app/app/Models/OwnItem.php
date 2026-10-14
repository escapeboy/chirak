<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnItem extends Model
{
    public const NEW = 'new';
    public const LABELLED = 'labelled';
    public const UNSTABLE = 'unstable';
    public const DROPPED = 'dropped';
    public const EVAL = 'eval';
    public const TRAIN = 'train';

    protected $fillable = ['run_id', 'position', 'split', 'text', 'status', 'passes', 'label', 'label_source', 'public', 'decided_at'];

    protected function casts(): array
    {
        return ['passes' => 'array', 'label' => 'array', 'public' => 'boolean', 'decided_at' => 'datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
