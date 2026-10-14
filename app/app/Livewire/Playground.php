<?php

namespace App\Livewire;

use App\Models\Run;
use App\Services\Worker\WorkerClient;
use App\Services\Worker\WorkerRejected;
use App\Services\Worker\WorkerUnreachable;
use App\Support\DemoToken;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class Playground extends Component
{
    public Run $run;

    public string $input = '';

    public ?array $base = null;

    public ?array $adapter = null;

    public ?string $notice = null;

    public bool $offline = false;

    public function useHoldout(int $index): void
    {
        $this->input = $this->run->holdout_examples[$index]['input'] ?? $this->input;
    }

    public function predict(WorkerClient $worker): void
    {
        if (! DemoToken::unlocked()) {
            $this->notice = 'Enter the demo token (top right) to use the playground.';

            return;
        }

        $this->validate(['input' => 'required|string|max:4000']);
        $this->reset('base', 'adapter', 'notice', 'offline');

        $key = 'playground:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, config('chirak.playground_per_minute'))) {
            $this->notice = 'Too many requests from your address. Try again in '.RateLimiter::availableIn($key).' s.';

            return;
        }
        RateLimiter::hit($key, 60);

        try {
            $answers = $worker->predictMany(array_filter(['base', $this->run->served_name]), $this->run->task_spec, $this->input);
            $this->base = $answers['base'];
            $this->adapter = $this->run->served_name ? $answers[$this->run->served_name] : null;
        } catch (WorkerUnreachable) {
            $this->offline = true;
            $this->base = $this->adapter = null;
        } catch (WorkerRejected $e) {
            $this->notice = $e->status === 409
                ? 'The GPU is busy with a training or evaluation job. Try again in a minute.'
                : 'The worker rejected the request: '.$e->getMessage();
        }
    }

    public function render()
    {
        $evaluations = $this->run->evaluations()->get()->keyBy(fn ($e) => $e->iteration ?? 'base');

        return view('livewire.playground', [
            'storedBase' => array_slice($evaluations->get('base')?->failures ?? [], 0, 5),
            'storedAdapter' => array_slice($evaluations->get($this->run->best_iteration)?->failures ?? [], 0, 5),
            'unlocked' => DemoToken::unlocked(),
        ])->layout('components.layouts.app', ['title' => 'Playground']);
    }
}
