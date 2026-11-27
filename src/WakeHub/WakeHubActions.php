<?php

declare(strict_types=1);

namespace Dashboard\WakeHub;

use Dashboard\Support\ValidationException;

/**
 * Shared power verbs. off, cycle, reboot, and shutdown need confirm.
 * Each collection accepts a different subset, so the verb is mapped before the hub call.
 */
final class WakeHubActions
{
    public const DEVICE = ['on', 'off', 'cycle', 'reboot', 'shutdown'];

    public const DESTRUCTIVE = ['off', 'cycle', 'reboot', 'shutdown'];

    public const METHODS = ['auto', 'wol', 'pdu', 'amt', 'jetkvm', 'plug', 'ups', 'roku'];

    public static function normalize(string $action): string
    {
        return strtolower(trim($action));
    }

    public static function destructive(string $action): bool
    {
        return in_array(self::normalize($action), self::DESTRUCTIVE, true);
    }

    public static function forKind(string $kind, string $action): string
    {
        $action = self::normalize($action);
        if (!in_array($action, self::DEVICE, true)) {
            throw ValidationException::withFields(['action' => 'action must be on, off, cycle, reboot, or shutdown.']);
        }
        if ($kind === 'device' || $kind === 'amt' || $kind === 'jetkvm') {
            return $action;
        }
        if ($kind === 'outlet') {
            if ($action === 'shutdown') {
                throw ValidationException::withFields(['action' => 'A PDU outlet can be turned on, off, or rebooted.']);
            }

            return $action === 'cycle' || $action === 'reboot' ? 'reboot' : $action;
        }
        if ($kind === 'plug') {
            if ($action === 'shutdown') {
                throw ValidationException::withFields(['action' => 'A plug can be turned on, off, or cycled.']);
            }

            return $action === 'reboot' ? 'cycle' : $action;
        }
        if ($kind === 'ups') {
            return match ($action) {
                'on' => 'turn_on',
                'off' => 'turn_off',
                'shutdown' => 'shutdown',
                default => 'reboot',
            };
        }
        if ($kind === 'roku') {
            if ($action !== 'on' && $action !== 'off') {
                throw ValidationException::withFields(['action' => 'A Roku can be turned on or off.']);
            }

            return $action;
        }

        throw ValidationException::withFields(['device_id' => 'Pick an outlet on that PDU, such as pdu:1:3.']);
    }
}
