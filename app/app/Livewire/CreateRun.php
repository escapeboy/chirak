<?php

namespace App\Livewire;

use App\Models\Run;
use App\Services\Pipeline\RunPipeline;
use App\Support\DemoToken;
use App\Support\ExamplesParser;
use App\Support\TaskSpec;
use App\Support\TaskTemplate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

class CreateRun extends Component
{
    public string $name = 'Merchant ticket triage (BG/EN)';

    public string $description = '';

    public string $languages = 'bg, en';

    public string $fields = '';

    public string $guidelines = '';

    public string $seeds = '';

    public string $holdout = '';

    public string $targetMetric = 'macro_f1';

    public float $targetValue = 0.85;

    public int $evalN = 200;

    public int $trainN = 1000;

    public bool $requireReview = true;

    public function mount(): void
    {
        $template = TaskTemplate::merchantTickets();
        $this->description = $template['description'];
        $this->fields = json_encode($template['fields'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->guidelines = $template['guidelines'];
        $this->seeds = TaskTemplate::toJsonl(TaskTemplate::seedExamples());
        $this->holdout = TaskTemplate::toJsonl(TaskTemplate::holdoutExamples());
    }

    public function start()
    {
        if (! DemoToken::unlocked()) {
            $this->addError('token', 'Enter the demo token (top right) to start a run.');

            return null;
        }

        $this->validate([
            'name' => 'required|string|max:120',
            'description' => 'required|string|max:2000',
            'languages' => 'required|string|max:60',
            'guidelines' => 'nullable|string|max:5000',
            'targetMetric' => 'required|in:macro_f1,accuracy',
            'targetValue' => 'required|numeric|between:0.05,1',
            'evalN' => 'required|integer|between:20,1000',
            'trainN' => 'required|integer|between:100,5000',
        ]);

        $fields = json_decode($this->fields, true);
        if ($errors = TaskSpec::fieldErrors($fields)) {
            $this->addError('fields', implode('; ', $errors));

            return null;
        }

        $spec = [
            'name' => Str::slug($this->name) ?: 'task',
            'description' => $this->description,
            'languages' => array_values(array_filter(array_map('trim', explode(',', $this->languages)))),
            'fields' => $fields,
            'guidelines' => (string) $this->guidelines,
        ];

        $seeds = ExamplesParser::parse($this->seeds, $spec);
        $holdout = ExamplesParser::parse($this->holdout, $spec);

        if (count($seeds['items']) < 3) {
            $seeds['errors'][] = 'at least 3 valid seed examples are needed';
        }
        if ($seeds['errors']) {
            $this->addError('seeds', implode('; ', array_slice($seeds['errors'], 0, 5)));
        }
        if ($holdout['errors']) {
            $this->addError('holdout', implode('; ', array_slice($holdout['errors'], 0, 5)));
        }
        if (array_intersect(array_column($seeds['items'], 'input'), array_column($holdout['items'], 'input'))) {
            $this->addError('holdout', 'a hold-out example is also a seed example; hold-outs must stay unseen');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return null;
        }

        $key = 'start-run:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('token', 'At most 3 runs per hour from one address. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' min.');

            return null;
        }
        RateLimiter::hit($key, 3600);

        $run = Run::create([
            'slug' => (Str::slug(Str::limit($this->name, 40, ''), '-') ?: 'run').'-'.Str::lower(Str::random(6)),
            'name' => $this->name,
            'task_spec' => $spec,
            'seed_examples' => $seeds['items'],
            'holdout_examples' => $holdout['items'],
            'target_metric' => $this->targetMetric,
            'target_value' => $this->targetValue,
            'eval_n' => $this->evalN,
            'train_n' => $this->trainN,
            'require_review' => $this->requireReview,
            'status' => Run::QUEUED,
        ]);

        app(RunPipeline::class)->start($run);

        return $this->redirectRoute('runs.show', $run);
    }

    public function render()
    {
        return view('livewire.create-run')->layout('components.layouts.app', ['title' => 'New run']);
    }
}
