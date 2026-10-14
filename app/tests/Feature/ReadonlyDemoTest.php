<?php

namespace Tests\Feature;

use App\Livewire\CreateRun;
use App\Livewire\Playground;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
