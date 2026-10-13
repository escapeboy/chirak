<?php

namespace App\Jobs;

use App\Models\Run;
use App\Services\Pipeline\RunPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class AdvanceRun implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $runId, public int $attempt = 0)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('run-'.$this->runId))->releaseAfter(10)->expireAfter(1800)];
    }

    public function handle(RunPipeline $pipeline): void
    {
        if ($run = Run::find($this->runId)) {
            $pipeline->advance($run, $this->attempt);
        }
    }
}
