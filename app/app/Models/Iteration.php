<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Iteration extends Model
{
    protected $fillable = [
        'run_id', 'n', 'adapter_path', 'adapter_archive_local', 'train_config', 'loss_curve', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'train_config' => 'array',
            'loss_curve' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
