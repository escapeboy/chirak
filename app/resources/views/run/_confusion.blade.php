@php($confusion = $report->confusion())
<div class="card">
    <h2 class="card-title">Confusion matrix @if ($report->confusionSource())<span class="normal-case text-slate-400">({{ $report->confusionSource() }}, rows = expected, columns = predicted)</span>@endif</h2>
    @if ($confusion)
        @php($max = max(1, ...array_map(fn ($row) => max($row), $confusion['matrix'])))
        <div class="overflow-x-auto">
            <table class="text-xs">
                <thead><tr>
                    <th class="p-1"></th>
                    @foreach ($confusion['labels'] as $label)
                        <th class="max-w-20 p-1 text-left font-medium text-slate-500" title="{{ $label }}">{{ \Illuminate\Support\Str::limit($label, 10) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                @foreach ($confusion['matrix'] as $r => $row)
                    <tr>
                        <th class="whitespace-nowrap p-1 pr-2 text-right font-medium text-slate-500">{{ $confusion['labels'][$r] ?? '' }}</th>
                        @foreach ($row as $c => $value)
                            @php($diagonal = $r === $c)
                            <td class="h-9 w-14 border border-white text-center {{ $value / $max > 0.55 ? 'text-white' : 'text-slate-800' }}"
                                style="background-color: {{ $diagonal ? 'rgba(5,150,105,' : 'rgba(220,38,38,' }}{{ $value === 0 ? 0 : round(0.08 + 0.92 * $value / $max, 2) }})">{{ $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-sm text-slate-500">No evaluation yet.</p>
    @endif
</div>
