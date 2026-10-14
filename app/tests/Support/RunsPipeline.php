<?php

namespace Tests\Support;

use App\Jobs\AdvanceRun;
use App\Jobs\EvaluateOwnSet;
use App\Jobs\LabelOwnItem;
use App\Jobs\PollWorkerJob;
use App\Models\Run;
use App\Services\Pipeline\RunPipeline;
use App\Support\TaskTemplate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

trait RunsPipeline
{
    protected FakeWorker $worker;

    private array $ranJobs = [];

    protected function setUpPipeline(): void
    {
        config([
            'chirak.worker.token' => 'secret-worker-token',
            'chirak.orchestrator.api_key' => 'test-key',
            'chirak.demo_token' => 'demo-secret',
        ]);
        Storage::fake('runs');
        Queue::fake();
        $this->worker = (new FakeWorker)->install();
    }

    protected function makeRun(array $overrides = []): Run
    {
        return Run::create($overrides + [
            'slug' => 'demo-'.str()->lower(str()->random(5)),
            'name' => 'Demo',
            'task_spec' => TaskTemplate::merchantTickets(),
            'seed_examples' => TaskTemplate::seedExamples(),
            'holdout_examples' => TaskTemplate::holdoutExamples(),
            'target_metric' => 'macro_f1',
            'target_value' => 0.85,
            'eval_n' => 200,
            'train_n' => 1000,
            'require_review' => true,
        ]);
    }

    /** Runs queued jobs one by one, ignoring delays, until none is left (or $max ran). */
    protected function drainQueue(int $max = 100): int
    {
        for ($ran = 0; $ran < $max; $ran++) {
            $next = null;
            foreach ([AdvanceRun::class, PollWorkerJob::class, LabelOwnItem::class, EvaluateOwnSet::class] as $class) {
                foreach (Queue::pushedJobs()[$class] ?? [] as $entry) {
                    if (! in_array(spl_object_id($entry['job']), $this->ranJobs, true)) {
                        $next = $entry['job'];
                        break 2;
                    }
                }
            }

            if (! $next) {
                return $ran;
            }

            $this->ranJobs[] = spl_object_id($next);
            app()->call([$next, 'handle']);
        }

        return $ran;
    }

    protected function startAndDrain(Run $run, int $max = 100): Run
    {
        app(RunPipeline::class)->start($run);
        $this->drainQueue($max);

        return $run->refresh();
    }
}
