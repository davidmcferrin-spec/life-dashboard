<?php

declare(strict_types=1);

namespace Dashboard\Health;

/**
 * Samsung Health and Health Connect outrank later device clouds.
 * A manual meal or body log outranks a device sample. Sleep, HRV, and steps do not.
 */
final class Authority
{
    public const RANKS = [
        'samsung'         => 0,
        'health_connect'  => 1,
        'garmin'          => 2,
        'whoop'           => 3,
        'oura'            => 4,
        'polar'           => 5,
        'suunto'          => 6,
        'apple'           => 7,
        'other'           => 8,
        'manual'          => 9,
    ];

    public static function fromProvider(string $provider): string
    {
        $name = strtolower(trim($provider));
        $name = str_replace([' ', '-'], '_', $name);

        return match (true) {
            $name === '' => 'other',
            str_contains($name, 'samsung') => 'samsung',
            str_contains($name, 'health_connect') || $name === 'google' => 'health_connect',
            str_contains($name, 'garmin') => 'garmin',
            str_contains($name, 'whoop') => 'whoop',
            str_contains($name, 'oura') => 'oura',
            str_contains($name, 'polar') => 'polar',
            str_contains($name, 'suunto') => 'suunto',
            str_contains($name, 'apple') => 'apple',
            $name === 'manual' => 'manual',
            default => 'other',
        };
    }

    public static function rank(string $authority): int
    {
        return self::RANKS[$authority] ?? self::RANKS['other'];
    }

    public static function beats(string $metric, string $candidate, string $current): bool
    {
        if (in_array($metric, ['meal', 'body'], true)) {
            if ($candidate === 'manual') {
                return true;
            }
            if ($current === 'manual') {
                return false;
            }
        }

        return self::rank($candidate) < self::rank($current);
    }
}
