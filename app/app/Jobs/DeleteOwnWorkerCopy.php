<?php

namespace App\Jobs;

use App\Models\Run;
use App\Services\Worker\WorkerClient;
use App\Services\Worker\WorkerRejected;
use App\Services\Worker\WorkerUnreachable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Asks the worker to delete its copy of the own-tickets file and the eval-own results (they repeat ticket texts). */
class DeleteOwnWorkerCopy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $runId, public int $attempt = 0)
    {
    }

    public function handle(WorkerClient $worker): void
    {
        $run = Run::find($this->runId);
        $set = $run?->ownSet()->first();
        if (! $set || ! $set->items_deleted_at || $set->worker_copy_deleted_at) {
            return;
        }

        try {
            $worker->deleteOwn($run->slug);
            $set->update(['worker_copy_deleted_at' => now()]);
        } catch (WorkerUnreachable) {
            self::dispatch($run->id, $this->attempt + 1)->delay(now()->addSeconds(min(300, 15 * 2 ** min($this->attempt, 5))));
        } catch (WorkerRejected $e) {
            $set->update(['error' => 'worker copy not deleted ('.$e->status.'): '.$e->getMessage()]);
        }
    }
}
