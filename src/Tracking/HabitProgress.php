<?php

declare(strict_types=1);

namespace Dashboard\Tracking;

/**
 * When a habit counts as done.
 *
 * A check-off habit has target 1. A count habit (daily_target, such as glasses
 * of water) is done only when progress reaches that target. A smaller total is
 * partial and is not done. A habit linked to a number metric uses that metric's
 * value as progress and goal_threshold, or the metric's goal, as the target.
 * done_today is the same boolean as done.
 */
final class HabitProgress
{
    public const DONE_MEANS = 'A habit is done when it is checked for the day. A check-off habit has target 1. A count habit is checked only when progress reaches daily_target; a smaller total is partial and done is false. A habit linked to a number metric shows that metric as progress and is checked when the value reaches goal_threshold (or the metric goal) or when it is checked by hand. done_today is that same boolean. A number goal is done when progress reaches its goal.';

    /**
     * @return array{target: float, threshold: float, progress: float, done: bool, remaining: float}
     */
    public static function state(float $progress, float $target, bool $checked): array
    {
        $target = max(0.0, $target);
        $progress = max(0.0, $progress);
        $met = $target > 0.0 && $progress + 1e-9 >= $target;
        $remaining = $met ? 0.0 : max(0.0, $target - $progress);

        return [
            'target'    => $target,
            'threshold' => $target,
            'progress'  => $progress,
            'done'      => $checked,
            'remaining' => $remaining,
        ];
    }

    /**
     * A down goal is done when a reading is at or under the target.
     *
     * @return array{target: float, threshold: float, progress: float, done: bool, remaining: float}
     */
    public static function downState(float $progress, float $target, bool $hasReading): array
    {
        $target = max(0.0, $target);
        $progress = max(0.0, $progress);
        $met = $hasReading && $progress <= $target + 1e-9;
        if ($met || !$hasReading) {
            $remaining = $met ? 0.0 : $target;
        } else {
            $remaining = max(0.0, $progress - $target);
        }

        return [
            'target'    => $target,
            'threshold' => $target,
            'progress'  => $hasReading ? $progress : 0.0,
            'done'      => $met,
            'remaining' => $remaining,
        ];
    }

    public static function countsAsChecked(float $value, float $target, bool $countHabit): bool
    {
        if ($countHabit) {
            return $target > 0.0 && $value + 1e-9 >= $target;
        }

        return $value + 1e-9 >= 1.0;
    }

    /**
     * A meal checks a habit whose name or key is that meal, a general meal habit,
     * or eat-at-home when the meal was at home. Meal prep is not a meal log.
     */
    public static function matchesMeal(string $name, string $key, string $mealType, bool $eatenOut): bool
    {
        $text = self::words($name . ' ' . $key);
        $type = strtolower(trim($mealType));
        if ($type !== '' && $type !== 'other' && self::hasWord($text, $type)) {
            return true;
        }
        if (self::isGeneralMeal($text)) {
            return true;
        }
        if (!$eatenOut && self::isEatAtHome($text)) {
            return true;
        }
        if ($eatenOut && self::isEatOut($text)) {
            return true;
        }

        return false;
    }

    private static function isGeneralMeal(string $text): bool
    {
        if ($text === 'meal' || $text === 'meals' || $text === 'log meal') {
            return true;
        }
        if (!self::hasWord($text, 'meal') && !self::hasWord($text, 'meals')) {
            return false;
        }
        if (str_contains($text, 'meal prep') || str_contains($text, 'meal plan') || str_contains($text, 'meal builder')) {
            return false;
        }

        return self::hasWord($text, 'meal') || self::hasWord($text, 'meals');
    }

    private static function isEatAtHome(string $text): bool
    {
        foreach (['eat at home', 'ate at home', 'eaten at home', 'home cooked', 'cooked at home', 'eat home'] as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function isEatOut(string $text): bool
    {
        foreach (['eat out', 'ate out', 'eaten out', 'dining out', 'restaurant'] as $phrase) {
            if (str_contains($text, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function words(string $value): string
    {
        $text = strtolower($value);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private static function hasWord(string $text, string $word): bool
    {
        if ($word === '') {
            return false;
        }

        return preg_match('/(?:^| )' . preg_quote($word, '/') . '(?: |$)/', $text) === 1;
    }
}
