<?php

namespace Tests\Feature;

use App\Models\Iteration;
use App\Models\Run;
use App\Services\Own\OwnTickets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\RunsPipeline;
use Tests\TestCase;

class ImportOwnLabelledTest extends TestCase
{
    use RefreshDatabase, RunsPipeline;

    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPipeline();
        Storage::fake('local');
        Sleep::fake();
        $this->run = $this->makeRun(['require_review' => false, 'served_name' => 'demo-iter-1', 'best_iteration' => 1, 'status' => Run::COMPLETED]);
        Iteration::create(['run_id' => $this->run->id, 'n' => 1, 'adapter_path' => "/data/runs/{$this->run->slug}/iter-1/adapter", 'finished_at' => now()]);
    }

    private function row(int $i, string $split, string $category = 'shipping'): array
    {
        return ['input' => "please check parcel ref$i again because the courier never came",
            'output' => ['category' => $category, 'urgency' => 'normal', 'needs_human' => false], 'split' => $split];
    }

    private function file(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'own');
        file_put_contents($path, implode("\n", array_map(fn ($r) => json_encode($r), $rows))."\n");

        return $path;
    }

    private function import(array $rows): int
    {
        return $this->artisan('chirak:import-own-labelled', ['slug' => $this->run->slug, 'file' => $this->file($rows), '--origin' => 'people at the source'])->run();
    }

    public function test_labelled_tickets_are_frozen_without_the_judge_and_split_as_given(): void
    {
        $rows = array_merge(array_map(fn ($i) => $this->row($i, 'eval', 'returns'), range(1, 6)), array_map(fn ($i) => $this->row($i, 'train'), range(7, 10)));
        $rows[9]['input'] = 'urgent: '.$rows[0]['input']; // shares an 8-gram with an eval ticket

        $this->worker->labeler = fn () => $this->fail('the judge must not be asked');
        $this->assertSame(0, $this->import($rows));

        $eval = $this->run->datasets()->where('purpose', 'eval-own')->first();
        $this->assertSame(6, $eval->n);
        $this->assertSame(6, $eval->stats['label_sources']['dataset']);
        $this->assertSame('people at the source', $eval->stats['label_origin']);
        $this->assertSame(['share' => 40, 'n' => 3, 'overlap_dropped' => 1], $eval->stats['train']);
        $this->assertSame(['returns'], array_values(array_unique(array_map(fn ($l) => json_decode($l, true)['output']['category'],
            array_filter(explode("\n", Storage::disk('runs')->get("{$this->run->slug}/eval-own.jsonl")))))));
        $this->assertSame(3, $this->run->datasets()->where('purpose', 'train-own')->value('n'));

        $this->drainQueue();
        $this->assertSame(2, $this->run->ownEvaluations()->count(), 'base and the served adapter are scored');
        $this->get(route('runs.report', $this->run))->assertOk()->assertSee('uploaded with their labels: people at the source', false);
    }

    public function test_a_follow_up_run_trains_on_the_imported_training_part(): void
    {
        $this->import(array_merge(array_map(fn ($i) => $this->row($i, 'eval'), range(1, 4)), array_map(fn ($i) => $this->row($i, 'train'), range(5, 8))));

        $follow = app(OwnTickets::class)->trainNewRun($this->run->refresh());

        $real = $follow->datasets()->where('purpose', 'train-real')->first();
        $this->assertSame(4, $real->n);
        $this->assertSame('people at the source', $follow->datasets()->where('purpose', 'eval-own')->first()->stats['label_origin']);
    }

    public function test_the_demo_export_leaves_out_every_copy_of_the_own_tickets(): void
    {
        $this->import(array_merge(array_map(fn ($i) => $this->row($i, 'eval'), range(1, 4)), array_map(fn ($i) => $this->row($i, 'train'), range(5, 8))));
        $follow = app(OwnTickets::class)->trainNewRun($this->run->refresh());
        Storage::disk('runs')->put("{$this->run->slug}/eval.jsonl", '{}');

        $dir = sys_get_temp_dir().'/chirak-export-'.uniqid();
        $this->artisan('chirak:export-demo', ['dir' => $dir, 'slugs' => [$this->run->slug, $follow->slug]])->assertSuccessful();

        $files = array_map(fn ($f) => substr($f, strlen("$dir/storage/app/runs/")), \Illuminate\Support\Facades\File::allFiles("$dir/storage/app/runs"));
        $this->assertSame(["{$this->run->slug}/eval.jsonl"], array_values(array_map('strval', $files)));
        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }

    public function test_bad_rows_and_a_second_upload_are_refused(): void
    {
        $this->assertSame(1, $this->import([$this->row(1, 'test')]));
        $this->assertSame(1, $this->import([['split' => 'eval'] + $this->row(1, 'eval', 'refunds')]));
        $this->assertSame(1, $this->import([$this->row(1, 'train')]), 'nothing to evaluate');
        $this->assertFalse($this->run->ownSet()->exists());

        $this->assertSame(0, $this->import([$this->row(1, 'eval')]));
        $this->assertSame(1, $this->import([$this->row(2, 'eval')]));
        $this->assertSame(1, $this->run->ownItems()->count());
    }
}
