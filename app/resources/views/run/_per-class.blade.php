@use('App\Support\Fmt')
@php($classes = $report->perClass())
<div class="card">
    <h2 class="card-title">Per-class results, {{ $report->final() ? 'baseline vs iteration '.$report->final()->iteration : 'baseline' }}</h2>
    @if ($classes)
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead><tr>
                    <th class="th">Class</th><th class="th">Support</th>
                    <th class="th">Base P</th><th class="th">Base R</th><th class="th">Base F1</th>
                    @if ($report->final())<th class="th">P</th><th class="th">R</th><th class="th">F1</th><th class="th">F1 vs base</th>@endif
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($classes as $class => $row)
                    <tr>
                        <td class="td font-medium">{{ $class }}</td>
                        <td class="td">{{ $row['final']['support'] ?? $row['base']['support'] ?? '—' }}</td>
                        <td class="td">{{ Fmt::pct($row['base']['precision'] ?? null, 0) }}</td>
                        <td class="td">{{ Fmt::pct($row['base']['recall'] ?? null, 0) }}</td>
                        <td class="td">{{ Fmt::pct($row['base']['f1'] ?? null, 0) }}</td>
                        @if ($report->final())
                            <td class="td">{{ Fmt::pct($row['final']['precision'] ?? null, 0) }}</td>
                            <td class="td">{{ Fmt::pct($row['final']['recall'] ?? null, 0) }}</td>
                            <td class="td">{{ Fmt::pct($row['final']['f1'] ?? null, 0) }}</td>
                            <td @class(['td font-medium', 'text-emerald-700' => ($row['delta_f1'] ?? 0) > 0, 'text-red-700' => ($row['delta_f1'] ?? 0) < 0])>{{ Fmt::delta($row['delta_f1']) }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-sm text-slate-500">Per-class numbers appear after the baseline evaluation.</p>
    @endif
</div>
