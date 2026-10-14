<?php

namespace Tests\Feature;

use App\Livewire\OwnTickets;
use App\Models\OwnItem;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\RunsPipeline;
use Tests\TestCase;

class OwnTrainingTest extends TestCase
{
    use RefreshDatabase, RunsPipeline;

    private const OK = '{"category":"shipping","urgency":"normal","needs_human":false}';

    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPipeline();
        Storage::fake('local');
        Sleep::fake();
        $this->run = $this->makeRun(['require_review' => false, 'served_name' => 'demo-iter-1', 'best_iteration' => 1, 'status' => Run::COMPLETED]);
        $this->worker->labeler = fn () => self::OK;
        $this->withSession(['demo_token_ok' => true]);
    }

    /** Ten words with the variable one in the middle, so no two tickets share a word 8-gram. */
    private function ticket(int $i): string
    {
        return "please check parcel ref$i again because the courier never came";
    }

    private function csv(int $n = 100): string
    {
        return "text\n".implode("\n", array_map(fn ($i) => '"'.$this->ticket($i).'"', range(1, $n)));
    }

    private function upload(int $share, bool $drain = true)
    {
        $page = Livewire::test(OwnTickets::class, ['run' => $this->run])->set('paste', $this->csv())->set('trainShare', $share)->call('uploadTickets');
        $drain && $this->drainQueue(1000);

        return $page;
    }

    private function frozen(int $share = 30): void
    {
        $this->upload($share, false);
        $train = $this->run->ownItems()->where('split', 'train')->orderBy('position')->get();
        $eval = $this->run->ownItems()->where('split', 'eval')->orderBy('position')->get();
        $train[0]->update(['text' => $eval[0]->text]); // the same ticket
        $train[1]->update(['text' => 'Hello, '.mb_strtoupper($eval[1]->text).'!!']); // same words, other case and punctuation
        $train[2]->update(['text' => 'urgent: '.$eval[2]->text.' thanks']); // shares 8-grams
        $this->drainQueue(1000);
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('freeze');
        $this->drainQueue();
    }

    private function body(string $name): array
    {
        return array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", Storage::disk('runs')->get("{$this->run->slug}/$name"))));
    }

    public function test_the_split_is_made_at_upload_before_labelling_and_is_deterministic(): void
    {
        $this->upload(30, false);

        $this->assertSame([30, 70], [$this->run->ownItems()->where('split', 'train')->count(), $this->run->ownItems()->where('split', 'eval')->count()]);
        $this->assertSame(0, $this->run->ownItems()->where('status', '!=', 'new')->count(), 'nothing is labelled yet');
        $this->assertSame(30, $this->run->ownSet()->first()->train_share);
        $first = $this->run->ownItems()->where('split', 'train')->pluck('position')->all();

        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('deleteItems');
        $this->upload(30, false);
        $this->assertSame($first, $this->run->ownItems()->where('split', 'train')->pluck('position')->all());
        $this->assertNotSame(range(1, 30), $first, 'a shuffle, not the first rows');
    }

    public function test_each_share_gives_its_count_and_other_shares_are_refused(): void
    {
        foreach ([0 => 0, 20 => 20, 50 => 50] as $share => $n) {
            $this->upload($share, false);
            $this->assertSame($n, $this->run->ownItems()->where('split', 'train')->count(), "$share%");
            Livewire::test(OwnTickets::class, ['run' => $this->run])->call('deleteItems');
        }

        $this->upload(40, false)->assertSee('must be one of');
        $this->assertNull($this->run->ownSet()->first());
    }

    public function test_without_a_share_nothing_goes_to_training(): void
    {
        $this->upload(0);
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('freeze');

        $this->assertCount(100, $this->body('eval-own.jsonl'));
        $this->assertDatabaseMissing('datasets', ['purpose' => 'train-own']);
        $this->assertArrayNotHasKey('train', $this->run->datasets()->where('purpose', 'eval-own')->first()->stats);
        $this->assertFalse(Storage::disk('runs')->exists("{$this->run->slug}/train-own.jsonl"));
        Livewire::test(OwnTickets::class, ['run' => $this->run])->assertDontSee('Train a new run');
    }

    public function test_freeze_writes_both_files_and_drops_training_tickets_that_overlap_evaluation(): void
    {
        $this->frozen();

        $eval = $this->body('eval-own.jsonl');
        $train = $this->body('train-own.jsonl');
        $this->assertCount(70, $eval);
        $this->assertCount(27, $train, '30 training tickets minus 3 that overlap');
        $this->assertSame([], array_intersect(array_column($eval, 'id'), array_column($train, 'id')));
        $this->assertSame([], array_intersect(array_column($eval, 'input'), array_column($train, 'input')));
        $this->assertSame(['id', 'input', 'output', 'source', 'label_source'], array_keys($train[0]));

        $dataset = $this->run->datasets()->where('purpose', 'train-own')->firstOrFail();
        $this->assertSame([27, 3, ['shipping' => 27], ['judge' => 27]], [$dataset->n, $dataset->stats['overlap_dropped'], $dataset->stats['by_category'], $dataset->stats['label_sources']]);
        $this->assertSame(hash('sha256', Storage::disk('runs')->get($dataset->local_path)), $dataset->sha256);
        $this->assertSame(['share' => 30, 'n' => 27, 'overlap_dropped' => 3], $this->run->datasets()->where('purpose', 'eval-own')->first()->stats['train']);
        $this->assertSame(70, $this->run->datasets()->where('purpose', 'eval-own')->first()->n);

        // only the evaluation part reaches the worker for scoring
        $this->assertSame(Storage::disk('runs')->get("{$this->run->slug}/eval-own.jsonl"), $this->worker->files['eval-own.jsonl']);
        $this->assertArrayNotHasKey('train-own.jsonl', $this->worker->files);
    }

    public function test_the_own_tickets_card_reports_the_split_and_the_drops(): void
    {
        $this->frozen();

        $html = $this->get(route('runs.report', $this->run))->assertOk()->getContent();
        $this->assertStringContainsString('30% of the uploaded tickets were set aside for training', $html);
        $this->assertStringContainsString('27 went into a training file, 70 were kept for evaluation', $html);
        $this->assertStringContainsString('3 training tickets were left out', $html);
        $this->assertStringNotContainsString('courier never came', $html);
    }

    public function test_the_review_list_shows_and_filters_the_split(): void
    {
        $this->upload(20);

        $page = Livewire::test(OwnTickets::class, ['run' => $this->run])->set('filter', 'all')->set('split', 'train');
        $this->assertSame(20, $page->viewData('items')->total());
        $this->assertSame(80, $page->set('split', 'eval')->viewData('items')->total());
        $page->assertSeeHtml('eval, ')->assertSee('Training');
    }

    public function test_a_follow_up_run_trains_on_the_tickets_and_scores_the_same_evaluation_set(): void
    {
        $this->worker->iterations = [1 => 0.95];
        $this->frozen();
        $evalSha = $this->run->datasets()->where('purpose', 'eval-own')->first()->sha256;
        $trainSha = $this->run->datasets()->where('purpose', 'train-own')->first()->sha256;
        $this->run->update(['target_value' => 0.9]);

        $page = Livewire::test(OwnTickets::class, ['run' => $this->run])->assertSee('Train a new run with these tickets');
        $page->call('trainNewRun');

        $follow = Run::where('trained_from_run_id', $this->run->id)->firstOrFail();
        $page->assertRedirect(route('runs.show', $follow));
        $this->assertSame('Demo + own tickets', $follow->name);
        $this->assertSame(
            [$this->run->task_spec, $this->run->seed_examples, $this->run->holdout_examples, 'macro_f1', 0.9, 200, 1000],
            [$follow->task_spec, $follow->seed_examples, $follow->holdout_examples, $follow->target_metric, $follow->target_value, $follow->eval_n, $follow->train_n],
        );
        $real = $follow->datasets()->where('purpose', 'train-real')->sole();
        $this->assertSame([27, $trainSha, "own tickets of run {$this->run->slug}"], [$real->n, $real->sha256, $real->stats['source']]);
        $own = $follow->datasets()->where('purpose', 'eval-own')->sole();
        $this->assertSame([70, $evalSha], [$own->n, $own->sha256]);
        $this->assertSame('frozen', $follow->ownSet()->first()->state);
        $this->assertSame(70, $follow->ownItems()->where('split', 'eval')->count());
        $this->assertSame(0, $follow->ownItems()->where('split', 'train')->count());

        $this->drainQueue();
        $this->assertSame(Storage::disk('runs')->get($real->local_path), $this->worker->files['train-real.jsonl']);
        $trains = array_column(array_filter($this->worker->sent('POST', '/train'), fn ($r) => $r['body']['run_id'] === $follow->slug), 'body');
        $this->assertCount(1, $trains);
        $this->assertContains("/data/runs/{$follow->slug}/train-real.jsonl", $trains[0]['train_files']);

        $this->assertStringContainsString('Training included 27 own tickets of run', $this->get(route('runs.report', $follow))->getContent());
        $this->assertStringContainsString('A follow-up run trained on the training part', $this->get(route('runs.report', $this->run))->getContent());

        // one follow-up per source run
        Livewire::test(OwnTickets::class, ['run' => $this->run])->assertDontSee('Train a new run')->call('trainNewRun')->assertStatus(422);
        $this->assertSame(1, Run::where('trained_from_run_id', $this->run->id)->count());
    }

    public function test_the_pipeline_puts_train_real_on_the_worker_by_itself(): void
    {
        $this->worker->iterations = [1 => 0.95];
        $run = $this->makeRun(['require_review' => false]);
        $row = json_encode(['input' => 'Where is my parcel?', 'output' => ['category' => 'shipping', 'urgency' => 'normal', 'needs_human' => false]])."\n";
        $file = tempnam(sys_get_temp_dir(), 'tr');
        file_put_contents($file, $row);
        $this->artisan('chirak:attach-train-real', ['slug' => $run->slug, 'file' => $file])->assertSuccessful();
        $this->assertArrayNotHasKey('train-real.jsonl', $this->worker->files);

        $this->startAndDrain($run);

        $this->assertSame($row, $this->worker->files['train-real.jsonl']);
        $this->assertSame(1, count(array_filter($this->worker->sent('PUT', "/runs/{$run->slug}/files/train-real.jsonl"))));
    }

    public function test_starting_a_follow_up_needs_the_token_a_writable_demo_and_training_tickets(): void
    {
        $this->frozen();

        $this->flushSession();
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('trainNewRun')->assertForbidden();
        $this->withSession(['demo_token_ok' => true]);
        config(['chirak.readonly' => true]);
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('trainNewRun')->assertForbidden();
        $this->assertSame(0, Run::where('trained_from_run_id', $this->run->id)->count());

        config(['chirak.readonly' => false]);
        $this->run->datasets()->where('purpose', 'train-own')->delete();
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('trainNewRun')->assertStatus(422);
    }

    public function test_deleting_the_tickets_removes_the_training_file_too(): void
    {
        $this->frozen();
        $this->assertTrue(Storage::disk('runs')->exists("{$this->run->slug}/train-own.jsonl"));

        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('deleteItems');

        $this->assertFalse(Storage::disk('runs')->exists("{$this->run->slug}/train-own.jsonl"));
        $this->assertNull($this->run->datasets()->where('purpose', 'train-own')->first()->local_path);
        $this->assertSame(0, OwnItem::where('run_id', $this->run->id)->count());
        Livewire::test(OwnTickets::class, ['run' => $this->run])->assertDontSee('Train a new run');
    }

    public function test_deleting_the_tickets_also_deletes_the_follow_up_runs_copies(): void
    {
        $this->worker->iterations = [1 => 0.95];
        $this->frozen();
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('trainNewRun');
        $follow = Run::where('trained_from_run_id', $this->run->id)->firstOrFail();

        // refused while the follow-up run still trains on the copies
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('deleteItems')->assertStatus(422);
        $this->assertSame(70, $follow->ownItems()->count());

        $this->drainQueue();
        $this->assertSame(Run::COMPLETED, $follow->refresh()->status);
        $this->assertArrayHasKey('train-real.jsonl', $this->worker->files);
        $real = $follow->datasets()->where('purpose', 'train-real')->sole();
        $own = $follow->datasets()->where('purpose', 'eval-own')->sole();

        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('deleteItems');
        $this->drainQueue();

        $this->assertSame(0, OwnItem::whereIn('run_id', [$this->run->id, $follow->id])->count());
        $this->assertFalse(Storage::disk('runs')->exists($real->local_path));
        $this->assertFalse(Storage::disk('runs')->exists($own->local_path));
        $this->assertNull($real->refresh()->local_path);
        $this->assertNotNull($follow->ownSet()->first()->items_deleted_at);
        $this->assertNotNull($follow->ownSet()->first()->worker_copy_deleted_at);
        $this->assertArrayNotHasKey('train-real.jsonl', $this->worker->files);
        $this->assertNotEmpty($this->worker->sent('DELETE', "/runs/{$follow->slug}/own"));
    }

    public function test_a_frozen_set_lists_every_ticket(): void
    {
        $this->frozen();

        $this->assertSame(100, Livewire::test(OwnTickets::class, ['run' => $this->run])->viewData('items')->total());
    }
}
