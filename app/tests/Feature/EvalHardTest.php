<?php

namespace Tests\Feature;

use App\Models\Run;
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
}
