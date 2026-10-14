<div @if ($set && ($set->state === 'labelling' || in_array($set->eval_state, ['pending', 'running']))) wire:poll.5s @endif>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Own tickets: {{ $run->name }}</h1>
            <p class="text-sm text-slate-500">
                Upload {{ $min }} to {{ $max }} of your own tickets. Emails, phone numbers, IBANs, card numbers and long digit runs are masked on this server before anything is stored or sent to Claude.
                Claude labels each ticket twice; you review the labels, freeze the set, and the base model and the served adapter are scored on it.
            </p>
        </div>
        <a href="{{ route('runs.show', $run) }}" class="btn-secondary">Back to the run</a>
    </div>

    @unless ($unlocked)
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Enter the demo token (top right) to upload, review or see the tickets. They are private to this run.</div>
    @endunless
    @if ($notice)
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ $notice }}</div>
    @endif

    @if (! $set && config('chirak.readonly'))
        <div class="card text-sm text-slate-600">This is a read-only demo without a GPU worker or a Claude key, so own tickets cannot be uploaded here. Runs that have own-ticket scores show them on their report.</div>
    @elseif (! $set)
        <form wire:submit="uploadTickets" class="card space-y-4">
            <h2 class="card-title">Upload tickets</h2>
            <div>
                <label class="label" for="file">CSV or JSONL file, one ticket per row (CSV: a column named <code>text</code>, else the first column)</label>
                <input id="file" type="file" wire:model="file" accept=".csv,.jsonl,.json,.txt" class="input">
                @error('file')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="paste">Or paste them (put texts that contain commas in double quotes)</label>
                <textarea id="paste" rows="8" class="input font-mono text-xs" wire:model="paste"></textarea>
            </div>
            <div>
                <label class="label" for="trainShare">Use part of the tickets for training</label>
                <select id="trainShare" wire:model="trainShare" class="input !w-auto">
                    @foreach (\App\Models\OwnSet::SHARES as $share)<option value="{{ $share }}">{{ $share === 0 ? 'None, all tickets are for evaluation' : $share.'% for training' }}</option>@endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">The share is split off now, before labelling, and never overlaps the evaluation tickets. Both parts are labelled and reviewed here.</p>
            </div>
            <button class="btn" wire:loading.attr="disabled" @disabled(! $unlocked)>Mask and upload</button>
        </form>
    @else
        <div class="space-y-6">
            <div class="card">
                <h2 class="card-title">Status</h2>
                <dl class="grid gap-4 text-sm sm:grid-cols-5">
                    <div><dt class="text-slate-500">Tickets</dt><dd class="text-xl font-bold">{{ $counts->sum() }}</dd></div>
                    <div><dt class="text-slate-500">Labelled</dt><dd class="text-xl font-bold">{{ $counts['labelled'] ?? 0 }}</dd></div>
                    <div><dt class="text-slate-500">Unstable</dt><dd class="text-xl font-bold">{{ $counts['unstable'] ?? 0 }}</dd></div>
                    <div><dt class="text-slate-500">Not judged yet</dt><dd class="text-xl font-bold">{{ $counts['new'] ?? 0 }}</dd></div>
                    <div><dt class="text-slate-500">Dropped</dt><dd class="text-xl font-bold">{{ $counts['dropped'] ?? 0 }}</dd></div>
                </dl>
                <p class="mt-3 text-xs text-slate-500">
                    Judge usage: {{ number_format($set->requests) }} requests, {{ number_format($set->input_tokens) }} input and {{ number_format($set->output_tokens) }} output tokens.
                    @if ($set->items_deleted_at) The tickets were deleted {{ $set->items_deleted_at->diffForHumans() }}; counts and scores stay. @endif
                    @if ($set->items_deleted_at)
                        @if ($set->worker_copy_deleted_at) Worker copy deleted {{ $set->worker_copy_deleted_at->diffForHumans() }}. @else Worker copy: deletion pending. @endif
                    @endif
                </p>
                @if ($set->error)<p class="mt-2 text-sm text-red-700">{{ $set->error }}</p>@endif

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    @if ($set->state === 'labelling')
                        <span class="text-sm text-slate-600">Claude is labelling the tickets.</span>
                        @if ($counts['new'] ?? 0)<button wire:click="relabel" class="btn-secondary" @disabled(! $unlocked)>Label the remaining tickets again</button>@endif
                    @elseif ($set->state === 'review')
                        <button wire:click="freeze" wire:confirm="Freeze the set? It cannot be changed afterwards." class="btn" @disabled(! $unlocked || ! $freezable)>Freeze and evaluate</button>
                        @unless ($freezable)<span class="text-sm text-slate-600">Resolve every unstable ticket first (accept, change or drop).</span>@endunless
                    @else
                        <span class="rounded bg-emerald-100 px-2 py-1 text-sm text-emerald-800">Frozen {{ $set->frozen_at?->toDayDateTimeString() }}</span>
                        <span class="text-sm text-slate-600">
                            Evaluation: {{ $set->eval_state ?? 'not started' }}@if ($set->eval_stage && $set->eval_state === 'running') ({{ $set->eval_stage }})@endif
                        </span>
                        @if (! $set->items_deleted_at && ! in_array($set->eval_state, ['pending', 'running']))
                            <button wire:click="evaluate" class="btn-secondary" @disabled(! $unlocked)>Evaluate again</button>
                        @endif
                    @endif
                    @if ($canTrainNewRun)
                        <button wire:click="trainNewRun" wire:confirm="Start a new run that trains on these tickets?" class="btn-secondary" @disabled(! $unlocked)>Train a new run with these tickets</button>
                    @endif
                    @if ($followUp)
                        <a href="{{ route('runs.show', $followUp) }}" class="text-sm font-medium text-brand-600 hover:underline">Follow-up run: {{ $followUp->name }}</a>
                    @endif
                    <button wire:click="deleteItems" wire:confirm="Delete the uploaded tickets and the worker's copy? Scores and counts stay." class="btn-secondary !text-red-700" @disabled(! $unlocked || $set->items_deleted_at)>Delete the tickets</button>
                </div>
            </div>

            @include('run._own', ['report' => new \App\Support\RunReport($run), 'public' => false])

            @if ($items !== null && ! $set->items_deleted_at)
                <div class="card">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                        <h2 class="card-title !mb-0">Tickets</h2>
                        <div class="flex gap-2">
                            @if ($set->train_share > 0)
                                <select wire:model.live="split" class="input !w-auto"><option value="all">Both parts</option><option value="eval">Evaluation</option><option value="train">Training</option></select>
                            @endif
                            @unless ($set->isFrozen())
                                <select wire:model.live="filter" class="input !w-auto"><option value="unstable">Unstable only</option><option value="all">All</option></select>
                            @endunless
                        </div>
                    </div>
                    <ul class="space-y-3">
                        @forelse ($items as $item)
                            <li wire:key="item-{{ $item->id }}" class="rounded-lg border border-slate-200 p-3 text-sm">
                                <p>{{ $item->text }}</p>
                                <div class="mt-2 flex flex-wrap items-end gap-3">
                                    @foreach ($fields as $field)
                                        <div>
                                            <label class="block text-xs text-slate-500">{{ $field['name'] }}</label>
                                            <select class="input !w-auto !py-1" wire:model="edits.{{ $item->id }}.{{ $field['name'] }}" @disabled($set->state !== 'review' || $item->status === 'dropped')>
                                                @php($current = $item->label[$field['name']] ?? null)
                                                @if ($current === null)<option value="">—</option>@endif
                                                @if ($field['type'] === 'bool')
                                                    <option value="true" @selected($current === true)>true</option><option value="false" @selected($current === false)>false</option>
                                                @else
                                                    @foreach ($field['values'] as $value)<option value="{{ $value }}" @selected($current === $value)>{{ $value }}</option>@endforeach
                                                @endif
                                            </select>
                                        </div>
                                    @endforeach
                                    <span @class(['rounded px-1.5 py-0.5 text-xs', 'bg-amber-100 text-amber-800' => $item->status === 'unstable', 'bg-slate-100 text-slate-700' => $item->status !== 'unstable'])>
                                        @if ($set->train_share > 0){{ $item->split }}, @endif{{ $item->status }}@if ($item->label_source), {{ $item->label_source }}@endif @if ($item->decided_at) {{ $item->decided_at->diffForHumans() }}@endif
                                    </span>
                                    @if ($set->isFrozen() && $item->status === 'labelled')
                                        <button wire:click="setPublic({{ $item->id }}, {{ $item->public ? 'false' : 'true' }})" class="btn-secondary !py-1 ml-auto" @disabled(! $unlocked)>{{ $item->public ? 'Shown on public report (hide)' : 'Show on public report' }}</button>
                                    @endif
                                    @if ($set->state === 'review')
                                        <div class="ml-auto flex gap-2">
                                            @if ($item->status === 'labelled' && $item->label_source === 'judge')<button wire:click="accept({{ $item->id }})" class="btn-secondary !py-1" @disabled(! $unlocked)>Accept</button>@endif
                                            @if ($item->status !== 'dropped')<button wire:click="change({{ $item->id }})" class="btn-secondary !py-1" @disabled(! $unlocked)>Set labels</button>@endif
                                            @if ($item->status !== 'dropped')<button wire:click="drop({{ $item->id }})" class="btn-secondary !py-1 !text-red-700" @disabled(! $unlocked)>Drop</button>@endif
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li class="text-sm text-slate-500">Nothing to show.</li>
                        @endforelse
                    </ul>
                    <div class="mt-4">{{ $items->links() }}</div>
                </div>
            @endif
        </div>
    @endif
</div>
