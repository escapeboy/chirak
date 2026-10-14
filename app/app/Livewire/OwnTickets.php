<?php

namespace App\Livewire;

use App\Models\OwnItem;
use App\Models\Run;
use App\Services\Own\OwnTickets as Tickets;
use App\Support\DemoToken;
use App\Support\TicketParser;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/** Upload, review and freeze a run's own tickets. Every change needs the demo token; ticket texts are shown only to it. */
class OwnTickets extends Component
{
    use WithFileUploads, WithPagination;

    public Run $run;

    public string $paste = '';

    public $file = null;

    public string $filter = 'unstable';

    public string $split = 'all';

    /** Percent of the upload that goes to training instead of evaluation. */
    public int $trainShare = 0;

    /** @var array<int, array<string, mixed>> item id => field => chosen value */
    public array $edits = [];

    public ?string $notice = null;

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSplit(): void
    {
        $this->resetPage();
    }

    public function uploadTickets(Tickets $tickets): void
    {
        $this->guard();
        $this->validate(['file' => 'nullable|file|max:2048', 'paste' => 'nullable|string|max:2000000']);
        $content = $this->file ? (string) file_get_contents($this->file->getRealPath()) : $this->paste;

        try {
            $tickets->upload($this->run, TicketParser::parse($content), $this->trainShare);
        } catch (InvalidArgumentException $e) {
            $this->notice = $e->getMessage();

            return;
        } finally {
            $this->file?->delete();
        }
        $this->reset('paste', 'file', 'notice');
    }

    public function accept(Tickets $tickets, int $id): void
    {
        $this->guard();
        $tickets->accept($this->item($id));
    }

    public function change(Tickets $tickets, int $id): void
    {
        $this->guard();
        $item = $this->item($id);

        try {
            $tickets->change($item, ($this->edits[$id] ?? []) + ($item->label ?? []));
        } catch (InvalidArgumentException $e) {
            $this->notice = $e->getMessage();

            return;
        }
        unset($this->edits[$id]);
        $this->notice = null;
    }

    public function drop(Tickets $tickets, int $id): void
    {
        $this->guard();
        $tickets->drop($this->item($id));
    }

    public function setPublic(Tickets $tickets, int $id, bool $public): void
    {
        $this->guard();

        try {
            $tickets->setPublic($this->item($id), $public);
        } catch (InvalidArgumentException $e) {
            $this->notice = $e->getMessage();

            return;
        }
        $this->notice = null;
    }

    public function relabel(Tickets $tickets): void
    {
        $this->guard();
        $tickets->label($this->run);
    }

    public function freeze(Tickets $tickets): void
    {
        $this->guard();
        $tickets->freeze($this->run);
    }

    public function evaluate(Tickets $tickets): void
    {
        $this->guard();
        $tickets->evaluate($this->run);
    }

    public function trainNewRun(Tickets $tickets)
    {
        $this->guard();
        $key = 'start-run:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->notice = 'At most 3 runs per hour from one address. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' min.';

            return null;
        }
        $run = $tickets->trainNewRun($this->run);
        RateLimiter::hit($key, 3600);

        return $this->redirectRoute('runs.show', $run);
    }

    public function deleteItems(Tickets $tickets): void
    {
        $this->guard();
        $tickets->deleteItems($this->run);
        $this->reset('edits', 'notice');
    }

    public function render(Tickets $tickets)
    {
        $run = $this->run->refresh();
        $set = $run->ownSet()->first();
        $counts = $run->ownItems()->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $unlocked = DemoToken::unlocked();

        $items = null;
        if ($set && $unlocked) {
            $query = $run->ownItems();
            if ($this->filter === 'unstable') {
                $query->where('status', OwnItem::UNSTABLE);
            }
            if (in_array($this->split, [OwnItem::EVAL, OwnItem::TRAIN], true)) {
                $query->where('split', $this->split);
            }
            $items = $query->paginate(25);
        }

        return view('livewire.own-tickets', [
            'set' => $set, 'counts' => $counts, 'items' => $items, 'unlocked' => $unlocked,
            'fields' => $run->task_spec['fields'],
            'freezable' => $tickets->freezable($run),
            'canTrainNewRun' => $tickets->canTrainNewRun($run), 'followUp' => $run->followUp,
            'min' => TicketParser::MIN, 'max' => TicketParser::MAX,
        ])->layout('components.layouts.app', ['title' => $run->name.' own tickets']);
    }

    private function guard(): void
    {
        abort_if(config('chirak.readonly'), 403, 'This read-only demo has no GPU worker and no Claude key: tickets cannot be uploaded or changed here.');
        abort_unless(DemoToken::unlocked(), 403, 'Enter the demo token to change the tickets.');
    }

    private function item(int $id): OwnItem
    {
        return $this->run->ownItems()->findOrFail($id);
    }
}
