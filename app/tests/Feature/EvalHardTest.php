<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Run;
use Tests\Support\FakeWorker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsPipeline;
use Tests\TestCase;

class EvalHardTest extends TestCase
{
    use RefreshDatabase, RunsPipeline;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPipeline();
        $this->dir = sys_get_temp_dir().'/chirak-evalhard-'.uniqid();
        mkdir("$this->dir/eval-hard", 0777, true);
    }

    private function writeArtifacts(string $body, ?string $evaluatedSha = null): string
    {
        $sha = hash('sha256', $body);
        file_put_contents("$this->dir/eval-hard.jsonl", $body);
        file_put_contents("$this->dir/eval-hard/freeze.json", json_encode(['path' => '/data/runs/x/eval-hard.jsonl', 'sha256' => $sha,
            'n' => 2, 'frozen' => true, 'frozen_at' => '2026-10-14T08:00:00+00:00', 'label_sources' => ['judge+proposed' => 1, 'human' => 1, 'human_drop' => 0]]));
        file_put_contents("$this->dir/eval-hard/sample.stats.json", json_encode(['n' => 3]));
        file_put_contents("$this->dir/eval-hard/judge.stats.json", json_encode(['agreed' => 1]));
        file_put_contents("$this->dir/eval-hard/leakage-train-1.json", json_encode(['leakage_cosine' => 0, 'leakage_ngram' => 1]));
        foreach (['base', 'iter-1'] as $label) {
            @mkdir("$this->dir/evals/$label.eval-hard", 0777, true);
            file_put_contents("$this->dir/evals/$label.eval-hard/metrics.json", json_encode([
                'label' => "$label.eval-hard", 'eval_sha256' => $evaluatedSha ?? $sha, 'macro_f1' => $label === 'base' ? 0.5 : 0.7,
                'accuracy' => 0.6, 'failures' => [['id' => 'hard-0001']],
            ]));
        }

        return $sha;
    }

    public function test_it_registers_eval_hard_and_hard_slice_scores_without_touching_the_clean_slice(): void
    {
        $this->worker->iterations = [1 => 0.95];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));
        $this->assertSame(Run::COMPLETED, $run->status);
        $clean = $run->evaluations()->get()->toArray();
        $sha = $this->writeArtifacts("{\"id\":\"hard-0001\"}\n{\"id\":\"hard-0002\"}\n");

        $this->artisan('chirak:import-eval-hard', ['slug' => $run->slug, '--dir' => $this->dir])->assertSuccessful();

        $dataset = $run->datasets()->where('purpose', 'eval-hard')->firstOrFail();
        $this->assertSame([$sha, 2], [$dataset->sha256, $dataset->n]);
        $this->assertSame(1, $dataset->stats['leakage']['leakage-train-1']['leakage_ngram']);
        $hard = $run->hardEvaluations()->get()->keyBy(fn ($e) => $e->iteration ?? 'base');
        $this->assertSame([0.5, 0.7], [$hard['base']->metrics['macro_f1'], $hard[1]->metrics['macro_f1']]);
        $this->assertSame($dataset->id, $hard[1]->dataset_id);
        $this->assertSame([['id' => 'hard-0001']], $hard[1]->failures);
        $this->assertEquals($clean, $run->evaluations()->get()->toArray());

        // Re-running is a no-op; a different file is refused because eval-hard is frozen.
        $this->artisan('chirak:import-eval-hard', ['slug' => $run->slug, '--dir' => $this->dir])->assertSuccessful();
        $this->assertSame(2, $run->hardEvaluations()->count());
        $this->writeArtifacts("{\"id\":\"other\"}\n");
        $this->artisan('chirak:import-eval-hard', ['slug' => $run->slug, '--dir' => $this->dir])->assertFailed();
    }

    public function test_an_evaluation_of_another_file_is_refused(): void
    {
        $this->worker->iterations = [1 => 0.95];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));
        $this->writeArtifacts("{\"id\":\"hard-0001\"}\n", str_repeat('0', 64));

        $this->artisan('chirak:import-eval-hard', ['slug' => $run->slug, '--dir' => $this->dir])->assertFailed();
        $this->assertSame(0, $run->hardEvaluations()->count());
    }

    public function test_a_retargeted_run_iterates_against_eval_hard_and_keeps_the_clean_slice_decision(): void
    {
        $this->worker->iterations = [1 => 0.95, 2 => 0.96, 3 => 0.97];
        $this->worker->hard = ['base' => 0.70, 1 => 0.75, 2 => 0.90, 3 => 0.91];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));
        $this->assertSame([Run::COMPLETED, 1], [$run->status, $run->best_iteration]);
        $cleanDecision = $run->decisions()->sole()->toArray();
        Dataset::create(['run_id' => $run->id, 'purpose' => 'eval-hard', 'remote_path' => "/data/runs/{$run->slug}/eval-hard.jsonl",
            'local_path' => "{$run->slug}/eval-hard.jsonl", 'n' => 119, 'sha256' => FakeWorker::SHA, 'frozen_at' => now()]);

        $this->artisan('chirak:retarget-hard', ['slug' => $run->slug])->assertSuccessful();
        $this->drainQueue();
        $run->refresh();

        $this->assertSame(Run::COMPLETED, $run->status);
        $this->assertSame(2, $run->best_iteration);
        $this->assertSame('target met (0.900 >= 0.850)', $run->stop_reason);
        $hard = $run->hardEvaluations()->get()->keyBy(fn ($e) => $e->iteration ?? 'base');
        $this->assertSame([0.70, 0.75, 0.90], [$hard['base']->metrics['macro_f1'], $hard[1]->metrics['macro_f1'], $hard[2]->metrics['macro_f1']]);
        $this->assertSame(0.96, $run->evaluations()->where('iteration', 2)->value('metrics')['macro_f1']);

        $this->assertEquals($cleanDecision, $run->decisions()->where('slice', 'clean')->sole()->toArray());
        $decisions = $run->decisions()->where('slice', 'hard')->get();
        $this->assertSame([1, 2], $decisions->pluck('iteration')->all());
        $this->assertFalse($decisions[0]->stop_rule['stop']);
        $this->assertSame('claude', $decisions[0]->orchestrator_output['used']);

        $gen = collect($this->worker->sent('POST', '/generate'))->last()['body'];
        $this->assertSame(2, $gen['iteration']);
        $this->assertSame(["/data/runs/{$run->slug}/eval.jsonl", "/data/runs/{$run->slug}/eval-hard.jsonl"], $gen['avoid_near']);
        $this->assertSame(400, $gen['judge_cap']);
        $this->assertSame(['returns', 'complaints'], array_column($gen['target_slices'], 'name'));
        $labels = array_column(array_column($this->worker->sent('POST', '/evaluate'), 'body'), 'label');
        $this->assertSame(['base', 'iter-1', 'base.eval-hard', 'iter-1.eval-hard', 'iter-2', 'iter-2.eval-hard'], $labels);
    }

    public function test_retarget_needs_eval_hard(): void
    {
        $this->worker->iterations = [1 => 0.95];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));
        $this->artisan('chirak:retarget-hard', ['slug' => $run->slug])->assertFailed();
        $this->assertSame(Run::COMPLETED, $run->refresh()->status);
    }

    public function test_report_shows_both_slices_the_hard_decisions_and_the_held_out_answers(): void
    {
        $this->worker->iterations = [1 => 0.95, 2 => 0.96, 3 => 0.97];
        $this->worker->hard = ['base' => 0.70, 1 => 0.75, 2 => 0.90, 3 => 0.91];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));
        Dataset::create(['run_id' => $run->id, 'purpose' => 'eval-hard', 'remote_path' => "/data/runs/{$run->slug}/eval-hard.jsonl",
            'local_path' => "{$run->slug}/eval-hard.jsonl", 'n' => 119, 'sha256' => FakeWorker::SHA, 'frozen_at' => now(),
            'stats' => ['label_sources' => ['judge+proposed' => 14, 'claude-code-delegated' => 105]]]);
        $this->artisan('chirak:retarget-hard', ['slug' => $run->slug])->assertSuccessful();
        $this->drainQueue();
        $this->artisan('chirak:holdout', ['slug' => $run->slug])->assertSuccessful();
        $this->assertSame(2 * count($run->holdout_examples), $run->holdoutPredictions()->count());
        $this->artisan('chirak:holdout', ['slug' => $run->slug])->assertSuccessful(); // stored answers are kept
        $this->assertSame(2 * count($run->holdout_examples), $run->holdoutPredictions()->count());

        $this->get("/r/{$run->slug}")->assertOk()
            ->assertSeeText('Clean slice')->assertSeeText('Hard slice')
            ->assertSeeText('90.0%')->assertSeeText('Macro-F1 (hard)')
            ->assertSee("by Claude Code on the owner's delegation, not by a human", false)
            ->assertSeeText('hard slice')->assertSeeText('Held-out examples')
            ->assertSeeText($run->holdout_examples[0]['input']);
    }
}
