<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Decision extends Model
{
    protected $fillable = [
        'run_id', 'iteration', 'orchestrator_input', 'orchestrator_output', 'next_slices', 'stop_rule', 'model',
    ];

    protected function casts(): array
    {
        return [
            'orchestrator_input' => 'array',
            'orchestrator_output' => 'array',
            'next_slices' => 'array',
            'stop_rule' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
