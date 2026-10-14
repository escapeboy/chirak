@use('App\Support\Fmt')
<x-layouts.app :title="$run->name.' report'">
    <div class="mb-6">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight">{{ $run->name }}</h1>
            @include('run._status-badge', ['status' => $run->status])
        </div>
        <p class="mt-1 text-sm text-slate-500">{{ $run->task_spec['description'] ?? '' }} Public, read-only report built from the stored results.</p>
        @if ($run->status === 'failed' && $run->stop_reason)<p class="mt-2 text-sm text-red-700">Stopped: {{ $run->stop_reason }}</p>@endif
        @if ($run->status === 'not_worth_it')<p class="mt-2 text-sm text-slate-700">Fine-tuning was skipped: {{ $run->stop_reason }}.</p>@endif
    </div>

    @if ($report->hardDataset() && $report->headline())
        {{-- Two slices, two headlines: no blended number. --}}
        <div class="mb-6 grid gap-4 sm:grid-cols-2">
            @foreach (['clean' => 'Clean slice', 'hard' => 'Hard slice'] as $slice => $title)
                <div class="card">
                    <div class="text-sm text-slate-500">{{ $title }}: {{ str_replace('_', '-', $run->target_metric) }}</div>
                    <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                        @foreach ($report->headline()[$slice] ?? [] as $key => $value)
                            <div>
                                <div class="text-xs text-slate-500">{{ $key === 'base' ? 'Base model' : 'Iteration '.$key }}</div>
                                <div @class(['text-2xl font-bold', 'text-emerald-700' => $key === $run->best_iteration])>{{ Fmt::pct($value) }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($report->baselineValue() !== null)
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="card"><div class="text-sm text-slate-500">Baseline {{ str_replace('_', '-', $run->target_metric) }}</div><div class="text-3xl font-bold">{{ Fmt::pct($report->baselineValue()) }}</div></div>
            <div class="card"><div class="text-sm text-slate-500">Final {{ str_replace('_', '-', $run->target_metric) }}</div><div class="text-3xl font-bold">{{ Fmt::pct($report->finalValue()) }}</div></div>
            <div class="card"><div class="text-sm text-slate-500">Change</div><div class="text-3xl font-bold">{{ Fmt::delta($report->finalValue() !== null ? $report->finalValue() - $report->baselineValue() : null) }}</div></div>
        </div>
    @endif

    <div class="space-y-6">
        @include('run._results')
        @include('run._holdout')
        @include('run._own', ['public' => true])
        @include('run._per-class')
        @include('run._confusion')
        @include('run._leakage')
        @include('run._decisions')
        @include('run._loss')
    </div>
</x-layouts.app>
