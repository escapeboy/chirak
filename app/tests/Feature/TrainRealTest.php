<?php

namespace Tests\Feature;

use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsPipeline;
use Tests\TestCase;

class TrainRealTest extends TestCase
{
    use RefreshDatabase, RunsPipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPipeline();
    }

    private function file(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'train-real');
        file_put_contents($path, collect($rows)->map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE))->implode("\n")."\n");

        return $path;
    }

    public function test_real_tickets_go_into_every_training_step(): void
    {
        $this->worker->iterations = [1 => 0.80, 2 => 0.90];
        $run = $this->makeRun(['require_review' => false]);
        $file = $this->file([
            ['input' => 'I need to update the shipping address on my order.', 'output' => ['category' => 'shipping', 'urgency' => 'normal', 'needs_human' => false], 'source' => 'abcd'],
            ['input' => 'My cart is not updating.', 'output' => ['category' => 'other', 'urgency' => 'normal', 'needs_human' => false], 'source' => 'abcd'],
        ]);

        $this->artisan('chirak:attach-train-real', ['slug' => $run->slug, 'file' => $file, '--source' => 'ABCD train'])->assertSuccessful();
        $run = $this->startAndDrain($run);

        $this->assertSame(Run::COMPLETED, $run->status);
        $dataset = $run->datasets()->where('purpose', 'train-real')->sole();
        $this->assertSame([2, 'ABCD train', ['other' => 1, 'shipping' => 1]], [$dataset->n, $dataset->stats['source'], $dataset->stats['by_category']]);
        $trains = array_column($this->worker->sent('POST', '/train'), 'body');
        $this->assertCount(2, $trains);
        foreach ($trains as $i => $train) {
            $this->assertSame("/data/runs/{$run->slug}/train-real.jsonl", end($train['train_files']));
            $this->assertCount($i + 2, $train['train_files']);
        }
    }

    public function test_refused_once_training_started_or_rows_are_invalid(): void
    {
        $this->worker->iterations = [1 => 0.95];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));
        $ok = $this->file([['input' => 'Where is my parcel?', 'output' => ['category' => 'shipping', 'urgency' => 'normal', 'needs_human' => false]]]);
        $this->artisan('chirak:attach-train-real', ['slug' => $run->slug, 'file' => $ok])->assertFailed();

        $fresh = $this->makeRun();
        $bad = $this->file([['input' => 'x', 'output' => ['category' => 'nope', 'urgency' => 'normal', 'needs_human' => false]]]);
        $this->artisan('chirak:attach-train-real', ['slug' => $fresh->slug, 'file' => $bad])->assertFailed();
        $this->assertSame(0, $fresh->datasets()->count());
    }
}
