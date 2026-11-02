@use('App\Support\Fmt')
<div class="card">
    <h2 class="card-title">Baseline vs iterations</h2>
    @if ($report->resultRows())
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead><tr>
                    <th class="th">Model</th><th class="th">Accuracy</th><th class="th">Macro-F1{{ $report->hardDataset() ? ' (clean)' : '' }}</th>
                    <th class="th">JSON validity</th><th class="th">Exact match</th>
                    @if ($report->hardDataset())<th class="th">Macro-F1 (hard)</th><th class="th">Exact match (hard)</th>@endif
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                @foreach ($report->resultRows() as $row)
                    <tr @class(['bg-emerald-50/50' => $row['is_best']])>
                        <td class="td font-medium">{{ $row['label'] }} @if ($row['is_best'])<span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">served</span>@endif</td>
                        <td class="td">{{ Fmt::pct($row['accuracy']) }}</td>
                        <td class="td">{{ Fmt::pct($row['macro_f1']) }}</td>
                        <td class="td">{{ Fmt::pct($row['json_valid_rate']) }}</td>
                        <td class="td">{{ Fmt::pct($row['exact_match']) }}</td>
                        @if ($report->hardDataset())<td class="td">{{ Fmt::pct($row['hard_macro_f1']) }}</td><td class="td">{{ Fmt::pct($row['hard_exact_match']) }}</td>@endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-sm text-slate-500">
            Target: {{ str_replace('_', '-', $report->run->target_metric) }} at least {{ Fmt::pct($report->run->target_value, 0) }}.
            @if ($report->hardDataset()) Measured on the hard slice. @endif
            @if ($report->run->stop_reason) Stopped: {{ $report->run->stop_reason }}. @endif
        </p>
        @if ($hard = $report->hardDataset())
            <p class="mt-2 text-sm text-slate-600">
                Two eval slices. <span class="font-medium">Clean</span>: tickets where two readings by the generator agree on the label (the label it was written for and its own blind re-label); {{ $report->evalDataset()?->rows() }} tickets, the original frozen eval.
                <span class="font-medium">Hard</span>: tickets the blind label check rejected; {{ $hard->n }} tickets, labelled by the Claude judge where it agreed with the intended label ({{ $hard->stats['label_sources']['judge+proposed'] ?? '?' }}), otherwise
                @if (isset($hard->stats['label_sources']['human'])) by a human @else by Claude Code on the owner's delegation, not by a human @endif
                ({{ $hard->stats['label_sources']['human'] ?? $hard->stats['label_sources']['claude-code-delegated'] ?? '?' }}). sha256 <span class="font-mono">{{ substr($hard->sha256, 0, 12) }}…</span>
            </p>
        @endif
    @else
        <p class="text-sm text-slate-500">No evaluation has finished yet.</p>
    @endif
</div>
