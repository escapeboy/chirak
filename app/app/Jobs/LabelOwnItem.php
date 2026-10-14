<?php

namespace App\Jobs;

use App\Models\OwnItem;
use App\Models\OwnSet;
use App\Services\Own\ClaudeLabeler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Two Claude passes over one masked ticket: both parse and agree, or the ticket is unstable. */
class LabelOwnItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $itemId)
    {
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('own-item-'.$this->itemId))->releaseAfter(10)->expireAfter(900)];
    }

    public function handle(ClaudeLabeler $labeler): void
    {
        $item = OwnItem::with('run')->find($this->itemId);
        $set = $item ? OwnSet::where('run_id', $item->run_id)->first() : null;
        if (! $item || $item->status !== OwnItem::NEW || $set?->state !== OwnSet::LABELLING) {
            return;
        }

        $result = $labeler->label($item->run->task_spec, $item->text);
        $agreed = $result['label'] !== null;
        $item->update([
            'passes' => $result['passes'],
            'status' => $agreed ? OwnItem::LABELLED : OwnItem::UNSTABLE,
            'label' => $result['label'],
            'label_source' => $agreed ? 'judge' : null,
            'decided_at' => $agreed ? now() : null,
        ]);
        OwnSet::whereKey($set->id)->incrementEach([
            'requests' => $result['usage']['requests'],
            'input_tokens' => $result['usage']['input_tokens'],
            'output_tokens' => $result['usage']['output_tokens'],
        ]);

        if (! OwnItem::where('run_id', $item->run_id)->where('status', OwnItem::NEW)->exists()) {
            OwnSet::where('run_id', $item->run_id)->where('state', OwnSet::LABELLING)->update(['state' => OwnSet::REVIEW, 'error' => null]);
        }
    }

    public function failed(Throwable $e): void
    {
        if ($item = OwnItem::find($this->itemId)) {
            OwnSet::where('run_id', $item->run_id)->update(['error' => 'labelling failed: '.$e->getMessage()]);
        }
    }
}
