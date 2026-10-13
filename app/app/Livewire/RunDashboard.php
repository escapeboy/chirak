<?php

namespace App\Livewire;

use App\Models\Run;
use App\Services\Pipeline\RunPipeline;
use App\Services\Worker\WorkerClient;
use App\Support\DemoToken;
use App\Support\RunReport;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class RunDashboard extends Component
{
    public Run $run;

    public function approve(): void
    {
        abort_unless(DemoToken::unlocked(), 403, 'Enter the demo token to approve.');

        app(RunPipeline::class)->approve($this->run->refresh());
    }

    public function render(WorkerClient $worker, RunPipeline $pipeline)
    {
        $run = $this->run->refresh();
        $report = new RunReport($run);

        // Short cache so many open dashboards do not hammer the worker through its tunnel.
        $health = Cache::remember('worker.health', 10, fn () => ['body' => $worker->health()])['body'];

        $trainSets = $report->datasets->where('purpose', 'train')->sortBy('iteration');

        return view('livewire.run-dashboard', [
            'report' => $report,
            'health' => $health,
            'stages' => $report->stages($pipeline->progress($run)),
            'evalSample' => RunReport::sampleDataset($report->evalDataset()),
            'trainSet' => $trainSets->last(),
            'trainSample' => RunReport::sampleDataset($trainSets->last()),
            'unlocked' => DemoToken::unlocked(),
        ])->layout('components.layouts.app', ['title' => $run->name]);
    }
}
