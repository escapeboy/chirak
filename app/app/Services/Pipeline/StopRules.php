<?php

namespace App\Services\Pipeline;

/**
 * The only termination logic of a run. The orchestrator model's own "stop" is logged but never consulted here.
 */
class StopRules
{
    public static function leakageExceeded(float $leakageDropRate): bool
    {
        return $leakageDropRate > config('chirak.stop.max_leakage_drop_rate');
    }

    /**
     * @return array{stop: bool, rule: ?string, reason: string, checks: array<string, bool>}
     */
    public static function evaluate(
        float $targetValue,
        int $iteration,
        float $metric,
        ?float $previousMetric,
        float $leakageDropRate = 0.0,
    ): array {
        $checks = [
            'leakage' => self::leakageExceeded($leakageDropRate),
            'target_met' => $metric >= $targetValue,
            'max_iterations' => $iteration >= config('chirak.stop.max_iterations'),
            // Rounded so that an exact one-point gain is not lost to float noise (0.81 - 0.80).
            'no_progress' => $iteration >= 2 && $previousMetric !== null
                && round($metric - $previousMetric, 6) < config('chirak.stop.min_gain'),
        ];

        $reasons = [
            'leakage' => 'leakage drop rate above 30%',
            'target_met' => sprintf('target met (%.3f >= %.3f)', $metric, $targetValue),
            'max_iterations' => 'reached the maximum of '.config('chirak.stop.max_iterations').' iterations',
            'no_progress' => sprintf('gain below 1 point (%+.3f)', $metric - ($previousMetric ?? 0)),
        ];

        foreach ($checks as $rule => $hit) {
            if ($hit) {
                return ['stop' => true, 'rule' => $rule, 'reason' => $reasons[$rule], 'checks' => $checks];
            }
        }

        return ['stop' => false, 'rule' => null, 'reason' => 'no stop rule hit', 'checks' => $checks];
    }
}
