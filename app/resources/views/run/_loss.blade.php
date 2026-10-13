@php($curves = collect($report->lossCurves())->mapWithKeys(fn ($c, $n) => ['Iteration '.$n => $c])->all())
<div class="card">
    <h2 class="card-title">Training loss</h2>
    @if ($curves)
        <div wire:ignore wire:key="loss-{{ md5(json_encode($curves)) }}" class="h-64">
            <canvas data-loss="{{ json_encode($curves) }}"></canvas>
        </div>
    @else
        <p class="text-sm text-slate-500">No training has finished yet.</p>
    @endif
</div>
