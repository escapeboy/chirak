@use('App\Support\Fmt')
@if ($rows = $report->holdout())
<div class="card">
    <h2 class="card-title">Held-out examples</h2>
    <p class="mb-3 text-sm text-slate-500">
        {{ count($rows) }} tickets kept out of every dataset file, sent to the base model and to the served adapter (iteration {{ $report->run->best_iteration }}) through the playground's /predict.
        They were written by Claude at the owner's request, not by a human.
    </p>
    @php($fmt = fn ($l) => $l ? implode(' / ', array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $l)) : '—')
    @php($same = fn ($a, $b) => is_array($a) && collect($a)->sortKeys()->toJson() === collect($b)->sortKeys()->toJson())
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead><tr><th class="th">Ticket</th><th class="th">Expected</th><th class="th">Base model</th><th class="th">Adapter</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @foreach ($rows as $row)
                <tr>
                    <td class="td max-w-md whitespace-normal">{{ $row['input'] }}</td>
                    <td class="td">{{ $fmt($row['expected']) }}</td>
                    @foreach (['base', 'adapter'] as $who)
                        @php($p = $row[$who])
                        <td @class(['td', 'text-emerald-700' => $p && $same($p->parsed, $row['expected']), 'text-red-700' => $p && ! $same($p->parsed, $row['expected'])])>
                            {{ $p ? ($p->valid ? $fmt($p->parsed) : 'invalid: '.$p->error) : '—' }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-2 text-xs text-slate-500">Labels: category / urgency / needs_human. Green: equals the expected label in all fields.</p>
</div>
@endif
