<?php

declare(strict_types=1);

namespace Dashboard\WakeHub;

/**
 * Device power verbs Wake Hub accepts on POST /api/v1/devices/{id}/power.
 * off, cycle, reboot, and shutdown drop or bounce power, so they need confirm.
 */
final class WakeHubActions
{
    public const DEVICE = ['on', 'off', 'cycle', 'reboot', 'shutdown'];

    public const DESTRUCTIVE = ['off', 'cycle', 'reboot', 'shutdown'];

    public const METHODS = ['auto', 'wol', 'pdu', 'amt', 'jetkvm', 'plug', 'ups'];

    public static function normalize(string $action): string
    {
        return strtolower(trim($action));
    }

    public static function destructive(string $action): bool
    {
        return in_array(self::normalize($action), self::DESTRUCTIVE, true);
    }
}
