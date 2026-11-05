<?php

declare(strict_types=1);

namespace Dashboard\Health;

/**
 * One winning sample per metric per day, then a readiness score from those facts.
 *
 * @phpstan-type Point array{metric: string, day: string, value: float, authority: string}
 * @phpstan-type Prior array{day: string, hrv_ms: float}
 */
final class DailyRollup
{
    /**
     * @param list<Point> $points
     * @param list<Prior> $priorHrv days already stored, oldest or newest
     * @return array<string, array<string, mixed>>
     */
    public static function days(array $points, array $priorHrv): array
    {
        $chosen = [];
        foreach ($points as $point) {
            $day = (string) $point['day'];
            $metric = (string) $point['metric'];
            if (!isset($chosen[$day][$metric]) || Authority::beats($metric, (string) $point['authority'], (string) $chosen[$day][$metric]['authority'])) {
                $chosen[$day][$metric] = $point;
            }
        }
        $history = [];
        foreach ($priorHrv as $row) {
            $history[] = ['day' => (string) $row['day'], 'hrv_ms' => (float) $row['hrv_ms']];
        }
        ksort($chosen);
        $out = [];
        foreach ($chosen as $day => $metrics) {
            $baselineValues = [];
            foreach ($history as $row) {
                if ($row['day'] < $day) {
                    $baselineValues[] = $row['hrv_ms'];
                }
            }
            if (count($baselineValues) > 14) {
                $baselineValues = array_slice($baselineValues, -14);
            }
            $facts = [
                'sleep_minutes' => isset($metrics['sleep']) ? (float) $metrics['sleep']['value'] : null,
                'hrv_ms'        => isset($metrics['hrv']) ? (float) $metrics['hrv']['value'] : null,
                'hrv_baseline'  => Readiness::median($baselineValues),
                'steps'         => isset($metrics['steps']) ? (float) $metrics['steps']['value'] : null,
            ];
            $score = Readiness::score($facts);
            $out[$day] = [
                'sleep_minutes'        => $facts['sleep_minutes'],
                'sleep_authority'      => $metrics['sleep']['authority'] ?? null,
                'hrv_ms'               => $facts['hrv_ms'],
                'hrv_authority'        => $metrics['hrv']['authority'] ?? null,
                'resting_hr'           => isset($metrics['resting_hr']) ? (float) $metrics['resting_hr']['value'] : null,
                'resting_authority'    => $metrics['resting_hr']['authority'] ?? null,
                'steps'                => $facts['steps'],
                'steps_authority'      => $metrics['steps']['authority'] ?? null,
                'readiness_score'      => $score['score'],
                'readiness_confidence' => $score['confidence'],
                'used'                 => $score['used'],
            ];
            if ($facts['hrv_ms'] !== null) {
                $history[] = ['day' => $day, 'hrv_ms' => $facts['hrv_ms']];
            }
        }

        return $out;
    }
}
