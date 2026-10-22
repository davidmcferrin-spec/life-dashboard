<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\Api\AgentAccess;

/**
 * Setup, tunnel, and the plain-HTTP switch are dashboard forms.
 * A bearer on the request is an agent, even when a browser session is also present.
 */
final class BrowserOnly
{
    public const DENIED = 'These settings can only be changed from a signed-in browser.';

    /**
     * @param array<string, mixed> $server
     */
    public static function blocked(array $server, bool $agentSession): bool
    {
        if ($agentSession) {
            return true;
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $header = $server[$key] ?? '';
            if (is_string($header) && preg_match('/^\s*Bearer\s+\S/i', $header) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function reject(): void
    {
        if (!self::blocked($_SERVER, AgentAccess::currentUser() !== null)) {
            return;
        }
        jsonResponse(['error' => self::DENIED], 403);
    }
}
