<?php

declare(strict_types=1);

namespace Dashboard\WakeHub;

use Dashboard\CloudStorage\CredentialCrypto;
use Dashboard\DB\Database;
use Dashboard\Settings\SecretMask;
use Dashboard\Support\ValidationException;

/**
 * One user's Wake Hub origin and bearer token.
 * The token stays encrypted and is never returned.
 */
final class WakeHubSettings
{
    public const PREF_KEY = 'wake_hub';

    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $input
     * @return array{base_url: string, token_hint: string, configured: bool}
     */
    public function save(int $userId, array $input): array
    {
        $current = $this->read($userId);
        $url = array_key_exists('base_url', $input)
            ? self::baseUrl((string) $input['base_url'])
            : (string) ($current['base_url'] ?? '');
        if ($url === '') {
            throw ValidationException::withFields(['base_url' => 'Enter the Wake Hub base URL, including the host and port.']);
        }
        $token = array_key_exists('token', $input) ? trim((string) $input['token']) : '';
        if ($token === '') {
            $token = (string) ($current['token'] ?? '');
        } else {
            $token = self::token($token);
        }
        if ($token === '') {
            throw ValidationException::withFields(['token' => 'Enter the Wake Hub bearer token.']);
        }
        $this->write($userId, [
            'base_url' => $url,
            'token'    => $token,
        ]);

        return $this->view($userId);
    }

    /**
     * @return array{base_url: string, token_hint: string, configured: bool}
     */
    public function view(int $userId): array
    {
        $stored = $this->read($userId);
        $url = trim((string) ($stored['base_url'] ?? ''));
        $token = (string) ($stored['token'] ?? '');

        return [
            'base_url'   => $url,
            'token_hint' => $token !== '' ? SecretMask::hint($token) : '',
            'configured' => $this->configured($stored),
        ];
    }

    /**
     * @return array{base_url: string, token: string}|null
     */
    public function credentials(int $userId): ?array
    {
        $stored = $this->read($userId);
        if (!$this->configured($stored)) {
            return null;
        }

        return [
            'base_url' => (string) $stored['base_url'],
            'token'    => (string) $stored['token'],
        ];
    }

    public static function baseUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw ValidationException::withFields(['base_url' => 'Enter the Wake Hub base URL, including the host and port.']);
        }
        if (strlen($raw) > 200) {
            throw ValidationException::withFields(['base_url' => 'That URL is too long.']);
        }
        $parts = parse_url($raw);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw ValidationException::withFields(['base_url' => 'Enter a URL like http://host:port. No host or port is assumed.']);
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw ValidationException::withFields(['base_url' => 'The URL must start with http:// or https://.']);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withFields(['base_url' => 'Put the token in the token field, not in the URL.']);
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withFields(['base_url' => 'Use the origin only, without a query or fragment.']);
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            throw ValidationException::withFields(['base_url' => 'Use the Wake Hub origin only, such as http://host:port.']);
        }
        $host = (string) $parts['host'];
        if (str_contains($host, ' ') || str_contains($host, '\\')) {
            throw ValidationException::withFields(['base_url' => 'That host is not valid.']);
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw ValidationException::withFields(['base_url' => 'The port must be between 1 and 65535.']);
        }
        $origin = $scheme . '://' . $host;
        if ($port !== null) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    public static function token(string $raw): string
    {
        $token = trim($raw);
        if ($token === '' || strlen($token) > 500 || preg_match('/\s/', $token) === 1) {
            throw ValidationException::withFields(['token' => 'Enter the bearer token from Wake Hub.']);
        }

        return $token;
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function configured(array $stored): bool
    {
        return trim((string) ($stored['base_url'] ?? '')) !== ''
            && trim((string) ($stored['token'] ?? '')) !== '';
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
