<?php

namespace Tests\Feature;

use App\Jobs\EvaluateOwnSet;
use App\Jobs\LabelOwnItem;
use App\Livewire\OwnTickets;
use App\Models\Iteration;
use App\Models\OwnItem;
use App\Models\OwnSet;
use App\Models\Run;
use App\Services\Own\ClaudeLabeler;
use App\Support\StudentPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\RunsPipeline;
use Tests\TestCase;

class OwnTicketsTest extends TestCase
{
    use RefreshDatabase, RunsPipeline;

    private const OK = '{"category":"shipping","urgency":"normal","needs_human":false}';

    private Run $run;

    /** @var array<string, int> calls per ticket text */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPipeline();
        Storage::fake('local');
        Sleep::fake();
        $this->run = $this->makeRun(['served_name' => 'demo-iter-1', 'best_iteration' => 1, 'status' => Run::COMPLETED]);
        Iteration::create(['run_id' => $this->run->id, 'n' => 1, 'adapter_path' => "/data/runs/{$this->run->slug}/iter-1/adapter", 'finished_at' => now()]);
        $this->worker->labeler = fn (array $request) => $this->judge($request['messages'][0]['content']);
    }

    /** The judge: ticket 7 flips its answer, ticket 9 is garbage, ticket 11 is cut off, the rest are stable. */
    private function judge(string $text): string|array
    {
        $call = $this->calls[$text] = ($this->calls[$text] ?? 0) + 1;

        return match (true) {
            str_contains($text, 'ticket 7 ') => $call === 1 ? self::OK : '{"category":"returns","urgency":"normal","needs_human":false}',
            str_contains($text, 'ticket 9 ') => 'I would say shipping.',
            str_contains($text, 'ticket 11 ') => ['text' => self::OK, 'stop_reason' => 'max_tokens'],
            str_contains($text, 'ticket 12 ') => "```json\n".self::OK."\n```",
            default => self::OK,
        };
    }

    private function csv(int $n = 100): string
    {
        return "text\n".implode("\n", array_map(fn ($i) => "\"Order 48219$i is late, ticket $i from anna$i@mail.bg or +359 888 123 456\"", range(1, $n)));
    }

    private function unlocked(): static
    {
        return $this->withSession(['demo_token_ok' => true]);
    }

    private function uploaded(int $n = 100)
    {
        $this->unlocked();
        $page = Livewire::test(OwnTickets::class, ['run' => $this->run])->set('paste', $this->csv($n))->call('uploadTickets');
        $this->drainQueue(1000);

        return $page;
    }

    /** Resolves the three unstable tickets (7 flips, 9 garbage, 11 cut off): one set by hand, one dropped, one set by hand. */
    private function review(): void
    {
        $page = Livewire::test(OwnTickets::class, ['run' => $this->run]);
        $unstable = $this->run->ownItems()->where('status', 'unstable')->get();
        $page->set("edits.{$unstable[0]->id}", ['category' => 'returns', 'urgency' => 'low', 'needs_human' => 'false'])->call('change', $unstable[0]->id);
        $page->call('drop', $unstable[1]->id);
        $page->set("edits.{$unstable[2]->id}", ['category' => 'orders', 'urgency' => 'high', 'needs_human' => 'true'])->call('change', $unstable[2]->id);
    }

    public function test_tickets_are_masked_before_they_are_stored_or_sent(): void
    {
        $this->uploaded();

        $stored = $this->run->ownItems()->pluck('text')->implode("\n");
        $this->assertStringContainsString('Order [number] is late, ticket 1 from [email] or [phone]', $stored);
        $this->assertDoesNotMatchRegularExpression('/@|\d{6}|\+359/', $stored);

        $sent = $this->worker->sent('POST', '/anthropic');
        $this->assertNotEmpty($sent);
        foreach ($sent as $request) {
            $this->assertDoesNotMatchRegularExpression('/@|\d{6}|\+359/', json_encode($request['body']));
        }
    }

    public function test_the_request_to_claude_uses_the_student_prompt_and_the_verified_parameters(): void
    {
        $this->uploaded();

        $body = $this->worker->sent('POST', '/anthropic')[0]['body'];
        $this->assertSame(StudentPrompt::system($this->run->task_spec), $body['system']);
        $this->assertSame(4096, $body['max_tokens']);
        $this->assertSame(config('chirak.orchestrator.model'), $body['model']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertSame([['role' => 'user', 'content' => 'Order [number] is late, ticket 1 from [email] or [phone]']], $body['messages']);
    }

    public function test_each_ticket_is_labelled_twice_and_only_agreeing_valid_passes_become_labels(): void
    {
        $this->uploaded();

        $items = $this->run->ownItems()->get()->keyBy('position');
        $this->assertSame(['labelled', 'judge', ['category' => 'shipping', 'urgency' => 'normal', 'needs_human' => false]], [$items[1]->status, $items[1]->label_source, $items[1]->label]);
        $this->assertNotNull($items[1]->decided_at);
        $this->assertSame('labelled', $items[12]->status, 'a fenced answer parses');
        foreach ([7, 9, 11] as $position) {
            $this->assertSame(['unstable', null, null], [$items[$position]->status, $items[$position]->label, $items[$position]->label_source], "ticket $position");
        }
        $this->assertSame(200, count($this->worker->sent('POST', '/anthropic')));

        $set = $this->run->ownSet()->first();
        $this->assertSame([OwnSet::REVIEW, 200, 20000, 4000], [$set->state, $set->requests, $set->input_tokens, $set->output_tokens]);
    }

    public function test_rate_limits_are_retried_and_other_errors_leave_the_ticket_unjudged(): void
    {
        $this->uploaded();
        OwnSet::where('run_id', $this->run->id)->update(['state' => OwnSet::LABELLING]);
        $item = OwnItem::create(['run_id' => $this->run->id, 'position' => 500, 'text' => 'late parcel']);
        $calls = 0;
        $this->worker->labeler = function () use (&$calls) {
            return ++$calls <= 2 ? 429 : self::OK;
        };

        app()->call([new LabelOwnItem($item->id), 'handle']);
        $this->assertSame('labelled', $item->refresh()->status, 'two 429s, then both passes');
        $this->assertSame(4, $calls);

        OwnSet::where('run_id', $this->run->id)->update(['state' => OwnSet::LABELLING]);
        $other = OwnItem::create(['run_id' => $this->run->id, 'position' => 501, 'text' => 'late parcel 2']);
        $this->worker->labeler = fn () => 400;
        $this->expectExceptionMessage('Anthropic answered HTTP 400');
        try {
            app()->call([new LabelOwnItem($other->id), 'handle']);
        } finally {
            $this->assertSame('new', $other->refresh()->status);
        }
    }

    public function test_the_judge_labeller_gives_up_after_six_attempts_of_429(): void
    {
        $this->worker->labeler = fn () => 429;

        $this->expectExceptionMessage('Anthropic answered HTTP 429');
        try {
            app(ClaudeLabeler::class)->label($this->run->task_spec, 'x');
        } finally {
            $this->assertCount(6, $this->worker->sent('POST', '/anthropic'));
            Sleep::assertSleptTimes(5);
        }
    }

    public function test_changes_need_the_demo_token_and_valid_values(): void
    {
        $this->uploaded();
        $item = $this->run->ownItems()->where('status', 'unstable')->first();
        $this->flushSession();

        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('drop', $item->id)->assertForbidden();
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('freeze')->assertForbidden();
        $this->assertSame('unstable', $item->refresh()->status);

        $this->unlocked();
        $page = Livewire::test(OwnTickets::class, ['run' => $this->run])
            ->set("edits.{$item->id}", ['category' => 'nonsense', 'urgency' => 'low', 'needs_human' => 'false'])->call('change', $item->id);
        $this->assertSame('unstable', $item->refresh()->status);
        $page->assertSee('Pick a value for category');
    }

    public function test_the_review_records_who_decided_each_label(): void
    {
        $this->uploaded();
        $judged = $this->run->ownItems()->where('position', 1)->first();
        $page = Livewire::test(OwnTickets::class, ['run' => $this->run]);

        $page->call('accept', $judged->id);
        $this->assertSame('human', $judged->refresh()->label_source);

        $this->review();
        $items = $this->run->ownItems()->get()->keyBy('position');
        $this->assertSame(['labelled', 'human', 'returns'], [$items[7]->status, $items[7]->label_source, $items[7]->label['category']]);
        $this->assertSame(['dropped', null], [$items[9]->status, $items[9]->label]);
        $this->assertSame(['labelled', true], [$items[11]->status, $items[11]->label['needs_human']]);
        $this->assertSame('judge', $items[2]->label_source, 'untouched judge labels stay judge labels');
    }

    public function test_freezing_needs_every_unstable_ticket_resolved(): void
    {
        $this->uploaded();

        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('freeze')->assertStatus(422);
        $this->assertDatabaseMissing('datasets', ['purpose' => 'eval-own']);
    }

    public function test_freeze_writes_the_canonical_file_and_the_set_is_immutable_afterwards(): void
    {
        $this->uploaded();
        $this->review();
        $page = Livewire::test(OwnTickets::class, ['run' => $this->run]);
        $page->call('freeze');

        $dataset = $this->run->datasets()->where('purpose', 'eval-own')->firstOrFail();
        $body = Storage::disk('runs')->get($dataset->local_path);
        $rows = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", $body)));

        $this->assertSame(hash('sha256', $body), $dataset->sha256);
        $this->assertSame(99, $dataset->n);
        $this->assertNotNull($dataset->frozen_at);
        $this->assertSame(['id', 'input', 'output', 'source', 'label_source'], array_keys($rows[0]));
        $this->assertSame(['own-0001', 'own', 'judge'], [$rows[0]['id'], $rows[0]['source'], $rows[0]['label_source']]);
        $this->assertNotContains('own-0009', array_column($rows, 'id'), 'dropped tickets are left out');
        $this->assertSame('human', collect($rows)->firstWhere('id', 'own-0007')['label_source']);
        $this->assertSame(['judge' => 97, 'human' => 2], $dataset->stats['label_sources']);
        $this->assertSame(1, $dataset->stats['dropped']);
        $this->assertSame(OwnSet::FROZEN, $this->run->ownSet()->first()->state);

        // frozen: no decision, no second freeze, no new upload
        $item = $this->run->ownItems()->where('position', 2)->first();
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('drop', $item->id)->assertStatus(422);
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('freeze')->assertStatus(422);
        Livewire::test(OwnTickets::class, ['run' => $this->run])->set('paste', $this->csv())->call('uploadTickets')->assertSee('already has uploaded tickets');
        $this->assertSame('labelled', $item->refresh()->status);
        $this->assertSame($body, Storage::disk('runs')->get($dataset->local_path));
    }

    public function test_the_frozen_file_is_uploaded_and_base_and_adapter_are_scored_as_the_own_slice(): void
    {
        $this->uploaded();
        $this->review();
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('freeze');
        $this->drainQueue();

        $dataset = $this->run->datasets()->where('purpose', 'eval-own')->first();
        $this->assertSame(Storage::disk('runs')->get($dataset->local_path), $this->worker->files['eval-own.jsonl']);
        $labels = array_column(array_column($this->worker->sent('POST', '/evaluate'), 'body'), 'label');
        $this->assertSame(['base.eval-own', 'demo-iter-1.eval-own'], $labels);
        $this->assertSame(['base', "/data/runs/{$this->run->slug}/iter-1/adapter"], array_column(array_column($this->worker->sent('POST', '/evaluate'), 'body'), 'model'));

        $evals = $this->run->ownEvaluations()->get()->keyBy(fn ($e) => $e->iteration ?? 'base');
        $this->assertSame([0.55, 0.85], [$evals['base']->metrics['macro_f1'], $evals[1]->metrics['macro_f1']]);
        $this->assertSame($dataset->id, $evals['base']->dataset_id);
        $this->assertSame([], $evals['base']->failures, 'failures would repeat ticket texts');
        $this->assertSame('done', $this->run->ownSet()->first()->eval_state);
        $this->assertCount(0, $this->run->evaluations()->get(), 'the clean slice is untouched');
        $this->assertCount(0, $this->run->hardEvaluations()->get());
    }

    public function test_an_unreachable_worker_is_retried_later(): void
    {
        $this->uploaded();
        $this->review();
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('freeze');
        $this->worker->offline = true;
        $this->drainQueue();

        $set = $this->run->ownSet()->first();
        $this->assertSame('pending', $set->eval_state);
        $this->assertStringContainsString('worker unreachable', $set->error);

        $this->worker->offline = false;
        $this->drainQueue();
        $this->assertSame('done', $set->refresh()->eval_state);
        $this->assertSame(2, $this->run->ownEvaluations()->count());
    }

    public function test_the_public_report_shows_counts_and_scores_but_never_a_ticket_text(): void
    {
        $this->uploaded();
        $this->review();
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('freeze');
        $this->drainQueue();

        $html = $this->get(route('runs.report', $this->run))->assertOk()->getContent();
        $this->assertStringContainsString('Own tickets', $html);
        $this->assertStringContainsString('99 of the shop', $html);
        $this->assertStringContainsString('97 by the Claude judge, 2 by a person', $html);
        $this->assertStringContainsString('55.0%', $html);
        $this->assertStringContainsString('87.0%', $html); // adapter accuracy, 0.85 + 0.02
        $this->assertStringContainsString('needs_human', $html);
        $this->assertStringNotContainsString('is late', $html);
        $this->assertStringNotContainsString('Private ticket text', $html);
    }

    public function test_ticket_texts_show_on_the_review_page_only_with_the_demo_token(): void
    {
        $this->uploaded();

        Livewire::test(OwnTickets::class, ['run' => $this->run])->set('filter', 'all')->assertSee('Order [number] is late, ticket 1 from');
        $this->flushSession();
        Livewire::test(OwnTickets::class, ['run' => $this->run])->assertDontSee('is late')->assertSee('Enter the demo token');
    }

    public function test_the_owner_can_delete_the_tickets_and_the_metrics_stay(): void
    {
        $this->uploaded();
        $this->review();
        $page = Livewire::test(OwnTickets::class, ['run' => $this->run])->call('freeze');
        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('deleteItems')->assertStatus(422); // evaluation not done yet
        $this->drainQueue();

        Livewire::test(OwnTickets::class, ['run' => $this->run])->call('deleteItems');

        $dataset = $this->run->datasets()->where('purpose', 'eval-own')->first();
        $this->assertSame(0, $this->run->ownItems()->count());
        $this->assertNull($dataset->local_path);
        $this->assertFalse(Storage::disk('runs')->exists("{$this->run->slug}/eval-own.jsonl"));
        $this->assertSame(2, $this->run->ownEvaluations()->count());
        $this->assertNotNull($this->run->ownSet()->first()->items_deleted_at);
        $this->get(route('runs.report', $this->run))->assertOk()->assertSee('99 of the shop');
    }

    public function test_deleting_before_the_freeze_clears_the_set_so_a_new_upload_is_possible(): void
    {
        $page = $this->uploaded();

        $page->call('deleteItems');
        $this->assertNull($this->run->ownSet()->first());

        $page->set('paste', $this->csv())->call('uploadTickets');
        $this->assertSame(100, $this->run->ownItems()->count());
    }

    public function test_uploads_outside_100_to_1000_tickets_are_refused_and_a_file_works_too(): void
    {
        $this->unlocked();
        $page = Livewire::test(OwnTickets::class, ['run' => $this->run])->set('paste', $this->csv(50))->call('uploadTickets');
        $page->assertSee('found 50');
        $this->assertNull($this->run->ownSet()->first());
        Queue::assertNotPushed(LabelOwnItem::class);

        $page->set('file', UploadedFile::fake()->createWithContent('tickets.csv', $this->csv(120)))->call('uploadTickets');
        $this->assertSame(120, $this->run->ownItems()->count());
        Queue::assertPushed(LabelOwnItem::class, 120);
        Queue::assertNotPushed(EvaluateOwnSet::class);
    }

    public function test_the_dashboard_card_links_to_the_upload_page_and_the_pages_load(): void
    {
        $this->get(route('runs.show', $this->run))->assertOk()->assertSee(route('runs.tickets', $this->run));
        $this->get(route('runs.tickets', $this->run))->assertOk()->assertSee('Upload tickets');
    }
}
