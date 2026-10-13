<?php

namespace Tests\Feature;

use App\Models\Evaluation;
use App\Models\Run;
use App\Services\Pipeline\FailureAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RunsPipeline;
use Tests\TestCase;

class FailureAnalystTest extends TestCase
{
    use RefreshDatabase, RunsPipeline;

    private Run $run;

    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPipeline();
        $this->run = $this->makeRun();
        $this->evaluation = Evaluation::create([
            'run_id' => $this->run->id, 'iteration' => 1, 'eval_sha256' => 'x',
            'metrics' => ['macro_f1' => 0.6, 'per_class' => [
                'orders' => ['f1' => 0.9], 'returns' => ['f1' => 0.2], 'shipping' => ['f1' => 0.7],
                'payments' => ['f1' => 0.1], 'other' => ['f1' => 0.3], 'complaints' => ['f1' => 0.95],
            ]],
            'failures' => array_fill(0, 80, ['input' => 'bad', 'expected' => ['category' => 'returns'], 'got' => null, 'error' => 'invalid json']),
        ]);
    }

    public function test_valid_answer_is_parsed_from_a_fenced_block(): void
    {
        $result = app(FailureAnalyst::class)->analyze($this->run, $this->evaluation, []);

        $this->assertSame('claude', $result['output']['used']);
        $this->assertSame('returns', $result['next_slices'][0]['name']);
        $this->assertCount(1, $result['output']['attempts']);
        $this->assertSame([], $result['output']['attempts'][0]['errors']);
    }

    public function test_request_uses_the_configured_model_headers_and_at_most_50_failures(): void
    {
        config(['chirak.orchestrator.model' => 'claude-test-model']);
        app(FailureAnalyst::class)->analyze($this->run, $this->evaluation, []);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.anthropic.com/v1/messages') {
                return false;
            }
            $payload = json_decode($request['messages'][0]['content'], true);

            return $request['model'] === 'claude-test-model'
                && $request->header('anthropic-version') === ['2023-06-01']
                && $request->header('x-api-key') === ['test-key']
                && count($payload['failures']) === 50
                && ! str_contains($request['messages'][0]['content'], 'holdout');
        });
    }

    public function test_one_invalid_answer_is_retried_with_the_errors(): void
    {
        $this->worker->claude = ['garbage', 'valid'];
        $result = app(FailureAnalyst::class)->analyze($this->run, $this->evaluation, []);

        $this->assertSame('claude', $result['output']['used']);
        $this->assertCount(2, $result['output']['attempts']);
        $this->assertNotEmpty($result['output']['attempts'][0]['errors']);

        $retry = $this->worker->sent('POST', '/anthropic')[1]['body']['messages'];
        $this->assertCount(3, $retry);
        $this->assertStringContainsString('rejected', $retry[2]['content']);
    }

    public function test_two_invalid_answers_fall_back_to_the_three_worst_f1_classes(): void
    {
        $this->worker->claude = ['garbage', 'too_many'];
        $result = app(FailureAnalyst::class)->analyze($this->run, $this->evaluation, []);

        $this->assertSame('fallback', $result['output']['used']);
        $this->assertSame(['payments', 'returns', 'other'], array_column($result['next_slices'], 'name'));
        $this->assertSame(1000, array_sum(array_column($result['next_slices'], 'n')));
        $this->assertSame([334, 333, 333], array_column($result['next_slices'], 'n'));
        $this->assertStringContainsString('exceeds train_n', implode(' ', $result['output']['attempts'][1]['errors']));
    }

    public function test_missing_api_key_falls_back_without_calling_anthropic(): void
    {
        config(['chirak.orchestrator.api_key' => null]);
        $result = app(FailureAnalyst::class)->analyze($this->run, $this->evaluation, []);

        $this->assertSame('fallback', $result['output']['used']);
        $this->assertCount(0, $this->worker->sent('POST', '/anthropic'));
    }

    public function test_anthropic_http_error_counts_as_a_failed_attempt(): void
    {
        $this->worker->claudeHttpStatus = 529;
        $result = app(FailureAnalyst::class)->analyze($this->run, $this->evaluation, []);

        $this->assertSame('fallback', $result['output']['used']);
        $this->assertStringContainsString('529', $result['output']['attempts'][0]['errors'][0]);
    }

    public function test_decision_row_logs_prompt_raw_response_parsed_slices_and_the_stop_rule_verdict(): void
    {
        $this->worker->iterations = [1 => 0.60, 2 => 0.90];
        $run = $this->startAndDrain($this->makeRun(['require_review' => false]));

        $decision = $run->decisions()->where('iteration', 1)->first();
        $this->assertNotEmpty($decision->orchestrator_input['system']);
        $this->assertStringContainsString('"failures"', $decision->orchestrator_input['messages'][0]['content']);
        $this->assertNotEmpty($decision->orchestrator_output['attempts'][0]['raw']);
        $this->assertIsArray($decision->orchestrator_output['attempts'][0]['parsed']);
        $this->assertFalse($decision->stop_rule['stop']);
        $this->assertNotEmpty($decision->next_slices);
        $this->assertSame(config('chirak.orchestrator.model'), $decision->model);
    }

    /** @dataProvider answers */
    public function test_validation_rules(mixed $answer, bool $valid): void
    {
        $this->assertSame($valid, FailureAnalyst::validate($answer, 1000) === []);
    }

    public static function answers(): array
    {
        $slice = ['name' => 'returns', 'n' => 100, 'guidance' => 'g'];
        $ok = ['stop' => false, 'reason' => 'r', 'next_slices' => [$slice]];

        return [
            'valid' => [$ok, true],
            'not an object' => [null, false],
            'stop as string' => [['stop' => 'no'] + $ok, false],
            'missing reason' => [array_diff_key($ok, ['reason' => 1]), false],
            'no slices' => [['next_slices' => []] + $ok, false],
            'seven slices' => [['next_slices' => array_fill(0, 7, ['n' => 10] + $slice)] + $ok, false],
            'six slices' => [['next_slices' => array_fill(0, 6, ['n' => 10] + $slice)] + $ok, true],
            'empty name' => [['next_slices' => [['name' => ''] + $slice]] + $ok, false],
            'n not integer' => [['next_slices' => [['n' => 'many'] + $slice]] + $ok, false],
            'n zero' => [['next_slices' => [['n' => 0] + $slice]] + $ok, false],
            'sum equals train_n' => [['next_slices' => [['n' => 1000] + $slice]] + $ok, true],
            'sum above train_n' => [['next_slices' => [['n' => 600] + $slice, ['n' => 401] + $slice]] + $ok, false],
        ];
    }
}
