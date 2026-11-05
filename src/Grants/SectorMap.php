<?php

declare(strict_types=1);

namespace Dashboard\Grants;

/**
 * Sectors a connecting key must be granted before it acts.
 * Missing row is deny. Reads of money, health, and live home data are gated.
 * Agent status reads stay open. Mail status stays open. Mail and agent changes do not.
 */
final class SectorMap
{
    public const SECTORS = ['money', 'home', 'mail', 'agents', 'health'];

    public const MODES = ['deny', 'once', 'always'];

    /**
     * @return array{sector: string, gated: bool, read: bool}|null
     */
    public static function match(string $path, bool $mutating): ?array
    {
        $read = !$mutating;
        if (str_contains($path, '/api/v1/money')) {
            return ['sector' => 'money', 'gated' => true, 'read' => $read];
        }
        if (str_contains($path, '/api/v1/health')) {
            return ['sector' => 'health', 'gated' => true, 'read' => $read];
        }
        if (str_contains($path, '/api/v1/home')) {
            return ['sector' => 'home', 'gated' => true, 'read' => $read];
        }
        if (str_contains($path, '/api/v1/mail')) {
            return ['sector' => 'mail', 'gated' => $mutating, 'read' => $read];
        }
        if (str_contains($path, '/api/v1/agents')) {
            return ['sector' => 'agents', 'gated' => $mutating, 'read' => $read];
        }

        return null;
    }

    public static function needsGrant(string $sector, bool $read): string
    {
        $act = $read ? 'reads' : 'changes';

        return 'needs-grant: This key is set to deny for ' . $sector . ' ' . $act
            . '. Grant allow once or allow always in Settings → Permissions. Nothing was changed.';
    }
}
