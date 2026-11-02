<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' - Chirak' : 'Chirak' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full font-sans text-slate-900 antialiased">
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
        <a href="{{ route('home') }}" class="flex items-baseline gap-2">
            <span class="text-lg font-bold tracking-tight text-brand-600">Chirak</span>
            <span class="hidden text-sm text-slate-500 sm:inline">task description in, fine-tuned small model out</span>
        </a>
        <div class="flex items-center gap-3">
            @unless (config('chirak.readonly'))
                <a href="{{ route('runs.create') }}" class="text-sm font-medium text-slate-700 hover:text-brand-600">New run</a>
            @endunless
            @if (App\Support\DemoToken::unlocked())
                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700">demo token accepted</span>
            @else
                <form method="POST" action="{{ route('unlock') }}" class="flex items-center gap-2">
                    @csrf
                    <input type="password" name="demo_token" placeholder="Demo token" autocomplete="off"
                           class="w-36 rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm focus:border-brand-500 focus:outline-none">
                    <button class="btn-secondary !py-1.5">Unlock</button>
                </form>
            @endif
        </div>
    </div>
    @if (session('unlock_error'))
        <div class="bg-red-50 px-4 py-2 text-center text-sm text-red-700">{{ session('unlock_error') }}</div>
    @endif
</header>

<main class="mx-auto max-w-6xl px-4 py-8">
    {{ $slot ?? '' }}
</main>

<footer class="mx-auto max-w-6xl px-4 pb-8 text-xs text-slate-400">
    Chirak, MIT licensed. Training and evaluation run on one AMD Instinct GPU (MI300X during the spike, MI350X for the demo run).
</footer>
</body>
</html>
