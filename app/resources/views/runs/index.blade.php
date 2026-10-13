@use('App\Support\Fmt')
<x-layouts.app title="Runs">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Runs</h1>
            <p class="text-sm text-slate-500">Each run turns a task description and a few examples into a LoRA-tuned small model.</p>
        </div>
        <a href="{{ route('runs.create') }}" class="btn">New run</a>
    </div>

    <div class="card !p-0 overflow-hidden">
        <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-50"><tr>
                <th class="th">Run</th><th class="th">Status</th><th class="th">Target</th>
                <th class="th">Baseline</th><th class="th">Best</th><th class="th">Iterations</th><th class="th"></th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($runs as $row)
                @php([$run, $report] = [$row['run'], $row['report']])
                <tr class="hover:bg-slate-50">
                    <td class="td"><a href="{{ route('runs.show', $run) }}" class="font-medium text-brand-600 hover:underline">{{ $run->name }}</a>
                        <div class="text-xs text-slate-400">{{ $run->created_at->diffForHumans() }}</div></td>
                    <td class="td">@include('run._status-badge', ['status' => $run->status])</td>
                    <td class="td">{{ str_replace('_', '-', $run->target_metric) }} {{ Fmt::pct($run->target_value, 0) }}</td>
                    <td class="td">{{ Fmt::pct($report->baselineValue()) }}</td>
                    <td class="td">{{ Fmt::pct($report->finalValue()) }}</td>
                    <td class="td">{{ $run->iterations->whereNotNull('finished_at')->count() }}</td>
                    <td class="td text-right"><a href="{{ route('runs.report', $run) }}" class="text-sm text-slate-500 hover:text-brand-600">Report</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="td py-10 text-center text-slate-500">No runs yet. Start with the merchant ticket template.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
