<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">New run</h1>
        <p class="text-sm text-slate-500">Describe the task and give a few labelled examples. Chirak generates the data, tunes a small model and measures the gain on a frozen eval set.</p>
    </div>

    <form wire:submit="start" class="space-y-6">
        <div class="card space-y-4">
            <h2 class="card-title">Task</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="name">Name</label><input id="name" class="input" wire:model="name">@error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="languages">Languages (comma separated)</label><input id="languages" class="input" wire:model="languages">@error('languages')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
            </div>
            <div><label class="label" for="description">Task description</label><textarea id="description" rows="2" class="input" wire:model="description"></textarea>@error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
            <div>
                <label class="label" for="fields">Output fields (JSON; exactly one field is <code>"primary": true</code> and must be an enum)</label>
                <textarea id="fields" rows="12" class="input font-mono text-xs" wire:model="fields"></textarea>
                @error('fields')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div><label class="label" for="guidelines">Labelling guidelines</label><textarea id="guidelines" rows="4" class="input" wire:model="guidelines"></textarea></div>
        </div>

        <div class="card space-y-4">
            <h2 class="card-title">Examples</h2>
            <div>
                <label class="label" for="seeds">Seed examples (JSONL, at least 3). They style the eval set and are copied into it.</label>
                <textarea id="seeds" rows="7" class="input font-mono text-xs" wire:model="seeds"></textarea>
                @error('seeds')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="label" for="holdout">Hold-out examples (JSONL). Never sent to the GPU worker or used for training; only offered in the playground.</label>
                <textarea id="holdout" rows="5" class="input font-mono text-xs" wire:model="holdout"></textarea>
                @error('holdout')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
        </div>

        <div class="card space-y-4">
            <h2 class="card-title">Target and size</h2>
            <div class="grid gap-4 sm:grid-cols-4">
                <div><label class="label" for="tm">Metric</label>
                    <select id="tm" class="input" wire:model="targetMetric"><option value="macro_f1">Macro-F1</option><option value="accuracy">Accuracy</option></select></div>
                <div><label class="label" for="tv">Target (0 to 1)</label><input id="tv" type="number" step="0.01" class="input" wire:model="targetValue">@error('targetValue')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="en">Eval items</label><input id="en" type="number" class="input" wire:model="evalN">@error('evalN')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
                <div><label class="label" for="tn">Train items per iteration</label><input id="tn" type="number" class="input" wire:model="trainN">@error('trainN')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror</div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="requireReview" class="rounded border-slate-300"> Pause for my review before the first training run</label>
        </div>

        <div class="flex items-center gap-4">
            <button class="btn" wire:loading.attr="disabled">Start run</button>
            @error('token')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
    </form>
</div>
