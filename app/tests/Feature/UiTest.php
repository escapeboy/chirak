<?php

namespace Tests\Feature;

use App\Livewire\CreateRun;
use App\Livewire\Playground;
use App\Livewire\RunDashboard;
use App\Models\Run;
use App\Services\Pipeline\RunPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\RunsPipeline;
use Tests\TestCase;

class UiTest extends TestCase
{
    use RefreshDatabase, RunsPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPipeline();
    }

    private function unlocked(): self
    {
        return $this->withSession(['demo_token_ok' => true]);
    }

    private function finishedRun(): Run
    {
        $this->worker->iterations = [1 => 0.60, 2 => 0.90];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));
        $this->assertSame(Run::COMPLETED, $run->status);

        return $run;
    }

    public function test_home_lists_runs(): void
    {
        $run = $this->finishedRun();

        $this->get('/')->assertOk()->assertSee($run->name)->assertSee('completed');
    }

    public function test_public_report_renders_from_the_database_with_the_worker_offline(): void
    {
        $run = $this->finishedRun();
        $this->worker->offline = true;
        $requestsBefore = count($this->worker->requests);

        $this->get("/r/{$run->slug}")
            ->assertOk()
            ->assertSee('50.0%')                 // baseline macro-F1
            ->assertSee('90.0%')                 // final macro-F1
            ->assertSee('Iteration 2')
            ->assertSee(\Tests\Support\FakeWorker::SHA)
            ->assertSee('returns confused with payments')
            ->assertSee('target met');

        $this->assertSame($requestsBefore, count($this->worker->requests), 'the report must not call the worker');
    }

    public function test_report_shows_dashes_instead_of_inventing_numbers(): void
    {
        $run = $this->makeRun();

        $this->get("/r/{$run->slug}")->assertOk()
            ->assertSee('No evaluation has finished yet.')
            ->assertSee('The eval set has not been generated yet.')
            ->assertDontSee('NaN');
    }

    public function test_dashboard_renders_with_worker_offline_and_shows_stored_data(): void
    {
        $run = $this->finishedRun();
        $this->worker->offline = true;

        Livewire::test(RunDashboard::class, ['run' => $run])
            ->assertOk()
            ->assertSee('GPU worker offline')
            ->assertSee('Baseline vs iterations')
            ->assertSee('Download adapter.tar.gz')
            ->assertSee('Stop rules: stop');
    }

    public function test_dashboard_shows_worker_online_badge(): void
    {
        $run = $this->makeRun();
        \Illuminate\Support\Facades\Cache::flush();

        Livewire::test(RunDashboard::class, ['run' => $run])->assertSee('GPU worker online');
    }

    public function test_review_checkpoint_shows_samples_and_approve_needs_the_token(): void
    {
        $run = $this->startAndDrain($this->makeRun());
        $this->assertSame(Run::AWAITING_REVIEW, $run->status);

        Livewire::test(RunDashboard::class, ['run' => $run])
            ->assertSee('Human checkpoint')
            ->assertSee('Approve and train')
            ->call('approve')
            ->assertForbidden();
        $this->assertSame(Run::AWAITING_REVIEW, $run->refresh()->status);

        $this->unlocked();
        session(['demo_token_ok' => true]);
        Livewire::test(RunDashboard::class, ['run' => $run])->call('approve');

        $this->assertSame(Run::RUNNING, $run->refresh()->status);
        $this->assertNotNull($run->review_approved_at);
    }

    public function test_dataset_preview_reads_the_local_copy(): void
    {
        $run = $this->startAndDrain($this->makeRun());
        \Illuminate\Support\Facades\Storage::disk('runs')->put("{$run->slug}/eval.jsonl", json_encode(['input' => 'Zebra ticket text', 'output' => ['category' => 'other'], 'source' => 'synthetic'])."\n");

        Livewire::test(RunDashboard::class, ['run' => $run])->assertSee('Zebra ticket text');
    }

    public function test_adapter_download_is_served_from_local_storage(): void
    {
        $run = $this->finishedRun();

        $this->get("/runs/{$run->slug}/adapters/1")->assertOk()->assertDownload("{$run->slug}-iter-1-adapter.tar.gz");
        $this->get("/runs/{$run->slug}/adapters/3")->assertNotFound();
    }

    public function test_unlock_accepts_only_the_configured_token(): void
    {
        $this->post('/unlock', ['demo_token' => 'wrong'])->assertSessionHas('unlock_error');
        $this->assertFalse(session('demo_token_ok', false));

        $this->post('/unlock', ['demo_token' => 'demo-secret']);
        $this->assertTrue(session('demo_token_ok'));
    }

    public function test_unlock_fails_closed_without_a_configured_token(): void
    {
        config(['chirak.demo_token' => '']);

        $this->post('/unlock', ['demo_token' => ''])->assertSessionHas('unlock_error');
        $this->assertFalse(session('demo_token_ok', false));
    }

    public function test_starting_a_run_requires_the_demo_token(): void
    {
        Livewire::test(CreateRun::class)->call('start')->assertHasErrors('token');

        $this->assertSame(0, Run::count());
        Queue::assertNothingPushed();
    }

    public function test_starting_a_run_creates_it_and_queues_the_pipeline(): void
    {
        session(['demo_token_ok' => true]);

        Livewire::test(CreateRun::class)->call('start')->assertHasNoErrors()->assertRedirect();

        $run = Run::firstOrFail();
        $this->assertSame(Run::RUNNING, $run->status);
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]{2,63}$/', $run->slug);
        $this->assertCount(6, $run->seed_examples);
        $this->assertCount(6, $run->holdout_examples);
        Queue::assertPushed(\App\Jobs\AdvanceRun::class);
    }

    public function test_start_is_rate_limited_to_three_per_hour_per_ip(): void
    {
        session(['demo_token_ok' => true]);
        RateLimiter::clear('start-run:127.0.0.1');

        foreach (range(1, 3) as $i) {
            Livewire::test(CreateRun::class)->call('start')->assertHasNoErrors();
        }
        Livewire::test(CreateRun::class)->call('start')->assertHasErrors('token');

        $this->assertSame(3, Run::count());
    }

    public function test_invalid_examples_and_fields_are_rejected_without_consuming_the_rate_limit(): void
    {
        session(['demo_token_ok' => true]);
        RateLimiter::clear('start-run:127.0.0.1');

        Livewire::test(CreateRun::class)
            ->set('seeds', '{"input":"x","output":{"category":"nonsense","urgency":"low","needs_human":false}}')
            ->call('start')->assertHasErrors('seeds');

        Livewire::test(CreateRun::class)
            ->set('fields', '[{"name":"a","type":"bool"}]')
            ->call('start')->assertHasErrors('fields');

        Livewire::test(CreateRun::class)
            ->set('holdout', \App\Support\TaskTemplate::toJsonl([\App\Support\TaskTemplate::seedExamples()[0]]))
            ->call('start')->assertHasErrors('holdout');

        $this->assertSame(0, Run::count());
        $this->assertFalse(RateLimiter::tooManyAttempts('start-run:127.0.0.1', 1));
    }

    public function test_playground_compares_base_and_adapter(): void
    {
        $run = $this->finishedRun();
        session(['demo_token_ok' => true]);

        Livewire::test(Playground::class, ['run' => $run])
            ->call('useHoldout', 1)
            ->assertSet('input', $run->holdout_examples[1]['input'])
            ->call('predict')
            ->assertSee('Parsed');

        $predicts = $this->worker->sent('POST', '/predict');
        $this->assertSame(['base', $run->served_name], array_map(fn ($r) => $r['body']['model'], $predicts));
    }

    public function test_playground_offline_shows_a_message_and_stored_evaluation_results(): void
    {
        $run = $this->finishedRun();
        session(['demo_token_ok' => true]);
        $this->worker->offline = true;

        Livewire::test(Playground::class, ['run' => $run])
            ->set('input', 'hello')
            ->call('predict')
            ->assertSee('GPU worker is offline')
            ->assertSee('Failing ticket');
    }

    public function test_playground_needs_the_demo_token(): void
    {
        $run = $this->finishedRun();

        Livewire::test(Playground::class, ['run' => $run])->set('input', 'hello')->call('predict')
            ->assertSee('Enter the demo token');
        $this->assertCount(0, $this->worker->sent('POST', '/predict'));
    }
}
