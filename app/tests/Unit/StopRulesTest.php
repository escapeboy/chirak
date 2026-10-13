<?php

namespace Tests\Unit;

use App\Services\Pipeline\StopRules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StopRulesTest extends TestCase
{
    public function test_target_met_stops(): void
    {
        $v = StopRules::evaluate(0.85, 1, 0.86, null);
        $this->assertTrue($v['stop']);
        $this->assertSame('target_met', $v['rule']);
    }

    public function test_target_exactly_reached_stops(): void
    {
        $this->assertSame('target_met', StopRules::evaluate(0.85, 1, 0.85, null)['rule']);
    }

    public function test_just_below_target_continues(): void
    {
        $v = StopRules::evaluate(0.85, 1, 0.849, null);
        $this->assertFalse($v['stop']);
        $this->assertNull($v['rule']);
    }

    public function test_third_iteration_always_stops(): void
    {
        $v = StopRules::evaluate(0.95, 3, 0.70, 0.60);
        $this->assertTrue($v['stop']);
        $this->assertSame('max_iterations', $v['rule']);
    }

    public function test_second_iteration_does_not_stop_on_count_alone(): void
    {
        $this->assertFalse(StopRules::evaluate(0.95, 2, 0.70, 0.60)['stop']);
    }

    #[DataProvider('gains')]
    public function test_no_progress_rule(float $previous, float $current, bool $stops): void
    {
        $v = StopRules::evaluate(0.99, 2, $current, $previous);
        $this->assertSame($stops, $v['stop']);
        $this->assertSame($stops ? 'no_progress' : null, $v['rule']);
    }

    public static function gains(): array
    {
        return [
            'gain of 0.5 points' => [0.80, 0.805, true],
            'exactly 1 point continues' => [0.80, 0.81, false],
            'exactly 1 point, float noise' => [0.79, 0.80, false],
            'just under 1 point' => [0.80, 0.8099, true],
            'no change' => [0.80, 0.80, true],
            'regression' => [0.80, 0.75, true],
            'big gain' => [0.60, 0.75, false],
        ];
    }

    public function test_no_progress_is_not_checked_on_the_first_iteration(): void
    {
        $this->assertFalse(StopRules::evaluate(0.99, 1, 0.50, 0.50)['stop']);
        $this->assertFalse(StopRules::evaluate(0.99, 1, 0.50, null)['stop']);
    }

    public function test_leakage_above_thirty_percent_stops(): void
    {
        $v = StopRules::evaluate(0.99, 1, 0.50, null, 0.31);
        $this->assertTrue($v['stop']);
        $this->assertSame('leakage', $v['rule']);
        $this->assertSame('leakage drop rate above 30%', $v['reason']);
    }

    public function test_leakage_of_exactly_thirty_percent_continues(): void
    {
        $this->assertFalse(StopRules::evaluate(0.99, 1, 0.50, null, 0.30)['stop']);
        $this->assertFalse(StopRules::leakageExceeded(0.30));
        $this->assertTrue(StopRules::leakageExceeded(0.3001));
    }

    public function test_rule_priority_leakage_then_target(): void
    {
        $this->assertSame('leakage', StopRules::evaluate(0.5, 3, 0.9, 0.9, 0.5)['rule']);
        $this->assertSame('target_met', StopRules::evaluate(0.5, 3, 0.9, 0.9)['rule']);
    }

    public function test_verdict_lists_every_check(): void
    {
        $v = StopRules::evaluate(0.99, 2, 0.5, 0.4);
        $this->assertSame(['leakage', 'target_met', 'max_iterations', 'no_progress'], array_keys($v['checks']));
    }
}
