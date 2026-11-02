@use('App\Support\Fmt')
<div @unless ($run->isTerminal()) wire:poll.5s @endunless>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight">{{ $run->name }}</h1>
                @include('run._status-badge', ['status' => $run->status])
                @if ($health)
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700"><span class="size-2 rounded-full bg-emerald-500"></span>GPU worker online @if (! empty($health['gpu']['name']))({{ $health['gpu']['name'] }})@endif</span>
                @else
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600"><span class="size-2 rounded-full bg-slate-400"></span>GPU worker offline</span>
                @endif
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ $run->task_spec['description'] ?? '' }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('runs.playground', $run) }}" class="btn-secondary">Playground</a>
            <a href="{{ route('runs.tickets', $run) }}" class="btn-secondary">Own tickets</a>
            <a href="{{ route('runs.report', $run) }}" class="btn-secondary">Public report</a>
        </div>
    </div>

    @if ($run->status === 'waiting_for_worker')
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            The GPU worker is not reachable, so the run is paused and keeps retrying. It resumes by itself when the pod is back. Everything already finished stays visible below.
        </div>
    @endif
    @if ($run->status === 'failed')
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">Run failed. {{ $run->error }} @if ($run->stop_reason) ({{ $run->stop_reason }}) @endif</div>
    @endif
    @if ($run->status === 'not_worth_it')
        <div class="mb-6 rounded-lg border border-slate-200 bg-slate-100 p-4 text-sm text-slate-800">Not worth fine-tuning: {{ $run->stop_reason }}. The base model already reaches the target.</div>
    @endif

    @if ($run->status === 'awaiting_review')
        <div class="mb-6 rounded-xl border-2 border-violet-300 bg-violet-50 p-5">
            <h2 class="text-lg font-semibold text-violet-900">Human checkpoint: look at the data before training</h2>
            <p class="mt-1 text-sm text-violet-900">
                Eval set frozen with hash <span class="font-mono text-xs">{{ \Illuminate\Support\Str::limit($report->evalDataset()?->sha256 ?? '', 16, '...') }}</span>.
                @if ($trainSet) Leakage guard dropped {{ $trainSet->leakageDropped() }} of {{ $trainSet->stats['n_candidates'] ?? '?' }} candidates ({{ Fmt::pct($trainSet->leakageDropRate()) }}). @endif
                @foreach (array_filter([$report->evalDataset(), $trainSet]) as $set)
                    @isset($set->stats['dropped']['label_mismatch'])
                        Blind label check dropped {{ $set->stats['dropped']['label_mismatch'] }} {{ $set->purpose }} candidates whose text did not match their label.
                    @endisset
                @endforeach
                Compare the samples below, then approve.
            </p>
            <div class="mt-4 flex items-center gap-3">
                <button wire:click="approve" wire:loading.attr="disabled" class="btn" @disabled(! $unlocked)>Approve and train</button>
                @unless ($unlocked)<span class="text-sm text-violet-900">Enter the demo token (top right) to approve.</span>@endunless
            </div>
        </div>
    @endif

    <div class="space-y-6">
        <div class="card">
            <h2 class="card-title">Pipeline</h2>
            <ol class="space-y-2">
                @foreach ($stages as $stage)
                    <li class="flex items-center gap-3 text-sm">
                        @switch($stage['state'])
                            @case('done')<span class="flex size-5 items-center justify-center rounded-full bg-emerald-500 text-xs text-white">&#10003;</span>@break
                            @case('active')<span class="size-5 animate-pulse rounded-full border-2 border-brand-600 bg-brand-100"></span>@break
                            @case('waiting')<span class="size-5 rounded-full border-2 border-amber-500 bg-amber-100"></span>@break
                            @default<span class="size-5 rounded-full border-2 border-slate-200"></span>
                        @endswitch
                        <span @class(['text-slate-400' => $stage['state'] === 'pending', 'font-medium text-slate-900' => in_array($stage['state'], ['active', 'waiting'])])>{{ $stage['label'] }}</span>
                        @if ($stage['detail'])<span class="text-xs text-slate-500">{{ $stage['detail'] }}</span>@endif
                    </li>
                @endforeach
            </ol>
        </div>

        @include('run._results')
        @include('run._holdout')
        @include('run._own', ['public' => false])

        <div class="grid gap-6 lg:grid-cols-2">
            @include('run._loss')
            @include('run._leakage')
        </div>

        @include('run._per-class')
        @include('run._confusion')
        @include('run._decisions')

        <div class="card">
            <h2 class="card-title">Dataset preview, {{ count($evalSample) }} eval items and {{ count($trainSample) }} training items{{ $trainSet ? ' (iteration '.$trainSet->iteration.')' : '' }}</h2>
            @if ($evalSample || $trainSample)
                <div class="grid gap-6 lg:grid-cols-2">
                    @foreach ([['Eval (frozen, used for all numbers above)', $evalSample], ['Training (synthetic)', $trainSample]] as [$title, $sample])
                        <div>
                            <h3 class="mb-2 text-sm font-semibold">{{ $title }}</h3>
                            <ul class="space-y-2">
                                @forelse ($sample as $item)
                                    <li class="rounded-lg border border-slate-200 p-3 text-sm">
                                        <p>{{ $item['input'] ?? '' }}</p>
                                        <p class="mt-1 font-mono text-xs text-slate-500">{{ json_encode($item['output'] ?? null, JSON_UNESCAPED_UNICODE) }}
                                            @if (! empty($item['source']))<span class="ml-1 rounded bg-slate-100 px-1">{{ $item['source'] }}</span>@endif</p>
                                    </li>
                                @empty
                                    <li class="text-sm text-slate-500">Not generated yet.</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-slate-500">Samples appear once the datasets are generated and copied from the worker.</p>
            @endif
        </div>

        <div class="card">
            <h2 class="card-title">Adapters</h2>
            @forelse ($report->iterations->whereNotNull('adapter_archive_local') as $iteration)
                <div class="flex items-center justify-between border-b border-slate-100 py-2 last:border-0">
                    <span class="text-sm">Iteration {{ $iteration->n }} @if ($run->best_iteration === $iteration->n)<span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">served</span>@endif</span>
                    <a href="{{ route('runs.adapter', [$run, $iteration->n]) }}" class="text-sm font-medium text-brand-600 hover:underline">Download adapter.tar.gz</a>
                </div>
            @empty
                <p class="text-sm text-slate-500">No adapter trained yet.</p>
            @endforelse
        </div>
    </div>
</div>
