<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\CloudStorage\CredentialCrypto;
use Dashboard\DB\Database;
use Dashboard\Settings\SecretMask;
use Dashboard\Support\ValidationException;

/**
 * Plaid app keys for one user. The secret stays encrypted in user_preferences
 * and is never returned to the browser or the MCP.
 */
final class PlaidSettings
{
    public const PREF_KEY = 'plaid_app';

    /** @var list<string> */
    public const ENVIRONMENTS = ['sandbox', 'development', 'production'];

    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $input
     * @return array{client_id_hint: string, secret_hint: string, environment: string, configured: bool}
     */
    public function save(int $userId, array $input): array
    {
        $current = $this->read($userId);
        $clientId = array_key_exists('client_id', $input) ? trim((string) $input['client_id']) : '';
        if ($clientId === '') {
            $clientId = (string) ($current['client_id'] ?? '');
        }
        $secret = array_key_exists('secret', $input) ? trim((string) $input['secret']) : '';
        if ($secret === '') {
            $secret = (string) ($current['secret'] ?? '');
        }
        $environment = array_key_exists('environment', $input)
            ? strtolower(trim((string) $input['environment']))
            : (string) ($current['environment'] ?? 'sandbox');
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw ValidationException::withFields(['environment' => 'Environment must be sandbox, development, or production.']);
        }
        if ($clientId === '' || preg_match('/^[A-Za-z0-9]{8,80}$/', $clientId) !== 1) {
            throw ValidationException::withFields(['client_id' => 'Enter the Plaid client id.']);
        }
        if ($secret === '' || strlen($secret) < 8 || strlen($secret) > 200 || preg_match('/[\r\n\0]/', $secret) === 1) {
            throw ValidationException::withFields(['secret' => 'Enter the Plaid secret.']);
        }
        $this->write($userId, [
            'client_id'   => $clientId,
            'secret'      => $secret,
            'environment' => $environment,
        ]);

        return $this->view($userId);
    }

    /**
     * @return array{client_id_hint: string, secret_hint: string, environment: string, configured: bool}
     */
    public function view(int $userId): array
    {
        $stored = $this->read($userId);

        return [
            'client_id_hint' => SecretMask::hint($stored['client_id'] ?? ''),
            'secret_hint'    => SecretMask::hint($stored['secret'] ?? ''),
            'environment'    => in_array($stored['environment'] ?? '', self::ENVIRONMENTS, true)
                ? (string) $stored['environment']
                : 'sandbox',
            'configured'     => $this->configured($stored),
        ];
    }

    /**
     * @return array{client_id: string, secret: string, environment: string}|null
     */
    public function credentials(int $userId): ?array
    {
        $stored = $this->read($userId);
        if (!$this->configured($stored)) {
            return null;
        }

        return [
            'client_id'   => (string) $stored['client_id'],
            'secret'      => (string) $stored['secret'],
            'environment' => (string) $stored['environment'],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function configured(array $stored): bool
    {
        return ($stored['client_id'] ?? '') !== ''
            && ($stored['secret'] ?? '') !== ''
            && in_array($stored['environment'] ?? '', self::ENVIRONMENTS, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, self::PREF_KEY]
        );
        if ($row === null || trim((string) $row['pref_value']) === '') {
            return [];
        }
        try {
            $decoded = CredentialCrypto::decryptJson((string) $row['pref_value']);
        } catch (\RuntimeException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $value
     */
    private function write(int $userId, array $value): void
    {
        $encrypted = CredentialCrypto::encryptJson($value);
        $existing = $this->db->fetchOne(
            'SELECT id FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, self::PREF_KEY]
        );
        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, ?, ?)',
                [$userId, self::PREF_KEY, $encrypted]
            );
            return;
        }
        $this->db->execute(
            'UPDATE user_preferences SET pref_value = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND pref_key = ?',
            [$encrypted, $userId, self::PREF_KEY]
        );
    }
}
