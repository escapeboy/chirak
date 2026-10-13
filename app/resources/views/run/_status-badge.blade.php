@php
    $colors = [
        'queued' => 'bg-slate-100 text-slate-700',
        'running' => 'bg-blue-50 text-blue-700',
        'waiting_for_worker' => 'bg-amber-50 text-amber-800',
        'awaiting_review' => 'bg-violet-50 text-violet-700',
        'completed' => 'bg-emerald-50 text-emerald-700',
        'not_worth_it' => 'bg-slate-100 text-slate-700',
        'failed' => 'bg-red-50 text-red-700',
    ];
@endphp
<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $colors[$status] ?? 'bg-slate-100 text-slate-700' }}">{{ App\Support\Fmt::statusLabel($status) }}</span>
