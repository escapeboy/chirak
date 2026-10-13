@use('App\Support\Fmt')
<div class="card">
    <h2 class="card-title">Baseline vs iterations</h2>
    @if ($report->resultRows())
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead><tr>
                    <th class="th">Model</th><th class="th">Accuracy</th><th class="th">Macro-F1</th>
                    <th class="th">JSON validity</th><th class="th">Exact match</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($report->resultRows() as $row)
                    <tr @class(['bg-emerald-50/50' => $row['is_best']])>
                        <td class="td font-medium">{{ $row['label'] }} @if ($row['is_best'])<span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">served</span>@endif</td>
                        <td class="td">{{ Fmt::pct($row['accuracy']) }}</td>
                        <td class="td">{{ Fmt::pct($row['macro_f1']) }}</td>
                        <td class="td">{{ Fmt::pct($row['json_valid_rate']) }}</td>
                        <td class="td">{{ Fmt::pct($row['exact_match']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-sm text-slate-500">
            Target: {{ str_replace('_', '-', $report->run->target_metric) }} at least {{ Fmt::pct($report->run->target_value, 0) }}.
            @if ($report->run->stop_reason) Stopped: {{ $report->run->stop_reason }}. @endif
        </p>
    @else
        <p class="text-sm text-slate-500">No evaluation has finished yet.</p>
    @endif
</div>
