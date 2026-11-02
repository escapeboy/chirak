@use('App\Support\Fmt')
@php($eval = $report->evalDataset())
<div class="card">
    <h2 class="card-title">Leakage guard and frozen eval set</h2>
    @if ($eval)
        <dl class="mb-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <div><dt class="text-slate-500">Eval items</dt><dd class="font-medium">{{ $eval->rows() }} ({{ $eval->syntheticRows() }} synthetic + {{ $eval->rows() - $eval->syntheticRows() }} seed)</dd></div>
            <div><dt class="text-slate-500">Frozen at</dt><dd class="font-medium">{{ $eval->frozen_at?->format('Y-m-d H:i:s') ?? '—' }} UTC</dd></div>
            @isset($eval->stats['dropped']['label_mismatch'])
                <div class="sm:col-span-2"><dt class="text-slate-500">Blind label check</dt><dd class="font-medium">{{ $eval->stats['dropped']['label_mismatch'] }} of {{ $eval->stats['n_candidates'] ?? '?' }} eval candidates dropped because the generator, labelling the text blind with the student's prompt, disagreed with the target label. The kept items are the unambiguous ones, so this eval is cleaner and easier than an unfiltered one.</dd></div>
            @endisset
            <div class="sm:col-span-2"><dt class="text-slate-500">Eval SHA-256</dt><dd class="break-all font-mono text-xs">{{ $eval->sha256 }}</dd></div>
        </dl>
    @else
        <p class="mb-4 text-sm text-slate-500">The eval set has not been generated yet.</p>
    @endif

    @if ($report->leakage())
        <table class="min-w-full divide-y divide-slate-200">
            <thead><tr><th class="th">Train set</th><th class="th">Candidates</th><th class="th">Dropped as near-copies of eval</th><th class="th">Drop rate</th><th class="th">Dropped by label check</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($report->leakage() as $row)
                <tr>
                    <td class="td">Iteration {{ $row['iteration'] }}</td>
                    <td class="td">{{ $row['candidates'] ?? '—' }}</td>
                    <td class="td">{{ $row['dropped'] }}</td>
                    <td @class(['td', 'font-semibold text-red-700' => $row['stopped']])>{{ Fmt::pct($row['rate']) }} @if ($row['stopped'])(above the 30% limit, run stopped)@endif</td>
                    <td class="td">{{ $row['label_mismatch'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
