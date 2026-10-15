<?php

declare(strict_types=1);

namespace Dashboard\Auth;

use Dashboard\Config;
use Dashboard\DB\Database;
use DateTimeImmutable;
use DateInterval;

/**
 * Auth — session-based authentication with auto-login token support.
 *
 * Auto-login URL pattern: /auto/{64-char hex token}
 * Tokens are per-user, have optional expiry, and can be revoked.
 */
class Auth
{
    private const SESSION_KEY  = 'dashboard_user_id';
    private const SESSION_HASH = 'dashboard_session_hash';
    private const ACTIVE_TOUCH_KEY = 'dashboard_active_touch';
    private const ACTIVE_TOUCH_SECONDS = 900;

    public function __construct(private readonly Database $db) {}

    // -------------------------------------------------------
    // Session auth
    // -------------------------------------------------------

    /**
     * Attempt login with username/email + password.
     * Returns user array on success, null on failure.
     *
     * @return array<string, mixed>|null
     */
    public function attempt(string $identifier, string $password): ?array
    {
        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE username = ? OR email = ?',
            [$identifier, $identifier]
        );

        if ($user === null) {
            // Constant-time compare even on miss to prevent timing attacks
            password_verify($password, '$2y$12$invalidhashpadding000000000000000000000000000000000000000');
            return null;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        $this->startSession($user);
        return $user;
    }

    /**
     * Authenticate via a 64-char auto-login token.
     * Returns user array on success, null on failure.
     *
     * @return array<string, mixed>|null
     */
    public function attemptWithToken(string $token): ?array
    {
        if (!$this->isValidTokenFormat($token)) {
            return null;
        }

        $record = $this->db->fetchOne(
            "SELECT t.*, u.* FROM autologin_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token = ?
               AND t.revoked = 0
               AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP)",
            [$token]
        );

        if ($record === null) {
            return null;
        }

        // Update last used timestamp
        $this->db->execute(
            'UPDATE autologin_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE token = ?',
            [$token]
        );

        // Build user array (avoid key collisions from JOIN)
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$record['user_id']]);

        if ($user === null) {
            return null;
        }

        $this->startSession($user);
        return $user;
    }

    /**
     * Check if current request has a valid authenticated session.
     *
     * @return array<string, mixed>|null Current user or null
     */
    public function user(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->initSession();
        }

        $userId = $_SESSION[self::SESSION_KEY] ?? null;

        if ($userId === null) {
            return null;
        }

        // Validate session integrity hash
        $expectedHash = $this->sessionHash((int) $userId);
        if (($_SESSION[self::SESSION_HASH] ?? '') !== $expectedHash) {
            $this->logout();
            return null;
        }

        return $this->db->fetchOne(
            'SELECT id, username, email, is_admin, created_at, last_login_at, last_active_at FROM users WHERE id = ?',
            [(int) $userId]
        );
    }

    /**
     * Throttled update of last dashboard activity (not every API poll).
     */
    public function touchLastActive(int $userId): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $now       = time();
        $lastTouch = (int) ($_SESSION[self::ACTIVE_TOUCH_KEY] ?? 0);
        if ($now - $lastTouch < self::ACTIVE_TOUCH_SECONDS) {
            return false;
        }

        $_SESSION[self::ACTIVE_TOUCH_KEY] = $now;

        $this->db->execute(
            'UPDATE users SET last_active_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$userId]
        );

        return true;
    }

    /**
     * Require authentication — redirect to login if not authed.
     *
     * @return array<string, mixed> Guaranteed user array
     */
    public function requireAuth(): array
    {
        $agent = \Dashboard\Api\AgentAccess::currentUser();
        if ($agent !== null) {
            return $agent;
        }

        $user = $this->user();

        if ($user === null) {
            header('Location: /login');
            exit;
        }

        if ($this->touchLastActive((int) $user['id'])) {
            $fresh = $this->db->fetchOne(
                'SELECT id, username, email, is_admin, created_at, last_login_at, last_active_at FROM users WHERE id = ?',
                [(int) $user['id']]
            );
            if ($fresh !== null) {
                $user = $fresh;
            }
        }

        return $user;
    }

    /**
     * JSON API variant of requireAuth(): 401 JSON instead of a login redirect,
     * so fetch() callers (and later MCP clients) get a clear error.
     *
     * @return array<string, mixed>
     */
    public function requireApiUser(): array
    {
        if ($this->user() === null) {
            jsonResponse(['error' => 'Not authenticated.'], 401);
        }
        return $this->requireAuth();
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function isAdmin(?array $user): bool
    {
        return $user !== null && (int) ($user['is_admin'] ?? 0) === 1;
    }

    /**
     * Require an authenticated admin — non-admins get 403.
     *
     * @return array<string, mixed>
     */
    public function requireAdmin(): array
    {
        $user = $this->requireAuth();

        if (!$this->isAdmin($user)) {
            http_response_code(403);
            require VIEWS_DIR . '/errors/403.php';
            exit;
        }

        return $user;
    }

    /**
     * Destroy current session and log out.
     * The session is not started until something reads it, so /logout must open it first.
     */
    public function logout(): void
    {
        $this->initSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    // -------------------------------------------------------
    // Auto-login token management
    // -------------------------------------------------------

    /**
     * Create a new auto-login token for a user.
     *
     * @param int         $userId
     * @param string|null $label     Human label for token management UI
     * @param string|null $expiresIn DateInterval string e.g. 'P30D' (30 days), null = no expiry
     * @return string The 64-char hex token
     */
    public function createToken(int $userId, ?string $label = null, ?string $expiresIn = null): string
    {
        $token = bin2hex(random_bytes(32)); // 64 hex chars

        $expiresAt = null;
        if ($expiresIn !== null) {
            $expiresAt = (new DateTimeImmutable())
                ->add(new DateInterval($expiresIn))
                ->format('Y-m-d H:i:s');
        }

        $this->db->execute(
            'INSERT INTO autologin_tokens (user_id, token, label, expires_at) VALUES (?, ?, ?, ?)',
            [$userId, $token, $label, $expiresAt]
        );

        return $token;
    }

    /**
     * Revoke a specific token by its ID (not the token value — safer for UI actions).
     */
    public function revokeToken(int $tokenId, int $userId): bool
    {
        $affected = $this->db->execute(
            'UPDATE autologin_tokens SET revoked = 1 WHERE id = ? AND user_id = ?',
            [$tokenId, $userId]
        );

        return $affected > 0;
    }

    /**
     * Regenerate a token — revokes old, issues new with same label/expiry.
     * Returns new token string.
     */
    public function regenerateToken(int $tokenId, int $userId): ?string
    {
        $existing = $this->db->fetchOne(
            'SELECT * FROM autologin_tokens WHERE id = ? AND user_id = ? AND revoked = 0',
            [$tokenId, $userId]
        );

        if ($existing === null) {
            return null;
        }

        $this->db->transaction(function (Database $db) use ($tokenId): void {
            $db->execute(
                'UPDATE autologin_tokens SET revoked = 1 WHERE id = ?',
                [$tokenId]
            );
        });

        return $this->createToken(
            $userId,
            (string) ($existing['label'] ?? ''),
            null // preserve expiry logic by re-passing original expires_at below
        );
    }

    /**
     * Update expiry on an existing token.
     * Pass null to remove expiry (never expires).
     */
    public function setTokenExpiry(int $tokenId, int $userId, ?string $expiresIn): bool
    {
        $expiresAt = null;
        if ($expiresIn !== null) {
            $expiresAt = (new DateTimeImmutable())
                ->add(new DateInterval($expiresIn))
                ->format('Y-m-d H:i:s');
        }

        $affected = $this->db->execute(
            'UPDATE autologin_tokens SET expires_at = ? WHERE id = ? AND user_id = ?',
            [$expiresAt, $tokenId, $userId]
        );

        return $affected > 0;
    }

    /**
     * Get all tokens for a user (for management UI).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTokens(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT id, label, expires_at, last_used_at, created_at, revoked,
                    CASE WHEN expires_at IS NOT NULL AND expires_at < CURRENT_TIMESTAMP
                         THEN 1 ELSE 0 END AS is_expired
             FROM autologin_tokens
             WHERE user_id = ?
             ORDER BY created_at DESC",
            [$userId]
        );
    }

    // -------------------------------------------------------
    // User management
    // -------------------------------------------------------

    /**
     * Create a new user. Returns user ID.
     */
    public function createUser(string $username, string $email, string $password, bool $isAdmin = false): int
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            [$username, $email, $hash, (int) $isAdmin]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Change a user's password.
     */
    public function changePassword(int $userId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->db->execute(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [$hash, $userId]
        );
    }

    // -------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------

    private function startSession(array $user): void
    {
        $this->initSession();
        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY]  = $user['id'];
        $_SESSION[self::SESSION_HASH] = $this->sessionHash((int) $user['id']);

        $this->db->execute(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP, last_active_at = CURRENT_TIMESTAMP WHERE id = ?',
            [(int) $user['id']]
        );
    }

    private function initSession(): void
    {
        initAppSession();
    }

    /**
     * HMAC hash tied to user ID + app secret — invalidates session if secret rotates.
     */
    private function sessionHash(int $userId): string
    {
        return hash_hmac('sha256', (string) $userId, Config::get('APP_SECRET'));
    }

    private function isValidTokenFormat(string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }
}
