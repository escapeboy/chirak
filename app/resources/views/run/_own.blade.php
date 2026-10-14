@use('App\Support\Fmt')
@php($own = $report->own())
@if ($own)
<div class="card">
    <h2 class="card-title">Own tickets</h2>
    <p class="mb-3 text-sm text-slate-500">
        {{ $own['n'] }} of the shop's own tickets, masked and frozen as a separate slice. Labels: {{ $own['label_sources']['judge'] }} by the Claude judge, {{ $own['label_sources']['human'] }} by a person
        @if ($own['label_sources']['claude-code-delegated'] ?? 0)
            and {{ $own['label_sources']['claude-code-delegated'] }} by Claude Code on the owner's delegation (not a person)
        @endif
        @if ($own['dropped']) ({{ $own['dropped'] }} dropped in review)@endif. {{ $own['examples'] ? 'Apart from the examples the owner approved, the tickets are private to the run owner.' : 'The tickets themselves are private to the run owner.' }}
    </p>
    @php($link = fn ($r) => ($public ?? true) ? route('runs.report', $r) : route('runs.show', $r))
    @if ($own['train'])
        <p class="mb-3 text-sm text-slate-500">
            {{ $own['train']['share'] }}% of the uploaded tickets were set aside for training before labelling: {{ $own['train']['n'] }} went into a training file, {{ $own['n'] }} were kept for evaluation.
            @if ($own['train']['overlap_dropped']) {{ $own['train']['overlap_dropped'] }} training tickets were left out because they repeated an evaluation ticket or shared a word 8-gram with one. @endif
            The two parts never overlap.
        </p>
    @endif
    @if ($own['trained_from'])
        <p class="mb-3 text-sm text-slate-500">Training included {{ $own['trained_from']['n'] }} own tickets of run <a href="{{ $link($own['trained_from']['run']) }}" class="font-medium text-brand-600 hover:underline">{{ $own['trained_from']['run']->name }}</a>.</p>
    @endif
    @if ($own['follow_up'])
        <p class="mb-3 text-sm text-slate-500">A follow-up run trained on the training part: <a href="{{ $link($own['follow_up']) }}" class="font-medium text-brand-600 hover:underline">{{ $own['follow_up']->name }}</a>.</p>
    @endif
    @if ($own['rows'])
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead><tr>
                    <th class="th">Model</th><th class="th">Accuracy</th><th class="th">Macro-F1</th>
                    @foreach (array_keys($own['rows'][0]['fields']) as $name)<th class="th">{{ $name }}</th>@endforeach
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($own['rows'] as $row)
                    <tr>
                        <td class="td font-medium">{{ $row['label'] }}</td>
                        <td class="td">{{ Fmt::pct($row['accuracy']) }}</td>
                        <td class="td">{{ Fmt::pct($row['macro_f1']) }}</td>
                        @foreach (array_keys($own['rows'][0]['fields']) as $name)<td class="td">{{ Fmt::pct($row['fields'][$name] ?? null) }}</td>@endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-2 text-xs text-slate-500">Accuracy is on the primary field; the field columns are the accuracy of each field on its own.</p>
    @else
        <p class="text-sm text-slate-500">Scores appear once the evaluation has run.</p>
    @endif
    @if ($own['examples'])
        <h3 class="mt-4 text-sm font-semibold">Examples the owner approved</h3>
        <ul class="mt-2 space-y-2">
            @foreach ($own['examples'] as $example)
                <li class="rounded-lg border border-slate-200 p-3 text-sm">
                    <p>{{ $example['text'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        @foreach ($example['label'] as $name => $value){{ $name }}: {{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}@if (! $loop->last), @endif @endforeach
                        &middot; labelled by {{ $example['label_source'] }}
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@elseif (! ($public ?? true))
<div class="card">
    <h2 class="card-title">Own tickets</h2>
    <p class="text-sm text-slate-500">Score the base model and the adapter on your own tickets. <a href="{{ route('runs.tickets', $run) }}" class="font-medium text-brand-600 hover:underline">Upload tickets</a></p>
</div>
@endif
