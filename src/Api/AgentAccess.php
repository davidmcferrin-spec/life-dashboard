<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\Api\V1\Scope;
use Dashboard\Audit\AuditLog;
use Dashboard\Audit\TokenStamp;
use Dashboard\Auth\ApiTokenService;
use Dashboard\DB\Database;

/**
 * Lets a bearer token call the older JSON APIs that the dashboard UI already uses.
 * Files, favicons, cloud connections, public share links, and CloudPad stay on the browser session.
 */
final class AgentAccess
{
    /** @var list<string> */
    private const OPEN = ['bookmarks', 'todos', 'flights', 'packages', 'stocks', 'clipboards'];

    /** @var list<string> */
    private const CLOSED = ['files', 'storage', 'cloudpad'];

    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    /**
     * @return array<string, mixed>|null
     */
    public static function currentUser(): ?array
    {
        return self::$user;
    }

    public static function prepare(Database $db): void
    {
        $bearer = self::bearerToken();
        if ($bearer === null) {
            return;
        }
        $path = self::path();
        $kind = self::classify($path);
        if ($kind === 'deny') {
            jsonResponse(['error' => 'Not available to an agent.'], 403);
        }
        if ($kind !== 'allow') {
            return;
        }

        $row = (new ApiTokenService($db))->authenticate($bearer);
        if ($row === null) {
            jsonResponse(['error' => 'Invalid or expired token.'], 401);
        }
        $write = !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true);
        $required = Scope::forPath($path, $write);
        if (!Scope::allows($row['scopes'], $required)) {
            jsonResponse(['error' => 'Token is missing the ' . $required . ' scope.'], 403);
        }
        $user = $db->fetchOne(
            'SELECT id, username, email, is_admin, created_at, last_login_at, last_active_at FROM users WHERE id = ?',
            [$row['user_id']]
        );
        if ($user === null) {
            jsonResponse(['error' => 'Invalid or expired token.'], 401);
        }
        self::$user = $user;
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'HEAD') {
            return;
        }
        $tool = $_SERVER['HTTP_X_LIFEDASH_TOOL'] ?? '';
        (new AuditLog($db))->record(
            $row['user_id'],
            'ai',
            is_string($tool) && trim($tool) !== '' ? trim($tool) : null,
            $write ? 'POST' : 'GET',
            $path,
            TokenStamp::detail((int) $row['id'], (string) $row['name'], $write ? 'write' : 'read')
        );
    }

    private static function classify(string $path): string
    {
        if (!str_starts_with($path, '/api/') || str_starts_with($path, '/api/v1/')) {
            return 'ignore';
        }
        foreach (['/favicon', '/capture', '/shares/link'] as $part) {
            if (str_contains($path, $part)) {
                return 'deny';
            }
        }
        $segment = explode('/', substr($path, strlen('/api/')), 2)[0];
        if (in_array($segment, self::OPEN, true)) {
            return 'allow';
        }
        if (in_array($segment, self::CLOSED, true)) {
            return 'deny';
        }
        return 'ignore';
    }

    private static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        return is_string($path) && $path !== '' ? $path : '/';
    }

    private static function bearerToken(): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $value = $_SERVER[$key] ?? '';
            if (is_string($value) && preg_match('/^Bearer\s+(\S+)/i', $value, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}
