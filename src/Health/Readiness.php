<?php

declare(strict_types=1);

namespace Dashboard\Health;

/**
 * Readiness uses only stored sleep, HRV, and steps.
 * HRV counts only against a baseline of at least three earlier days.
 * A missing input is left out. It is not scored as zero.
 */
final class Readiness
{
    /**
     * @param array{sleep_minutes: ?float, hrv_ms: ?float, hrv_baseline: ?float, steps: ?float} $facts
     * @return array{score: ?int, confidence: float, used: list<string>}
     */
    public static function score(array $facts): array
    {
        $parts = [];
        if (isset($facts['sleep_minutes']) && is_numeric($facts['sleep_minutes'])) {
            $parts['sleep'] = self::sleep((float) $facts['sleep_minutes']);
        }
        if (
            isset($facts['hrv_ms'], $facts['hrv_baseline'])
            && is_numeric($facts['hrv_ms'])
            && is_numeric($facts['hrv_baseline'])
            && (float) $facts['hrv_baseline'] > 0
        ) {
            $parts['hrv'] = self::hrv((float) $facts['hrv_ms'], (float) $facts['hrv_baseline']);
        }
        if (isset($facts['steps']) && is_numeric($facts['steps'])) {
            $parts['steps'] = self::steps((float) $facts['steps']);
        }
        if ($parts === []) {
            return ['score' => null, 'confidence' => 0.0, 'used' => []];
        }
        $used = array_keys($parts);

        return [
            'score'      => (int) round(array_sum($parts) / count($parts)),
            'confidence' => round(count($parts) / 3, 2),
            'used'       => $used,
        ];
    }

    public static function sleep(float $minutes): int
    {
        if ($minutes >= 420 && $minutes <= 540) {
            return 100;
        }
        if ($minutes < 420) {
            return max(0, (int) round(100 - (420 - $minutes) * 0.5));
        }

        return max(40, (int) round(100 - ($minutes - 540) * 0.25));
    }

    public static function steps(float $steps): int
    {
        if ($steps >= 8000) {
            return 100;
        }
        if ($steps <= 0) {
            return 0;
        }

        return (int) round(100 * $steps / 8000);
    }

    public static function hrv(float $value, float $baseline): int
    {
        $score = (int) round(80 + (($value / $baseline) - 1) * 200);

        return max(0, min(100, $score));
    }

    /**
     * @param list<float> $values
     */
    public static function median(array $values): ?float
    {
        $clean = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $clean[] = (float) $value;
            }
        }
        if (count($clean) < 3) {
            return null;
        }
        sort($clean);
        $mid = intdiv(count($clean), 2);
        if (count($clean) % 2 === 1) {
            return $clean[$mid];
        }

        return ($clean[$mid - 1] + $clean[$mid]) / 2;
    }
}
