<?php

namespace Tests\Feature;

use App\Livewire\CreateRun;
use App\Livewire\Playground;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\RunsPipeline;
use Tests\TestCase;

class ReadonlyDemoTest extends TestCase
{
    use RefreshDatabase, RunsPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPipeline();
    }

    public function test_readonly_demo_starts_no_runs_and_says_what_serves_the_playground(): void
    {
        config(['chirak.readonly' => true, 'chirak.playground_note' => 'CPU build of iteration 2 (GGUF q8_0).']);
        $this->post('/unlock', ['demo_token' => 'demo-secret']);

        Livewire::test(CreateRun::class)->call('start')->assertHasErrors('token');
        $this->assertSame(0, Run::count());
        $this->get('/')->assertDontSeeText('New run');

        $this->worker->iterations = [1 => 0.95];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));
        $this->get("/runs/{$run->slug}/playground")->assertSeeText('CPU build of iteration 2 (GGUF q8_0).');

        $this->get("/runs/{$run->slug}/tickets")->assertSeeText('own tickets cannot be uploaded here');
        Livewire::test(\App\Livewire\OwnTickets::class, ['run' => $run])->set('paste', 'a')->call('uploadTickets')->assertForbidden();
        $this->assertSame(0, $run->ownItems()->count());
    }

    public function test_playground_is_rate_limited_per_address(): void
    {
        config(['chirak.playground_per_minute' => 2]);
        $this->worker->iterations = [1 => 0.95];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));
        $this->post('/unlock', ['demo_token' => 'demo-secret']);

        $c = Livewire::test(Playground::class, ['run' => $run])->set('input', 'Where is my parcel?');
        $c->call('predict')->assertSet('notice', null);
        $c->call('predict')->assertSet('notice', null);
        $c->call('predict');
        $this->assertStringStartsWith('Too many requests', $c->get('notice'));
        $this->assertCount(4, $this->worker->sent('POST', '/predict'));
    }

    public function test_a_public_playground_works_without_the_token_within_a_shared_budget(): void
    {
        $this->worker->iterations = [1 => 0.95];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));

        $locked = Livewire::test(Playground::class, ['run' => $run])->set('input', 'Where is my parcel?');
        $locked->call('predict')->assertSet('notice', 'Enter the demo token (top right) to use the playground.');
        $this->get("/runs/{$run->slug}/playground")->assertSeeText('Enter the demo token (top right) to run predictions.');
        $this->assertCount(0, $this->worker->sent('POST', '/predict'));

        config(['chirak.playground_public' => true, 'chirak.playground_public_per_minute' => 2]);
        $this->get("/runs/{$run->slug}/playground")->assertDontSeeText('Enter the demo token (top right) to run predictions.');
        $c = Livewire::test(Playground::class, ['run' => $run])->set('input', 'Where is my parcel?');
        $c->call('predict')->assertSet('notice', null)->assertSet('base.valid', true);
        $c->call('predict')->assertSet('notice', null);
        $c->call('predict');
        $this->assertStringStartsWith('The demo is busy', $c->get('notice'));
        $this->assertCount(4, $this->worker->sent('POST', '/predict'));

        // the token holder is not held back by the visitors' shared budget
        $this->post('/unlock', ['demo_token' => 'demo-secret']);
        Livewire::test(Playground::class, ['run' => $run])->set('input', 'Where is my parcel?')->call('predict')->assertSet('notice', null);
        $this->assertCount(6, $this->worker->sent('POST', '/predict'));
    }

    public function test_a_request_refused_for_its_address_does_not_use_the_shared_budget(): void
    {
        config(['chirak.playground_public' => true, 'chirak.playground_per_minute' => 1, 'chirak.playground_public_per_minute' => 2]);
        $this->worker->iterations = [1 => 0.95];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));

        $one = Livewire::test(Playground::class, ['run' => $run])->set('input', 'Where is my parcel?');
        $one->call('predict')->assertSet('notice', null);
        $one->call('predict');
        $this->assertStringStartsWith('Too many requests', $one->get('notice'));

        // another visitor: the refused request left one of the two shared compares, the next one finds none
        RateLimiter::clear('playground:127.0.0.1');
        Livewire::test(Playground::class, ['run' => $run])->set('input', 'Where is my parcel?')->call('predict')->assertSet('notice', null);
        RateLimiter::clear('playground:127.0.0.1');
        $last = Livewire::test(Playground::class, ['run' => $run])->set('input', 'Where is my parcel?')->call('predict');
        $this->assertStringStartsWith('The demo is busy', $last->get('notice'));
        $this->assertCount(4, $this->worker->sent('POST', '/predict'));
    }
}
