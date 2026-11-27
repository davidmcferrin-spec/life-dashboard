<?php

declare(strict_types=1);

namespace Dashboard\WakeHub;

use Dashboard\Support\ValidationException;

/**
 * A hub id is only unique inside one collection, so the dashboard keeps the collection in the id.
 * A bare number is still a Wake-on-LAN device.
 */
final class WakeHubTarget
{
    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly ?int $outlet,
        public readonly string $ref,
    ) {}

    public static function parse(int|string $raw): self
    {
        if (is_int($raw) || (is_string($raw) && preg_match('/^[1-9][0-9]*$/', $raw) === 1)) {
            $id = (int) $raw;
            if ($id < 1) {
                throw ValidationException::withFields(['device_id' => 'device_id must be a device number or a target such as amt:4.']);
            }

            return new self('device', $id, null, 'device:' . $id);
        }
        $text = rawurldecode(trim((string) $raw));
        if (preg_match('/^(device|amt|plug|jetkvm|ups|roku):([1-9][0-9]*)$/', $text, $m) === 1) {
            return new self($m[1], (int) $m[2], null, $m[1] . ':' . $m[2]);
        }
        if (preg_match('/^pdu:([1-9][0-9]*)$/', $text, $m) === 1) {
            return new self('pdu', (int) $m[1], null, 'pdu:' . $m[1]);
        }
        if (preg_match('/^pdu:([1-9][0-9]*):([1-9][0-9]*)$/', $text, $m) === 1) {
            return new self('outlet', (int) $m[1], (int) $m[2], 'pdu:' . $m[1] . ':' . $m[2]);
        }

        throw ValidationException::withFields(['device_id' => 'device_id must be a device number or a target such as amt:4 or pdu:1:3.']);
    }
}
