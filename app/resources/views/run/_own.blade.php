@use('App\Support\Fmt')
@php($own = $report->own())
@if ($own)
<div class="card">
    <h2 class="card-title">Own tickets</h2>
    <p class="mb-3 text-sm text-slate-500">
        {{ $own['n'] }} of the shop's own tickets, masked and frozen as a separate slice. Labels: {{ $own['label_sources']['judge'] }} by the Claude judge, {{ $own['label_sources']['human'] }} by a person
        @if ($own['dropped']) ({{ $own['dropped'] }} dropped in review)@endif. The tickets themselves are private to the run owner.
    </p>
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
</div>
@elseif (! ($public ?? true))
<div class="card">
    <h2 class="card-title">Own tickets</h2>
    <p class="text-sm text-slate-500">Score the base model and the adapter on your own tickets. <a href="{{ route('runs.tickets', $run) }}" class="font-medium text-brand-600 hover:underline">Upload tickets</a></p>
</div>
@endif
