<?php

declare(strict_types=1);

namespace Dashboard\User;

use Dashboard\Auth\Auth;
use Dashboard\Clipboard\ClipboardService;
use Dashboard\DB\Database;
use Dashboard\Flight\FlightWatchService;
use Dashboard\Ping\PingService;
use Dashboard\Settings\FeedPreferenceService;
use Dashboard\Stock\StockService;

/**
 * Admin-only account management (no access to another user's dashboard data).
 */
final class UserService
{
    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccounts(): array
    {
        return $this->db->fetchAll(
            'SELECT id, username, email, is_admin, created_at, last_login_at, last_active_at
             FROM users
             ORDER BY username ASC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAccount(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, username, email, is_admin, created_at, last_login_at, last_active_at FROM users WHERE id = ?',
            [$id]
        );
    }

    public function createAccount(string $username, string $email, string $password, bool $isAdmin): int
    {
        $this->validateUsername($username);
        $this->validateEmail($email);
        $this->validatePassword($password);

        if ($this->usernameExists($username)) {
            throw new \InvalidArgumentException('Username is already taken.');
        }

        if ($this->emailExists($email)) {
            throw new \InvalidArgumentException('Email is already in use.');
        }

        $userId = $this->auth->createUser($username, $email, $password, $isAdmin);
        $this->seedWidgetDefaults($userId);

        return $userId;
    }

    private function seedWidgetDefaults(int $userId): void
    {
        FlightWatchService::fromDatabase($this->db)->ensureDefaults($userId);
        StockService::fromDatabase($this->db)->ensureDefaults($userId);
        PingService::fromDatabase($this->db)->ensureDefaults($userId);
        ClipboardService::fromDatabase($this->db)->ensureDefaults($userId);
        FeedPreferenceService::fromDatabase($this->db)->seedDashboardPins($userId);
    }

    public function updateAccount(
        int $id,
        string $username,
        string $email,
        bool $isAdmin,
        int $actingUserId,
    ): void {
        $existing = $this->findAccount($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $this->validateUsername($username);
        $this->validateEmail($email);

        if ($this->usernameExists($username, $id)) {
            throw new \InvalidArgumentException('Username is already taken.');
        }

        if ($this->emailExists($email, $id)) {
            throw new \InvalidArgumentException('Email is already in use.');
        }

        $wasAdmin = (int) ($existing['is_admin'] ?? 0) === 1;
        if ($wasAdmin && !$isAdmin) {
            $this->assertNotLastAdmin($id);
            if ($id === $actingUserId) {
                throw new \InvalidArgumentException('You cannot remove your own admin access.');
            }
        }

        $this->db->execute(
            'UPDATE users SET username = ?, email = ?, is_admin = ? WHERE id = ?',
            [$username, $email, $isAdmin ? 1 : 0, $id]
        );
    }

    public function setPassword(int $id, string $password): void
    {
        if ($this->findAccount($id) === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $this->validatePassword($password);
        $this->auth->changePassword($id, $password);
    }

    public function deleteAccount(int $id, int $actingUserId): void
    {
        if ($id === $actingUserId) {
            throw new \InvalidArgumentException('You cannot delete your own account while logged in.');
        }

        $existing = $this->findAccount($id);
        if ($existing === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        if ((int) ($existing['is_admin'] ?? 0) === 1) {
            $this->assertNotLastAdmin($id);
        }

        $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function countAdmins(): int
    {
        return (int) $this->db->fetchScalar('SELECT COUNT(*) FROM users WHERE is_admin = 1');
    }

    private function assertNotLastAdmin(int $userId): void
    {
        if ($this->countAdmins() <= 1) {
            $only = $this->db->fetchOne('SELECT id FROM users WHERE is_admin = 1 LIMIT 1');
            if ($only !== null && (int) $only['id'] === $userId) {
                throw new \InvalidArgumentException('Cannot remove or delete the only admin account.');
            }
        }
    }

    private function usernameExists(string $username, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT id FROM users WHERE username = ?';
        $params = [$username];
        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }

        return $this->db->fetchOne($sql, $params) !== null;
    }

    private function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql    = 'SELECT id FROM users WHERE email = ?';
        $params = [$email];
        if ($exceptId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $exceptId;
        }

        return $this->db->fetchOne($sql, $params) !== null;
    }

    private function validateUsername(string $username): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]{3,64}$/', $username)) {
            throw new \InvalidArgumentException('Username must be 3–64 characters (letters, numbers, underscore).');
        }
    }

    private function validateEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address.');
        }
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }
    }
}
