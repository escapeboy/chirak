<div>
    <div class="mb-6">
        <a href="{{ route('runs.show', $run) }}" class="text-sm text-slate-500 hover:text-brand-600">&larr; {{ $run->name }}</a>
        <h1 class="mt-1 text-2xl font-bold tracking-tight">Playground</h1>
        <p class="text-sm text-slate-500">Send one input to the base model and to the tuned adapter and compare the answers.</p>
        @if (config('chirak.playground_note'))
            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{{ config('chirak.playground_note') }}</p>
        @endif
    </div>

    <div class="card mb-6 space-y-4">
        <div>
            <label class="label" for="input">Input</label>
            <textarea id="input" rows="4" class="input" wire:model="input" placeholder="Paste a customer ticket"></textarea>
            @error('input')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        @if ($run->holdout_examples)
            <div>
                <p class="mb-1 text-sm font-medium text-slate-700">Hold-out examples (never used for training or evaluation)</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($run->holdout_examples as $i => $example)
                        <button type="button" wire:click="useHoldout({{ $i }})" class="btn-secondary !px-3 !py-1.5 max-w-xs truncate" title="{{ $example['input'] }}">{{ \Illuminate\Support\Str::limit($example['input'], 40) }}</button>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="flex items-center gap-3">
            <button wire:click="predict" wire:loading.attr="disabled" class="btn" @disabled(! $unlocked)>Compare</button>
            <span wire:loading wire:target="predict" class="text-sm text-slate-500">Asking both models...</span>
            @unless ($unlocked)<span class="text-sm text-slate-500">Enter the demo token (top right) to run predictions.</span>@endunless
        </div>
        @if ($notice)<p class="text-sm text-amber-700">{{ $notice }}</p>@endif
    </div>

    @if ($offline)
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            The GPU worker is offline, so live predictions are not possible. Below are the stored results of the evaluation instead.
        </div>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        @foreach ([['Base model', $base, $storedBase, true], ['Tuned adapter'.($run->served_name ? '' : ' (not served)'), $adapter, $storedAdapter, (bool) $run->served_name]] as [$title, $result, $stored, $available])
            <div class="card">
                <h2 class="card-title">{{ $title }}</h2>
                @if ($result)
                    <dl class="space-y-2 text-sm">
                        <div><dt class="text-slate-500">Parsed</dt><dd class="font-mono text-xs">{{ $result['parsed'] ? json_encode($result['parsed'], JSON_UNESCAPED_UNICODE) : 'null' }}</dd></div>
                        <div><dt class="text-slate-500">Raw</dt><dd class="whitespace-pre-wrap break-words font-mono text-xs">{{ $result['raw'] }}</dd></div>
                        <div class="flex gap-6">
                            <div><dt class="text-slate-500">Valid</dt><dd class="font-medium {{ $result['valid'] ? 'text-emerald-700' : 'text-red-700' }}">{{ $result['valid'] ? 'yes' : 'no' }}</dd></div>
                            <div><dt class="text-slate-500">Latency</dt><dd class="font-medium">{{ $result['latency_ms'] }} ms</dd></div>
                        </div>
                        @if (! empty($result['error']))<div class="text-red-700">{{ $result['error'] }}</div>@endif
                    </dl>
                @elseif ($offline && $stored)
                    <p class="mb-2 text-xs text-slate-500">Stored failures from the evaluation (not live):</p>
                    <ul class="space-y-2">
                        @foreach ($stored as $f)
                            <li class="rounded-lg border border-slate-200 p-3 text-xs">
                                <p class="text-sm">{{ $f['input'] }}</p>
                                <p class="mt-1 font-mono text-slate-500">expected {{ json_encode($f['expected'], JSON_UNESCAPED_UNICODE) }}</p>
                                <p class="font-mono text-red-700">got {{ json_encode($f['got'], JSON_UNESCAPED_UNICODE) }} {{ $f['error'] ?? '' }}</p>
                            </li>
                        @endforeach
                    </ul>
                @elseif (! $available)
                    <p class="text-sm text-slate-500">{{ $run->best_iteration ? 'This adapter is not served here. Its scores are in the run report.' : 'The adapter is served after the run completes.' }}</p>
                @else
                    <p class="text-sm text-slate-500">No prediction yet.</p>
                @endif
            </div>
        @endforeach
    </div>
</div>
