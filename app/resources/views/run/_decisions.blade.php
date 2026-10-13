<div class="card">
    <h2 class="card-title">Decisions log</h2>
    @forelse ($report->decisions as $d)
        <div class="mb-4 rounded-lg border border-slate-200 p-4 last:mb-0">
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <span class="font-semibold">After iteration {{ $d->iteration }}</span>
                <span @class(['rounded-full px-2.5 py-0.5 text-xs font-medium', 'bg-amber-50 text-amber-800' => $d->stop_rule['stop'], 'bg-blue-50 text-blue-700' => ! $d->stop_rule['stop']])>
                    Stop rules: {{ $d->stop_rule['stop'] ? 'stop' : 'continue' }}, {{ $d->stop_rule['reason'] }}
                </span>
            </div>
            @if ($d->orchestrator_output)
                @php($out = $d->orchestrator_output)
                <p class="text-sm text-slate-700">
                    <span class="font-medium">{{ $out['used'] === 'claude' ? ($d->model ?? 'Claude') : 'Fallback (worst-F1 classes)' }}:</span>
                    {{ $out['reason'] }}
                    @if ($out['used'] === 'claude')
                        <span class="text-slate-400">(model's own stop opinion: {{ $out['claude_stop'] ? 'stop' : 'continue' }}, not used)</span>
                    @endif
                </p>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($d->next_slices ?? [] as $slice)
                        <li class="rounded bg-slate-50 px-3 py-1.5"><span class="font-medium">{{ $slice['name'] }}</span> <span class="text-slate-500">x{{ $slice['n'] }}</span> <span class="text-slate-600">{{ $slice['guidance'] }}</span></li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-slate-500">The model was not consulted: the stop rules ended the loop.</p>
            @endif
        </div>
    @empty
        <p class="text-sm text-slate-500">No decision yet. The first one comes after the first adapter is evaluated.</p>
    @endforelse
</div>
