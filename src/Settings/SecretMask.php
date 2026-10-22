<?php

declare(strict_types=1);

namespace Dashboard\Settings;

/**
 * What the browser is allowed to see of a stored secret.
 */
final class SecretMask
{
    public static function hint(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return '';
        }
        if (strlen($secret) >= 8) {
            return substr($secret, -4);
        }
        return 'set';
    }
}
