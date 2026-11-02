<?php

namespace Tests\Feature;

use App\Models\Run;
use App\Services\Pipeline\RunPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeWorker;
use Tests\Support\RunsPipeline;
use Tests\TestCase;

class RunPipelineTest extends TestCase
{
    use RefreshDatabase, RunsPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPipeline();
    }

    public function test_full_loop_with_review_pause_runs_to_completion(): void
    {
        $this->worker->iterations = [1 => 0.60, 2 => 0.70, 3 => 0.72];
        $run = $this->startAndDrain($this->makeRun());

        // eval -> baseline -> train generation -> pause for the human checkpoint
        $this->assertSame(Run::AWAITING_REVIEW, $run->status);
        $this->assertSame('awaiting_review', $run->stage);
        $this->assertTrue($run->datasets()->where('purpose', 'eval')->exists());
        $this->assertTrue($run->datasets()->where('purpose', 'train')->where('iteration', 1)->exists());
        $this->assertCount(0, $this->worker->sent('POST', '/train'));

        app(RunPipeline::class)->approve($run);
        $this->drainQueue();
        $run->refresh();

        $this->assertSame(Run::COMPLETED, $run->status, (string) $run->error);
        $this->assertCount(3, $run->iterations);
        $this->assertCount(4, $run->evaluations);
        $this->assertCount(3, $run->decisions);

        // iteration 3 is the third one: max_iterations rule, with Claude not consulted
        $last = $run->decisions()->where('iteration', 3)->first();
        $this->assertSame('max_iterations', $last->stop_rule['rule']);
        $this->assertNull($last->orchestrator_output);

        // iterations 1 and 2 were analysed by Claude and fed the next generation
        $first = $run->decisions()->where('iteration', 1)->first();
        $this->assertSame('claude', $first->orchestrator_output['used']);
        $this->assertSame('returns', $first->next_slices[0]['name']);
        // generates[0]=eval, [1]=dev, [2]=train-1, [3]=train-2
        $secondTrain = $this->worker->sent('POST', '/generate')[3]['body'];
        $this->assertSame(2, $secondTrain['iteration']);
        $this->assertSame($first->next_slices, $secondTrain['target_slices']);
        $this->assertSame(500, $secondTrain['n']);

        // train files are appended
        $trains = $this->worker->sent('POST', '/train');
        $this->assertCount(1, $trains[0]['body']['train_files']);
        $this->assertCount(3, $trains[2]['body']['train_files']);

        // best adapter (iteration 3, 0.72) is served and archived locally
        $this->assertSame(3, $run->best_iteration);
        $this->assertSame("{$run->slug}-iter-3", $run->served_name);
        $this->assertSame($run->served_name, $this->worker->sent('POST', '/serve')[0]['body']['name']);
        Storage::disk('runs')->assertExists("{$run->slug}/iter-1/adapter.tar.gz");
        Storage::disk('runs')->assertExists("{$run->slug}/eval.jsonl");
        Storage::disk('runs')->assertExists("{$run->slug}/train-2.jsonl");
        $this->assertSame("{$run->slug}/iter-3/adapter.tar.gz", $run->iterations()->where('n', 3)->value('adapter_archive_local'));
    }

    public function test_loop_stops_early_when_target_is_met(): void
    {
        $this->worker->iterations = [1 => 0.60, 2 => 0.90];
        $run = $this->makeRun(['require_review' => false]);
        $run = $this->startAndDrain($run);

        $this->assertSame(Run::COMPLETED, $run->status, (string) $run->error);
        $this->assertCount(2, $run->iterations);
        $this->assertSame(2, $run->best_iteration);
        $this->assertStringContainsString('target met', $run->stop_reason);
    }

    public function test_loop_stops_when_gain_is_below_one_point(): void
    {
        $this->worker->iterations = [1 => 0.60, 2 => 0.605];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));

        $this->assertSame(Run::COMPLETED, $run->status);
        $this->assertCount(2, $run->iterations);
        $this->assertSame('no_progress', $run->decisions()->where('iteration', 2)->first()->stop_rule['rule']);
        $this->assertSame(2, $run->best_iteration);
    }

    public function test_best_adapter_is_served_even_when_the_last_one_regressed(): void
    {
        $this->worker->iterations = [1 => 0.70, 2 => 0.65];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));

        $this->assertSame(1, $run->best_iteration);
    }

    public function test_claude_stop_is_logged_but_does_not_end_the_loop(): void
    {
        $this->worker->claude = ['stop_true'];
        $this->worker->iterations = [1 => 0.60, 2 => 0.70, 3 => 0.80];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));

        $this->assertCount(3, $run->iterations);
        $this->assertTrue($run->decisions()->where('iteration', 1)->first()->orchestrator_output['claude_stop']);
    }

    public function test_baseline_that_already_meets_target_is_not_worth_it(): void
    {
        $this->worker->baseline = 0.90;
        $run = $this->startAndDrain($this->makeRun());

        $this->assertSame(Run::NOT_WORTH_IT, $run->status);
        $this->assertSame('baseline already meets target', $run->stop_reason);
        $this->assertCount(0, $this->worker->sent('POST', '/train'));
        // eval + dev both generated before the baseline check
        $this->assertCount(2, $this->worker->sent('POST', '/generate'));
    }

    public function test_leakage_above_thirty_percent_on_first_iteration_stops_the_run(): void
    {
        $this->worker->leakage = [1 => 0.35];
        $run = $this->startAndDrain($this->makeRun());

        $this->assertSame(Run::FAILED, $run->status);
        $this->assertSame('leakage drop rate above 30%', $run->stop_reason);
        $this->assertCount(0, $this->worker->sent('POST', '/train'));
    }

    public function test_leakage_on_a_later_iteration_completes_with_the_best_adapter_so_far(): void
    {
        $this->worker->leakage = [1 => 0.10, 2 => 0.45];
        $this->worker->iterations = [1 => 0.60];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));

        $this->assertSame(Run::COMPLETED, $run->status);
        $this->assertSame('leakage drop rate above 30%', $run->stop_reason);
        $this->assertSame(1, $run->best_iteration);
        $this->assertCount(1, $this->worker->sent('POST', '/train'));
    }

    public function test_baseline_evaluation_has_no_adapter_path_and_iterations_do(): void
    {
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));

        $baseline = $run->evaluations()->whereNull('iteration')->first();
        $this->assertNotNull($baseline);
        $this->assertNull($baseline->adapter_path);
        $this->assertSame('base', $this->worker->sent('POST', '/evaluate')[0]['body']['model']);

        $iter1 = $run->evaluations()->where('iteration', 1)->first();
        $this->assertSame("/data/runs/{$run->slug}/iter-1/adapter", $iter1->adapter_path);
        $this->assertArrayNotHasKey('failures', $iter1->metrics);
        $this->assertCount(1, $iter1->failures);
    }

    public function test_eval_set_is_frozen_with_hash_and_every_evaluation_must_use_it(): void
    {
        $run = $this->startAndDrain($this->makeRun());

        $eval = $run->datasets()->where('purpose', 'eval')->first();
        $this->assertSame(FakeWorker::SHA, $eval->sha256);
        $this->assertNotNull($eval->frozen_at);
        $this->assertSame($eval->sha256, $run->evaluations()->whereNull('iteration')->value('eval_sha256'));
    }

    public function test_changed_eval_hash_fails_the_run(): void
    {
        $run = $this->makeRun();
        $this->worker->install();
        app(RunPipeline::class)->start($run);
        $this->drainQueue(2); // generate_eval submitted, result stored
        $run->datasets()->where('purpose', 'eval')->update(['sha256' => str_repeat('b', 64)]);
        $this->drainQueue();

        $this->assertSame(Run::FAILED, $run->refresh()->status);
        $this->assertStringContainsString('eval set hash changed', $run->error);
    }

    public function test_holdout_examples_never_reach_the_worker_or_claude(): void
    {
        $run = $this->makeRun(['require_review' => false]);
        $this->startAndDrain($run);

        $this->assertNotEmpty($this->worker->requests);
        $outgoing = json_encode($this->worker->requests, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($run->holdout_examples as $example) {
            $this->assertStringNotContainsString(json_encode($example['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $outgoing);
        }
        // seeds are sent to eval and dev generation; train generation gets none
        $generates = $this->worker->sent('POST', '/generate');
        $this->assertSame($run->seed_examples, $generates[0]['body']['seed_examples']); // eval
        $this->assertSame($run->seed_examples, $generates[1]['body']['seed_examples']); // dev
        $this->assertSame([], $generates[2]['body']['seed_examples']); // train-1
    }

    public function test_train_generation_avoids_eval_and_dev_files(): void
    {
        $run = $this->startAndDrain($this->makeRun());

        // generates[0]=eval, generates[1]=dev, generates[2]=first train
        $train = $this->worker->sent('POST', '/generate')[2]['body'];
        $this->assertSame('train', $train['purpose']);
        $this->assertSame([
            "/data/runs/{$run->slug}/eval.jsonl",
            "/data/runs/{$run->slug}/dev.jsonl",
        ], $train['avoid_near']);
        $this->assertSame(1000, $train['n']);
        $this->assertArrayNotHasKey('target_slices', $train);
    }

    public function test_worker_offline_waits_and_resumes_without_failing(): void
    {
        $run = $this->makeRun(['require_review' => false]);
        $this->worker->offline = true;

        $run = $this->startAndDrain($run, 3);
        $this->assertSame(Run::WAITING_FOR_WORKER, $run->status);
        $this->assertStringContainsString('unreachable', $run->error);

        $this->worker->offline = false;
        $this->drainQueue();

        $run->refresh();
        $this->assertSame(Run::COMPLETED, $run->status, (string) $run->error);
        $this->assertNull($run->error);
    }

    public function test_worker_going_offline_mid_job_resumes_polling_the_same_job(): void
    {
        $run = $this->makeRun(['require_review' => false]);
        app(RunPipeline::class)->start($run);
        $this->drainQueue(1); // submits generate_eval
        $jobId = $run->refresh()->current_worker_job_id;
        $this->assertNotNull($jobId);

        $this->worker->offline = true;
        $this->drainQueue(1);
        $this->assertSame(Run::WAITING_FOR_WORKER, $run->refresh()->status);
        $this->assertSame($jobId, $run->current_worker_job_id);

        $this->worker->offline = false;
        $this->drainQueue();
        $this->assertSame(Run::COMPLETED, $run->refresh()->status);
        $this->assertSame(1, count(array_filter($this->worker->sent('POST', '/generate'), fn ($r) => $r['body']['purpose'] === 'eval')));
    }

    public function test_a_gpu_busy_with_another_run_makes_the_run_wait_instead_of_failing(): void
    {
        $run = $this->makeRun(['require_review' => false]);
        $this->worker->busyWith = 'generate';

        $run = $this->startAndDrain($run, 3);
        $this->assertSame(Run::WAITING_FOR_WORKER, $run->status);
        $this->assertSame('GPU is busy with a generate job', $run->error);

        $this->worker->busyWith = null;
        $this->drainQueue();
        $run->refresh();
        $this->assertSame(Run::COMPLETED, $run->status, (string) $run->error);
        $this->assertNull($run->error);
    }

    public function test_worker_job_failure_fails_the_run_with_the_worker_error(): void
    {
        $this->worker->failNextJob = 'worker restarted';
        $run = $this->startAndDrain($this->makeRun());

        $this->assertSame(Run::FAILED, $run->status);
        $this->assertStringContainsString('worker restarted', $run->error);
        $this->assertNull($run->current_worker_job_id);
    }

    public function test_previous_decisions_carry_dev_delta_metrics(): void
    {
        // iter-2 score (0.70) is below the target (0.90) so analysis runs and orchestrator_input is set
        $this->worker->iterations = [1 => 0.60, 2 => 0.70, 3 => 0.90];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));

        // Decision at iter-2 didn't stop (target not met yet), so orchestrator_input is populated.
        $decision2 = $run->decisions()->where('iteration', 2)->first();
        $this->assertNotNull($decision2->orchestrator_input);

        $content = json_decode($decision2->orchestrator_input['messages'][0]['content'], true);
        $prev = $content['previous_decisions'][0];
        $this->assertArrayHasKey('delta_metrics', $prev);
        $this->assertArrayHasKey('overall', $prev['delta_metrics']);
        $this->assertArrayHasKey('per_class', $prev['delta_metrics']);
    }

    public function test_next_step_is_derived_from_the_database_only(): void
    {
        $run = $this->makeRun();
        $pipeline = app(RunPipeline::class);

        $this->assertSame('generate_eval', $pipeline->nextStep($run)['step']);

        $this->startAndDrain($run);
        $this->assertSame(['step' => 'await_review', 'iteration' => 1], $pipeline->nextStep($run->refresh()));

        $run->update(['review_approved_at' => now()]);
        $this->assertSame(['step' => 'train', 'iteration' => 1], $pipeline->nextStep($run));
    }
}
