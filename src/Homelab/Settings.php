<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\CloudStorage\CredentialCrypto;
use Dashboard\DB\Database;
use Dashboard\Settings\SecretMask;
use Dashboard\Support\ValidationException;

/**
 * One encrypted blob for every homelab app. Secrets stay in the blob and are returned only to the client that calls the app.
 */
final class Settings
{
    public const PREF_KEY = 'homelab';

    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(int $userId, string $service, array $input): array
    {
        $meta = Catalog::get($service);
        $all = $this->readAll($userId);
        $current = is_array($all[$service] ?? null) ? $all[$service] : [];
        $enabled = array_key_exists('enabled', $input)
            ? self::flag($input['enabled'])
            : (bool) ($current['enabled'] ?? false);
        $url = array_key_exists('base_url', $input)
            ? self::baseUrl((string) $input['base_url'], $enabled)
            : trim((string) ($current['base_url'] ?? ''));
        if ($service === 'qbittorrent') {
            return $this->saveQbit($userId, $input, $all, $current, $enabled, $url);
        }
        $secretName = $meta['secret'];
        $secret = array_key_exists($secretName, $input) ? (string) $input[$secretName] : '';
        if (trim($secret) === '') {
            $secret = (string) ($current[$secretName] ?? '');
        } else {
            $secret = self::secret($secret, $secretName, $meta['secret_label']);
        }
        $row = [
            'enabled' => $enabled,
            'base_url' => $url,
            $secretName => $secret,
        ];
        if ($meta['username']) {
            $username = array_key_exists('username', $input)
                ? trim((string) $input['username'])
                : trim((string) ($current['username'] ?? ''));
            if ($enabled && $username === '') {
                throw ValidationException::withFields(['username' => 'Enter the ' . $meta['label'] . ' username.']);
            }
            if (strlen($username) > 80) {
                throw ValidationException::withFields(['username' => 'That username is too long.']);
            }
            $row['username'] = $username;
        }
        if ($enabled && ($url === '' || $secret === '')) {
            $field = $url === '' ? 'base_url' : $secretName;
            $label = $url === '' ? 'base URL' : strtolower($meta['secret_label']);
            throw ValidationException::withFields([$field => 'Enter the ' . $meta['label'] . ' ' . $label . '.']);
        }
        $all[$service] = $row;
        $this->writeAll($userId, $all);

        return $this->viewOne($userId, $service);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function view(int $userId): array
    {
        $out = [];
        foreach (Catalog::ids() as $service) {
            $out[$service] = $this->viewOne($userId, $service);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function viewOne(int $userId, string $service): array
    {
        $meta = Catalog::get($service);
        $stored = $this->stored($userId, $service) ?? [];
        $secret = (string) ($stored[$meta['secret']] ?? '');
        $hint = $secret !== '' ? SecretMask::hint($secret) : '';
        $url = trim((string) ($stored['base_url'] ?? ''));
        $configured = $url !== '' && $secret !== '';
        if ($meta['username']) {
            $configured = $configured && trim((string) ($stored['username'] ?? '')) !== '';
        }
        $view = [
            'service' => $service,
            'label' => $meta['label'],
            'icon' => $meta['icon'],
            'enabled' => (bool) ($stored['enabled'] ?? false),
            'base_url' => $url,
            'configured' => $configured,
            'ready' => $configured && (bool) ($stored['enabled'] ?? false),
            'secret_name' => $meta['secret'],
            'secret_label' => $meta['secret_label'],
            'secret_hint' => $hint,
            'where' => $meta['where'],
        ];
        if ($meta['username']) {
            $view['username'] = trim((string) ($stored['username'] ?? ''));
        }
        if ($service === 'qbittorrent') {
            $mode = self::qbitAuth(is_array($stored) ? $stored : []);
            $token = (string) ($stored['token'] ?? '');
            $view['auth'] = $mode;
            $view['token_hint'] = $token !== '' ? SecretMask::hint($token) : '';
            $configured = $url !== '' && match ($mode) {
                'none' => true,
                'token' => $token !== '',
                default => trim((string) ($stored['username'] ?? '')) !== '' && $secret !== '',
            };
            $view['configured'] = $configured;
            $view['ready'] = $configured && (bool) ($stored['enabled'] ?? false);
        }

        return $view;
    }

    public static function qbitAuth(array $stored): string
    {
        $raw = strtolower(trim((string) ($stored['auth'] ?? '')));
        if ($raw === '') {
            return 'password';
        }
        if ($raw !== 'token' && $raw !== 'password' && $raw !== 'none') {
            throw ValidationException::withFields(['auth' => 'auth must be token, password, or none.']);
        }

        return $raw;
    }

    /**
     * Credentials for a call. Null when the URL or secret has not been saved.
     *
     * @return array<string, mixed>|null
     */
    public function stored(int $userId, string $service): ?array
    {
        Catalog::get($service);
        $all = $this->readAll($userId);
        $row = $all[$service] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $all
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function saveQbit(int $userId, array $input, array $all, array $current, bool $enabled, string $url): array
    {
        $mode = array_key_exists('auth', $input) && trim((string) $input['auth']) !== ''
            ? self::qbitAuth(['auth' => $input['auth']])
            : self::qbitAuth($current);
        $username = array_key_exists('username', $input)
            ? trim((string) $input['username'])
            : trim((string) ($current['username'] ?? ''));
        if (strlen($username) > 80) {
            throw ValidationException::withFields(['username' => 'That username is too long.']);
        }
        $password = $this->keptSecret($input, $current, 'password', 'Password');
        $token = $this->keptSecret($input, $current, 'token', 'API token');
        if ($enabled && $url === '') {
            throw ValidationException::withFields(['base_url' => 'Enter the qBittorrent base URL.']);
        }
        if ($enabled && $mode === 'password' && ($username === '' || $password === '')) {
            $field = $username === '' ? 'username' : 'password';
            throw ValidationException::withFields([$field => 'Enter the qBittorrent username and password.']);
        }
        if ($enabled && $mode === 'token' && $token === '') {
            throw ValidationException::withFields(['token' => 'Enter the qBittorrent API token.']);
        }
        $all['qbittorrent'] = [
            'enabled' => $enabled,
            'base_url' => $url,
            'auth' => $mode,
            'username' => $username,
            'password' => $password,
            'token' => $token,
        ];
        $this->writeAll($userId, $all);

        return $this->viewOne($userId, 'qbittorrent');
    }

    /**
     * A blank secret field keeps the saved value. A new value replaces it.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $current
     */
    private function keptSecret(array $input, array $current, string $field, string $label): string
    {
        if (!array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
            return (string) ($current[$field] ?? '');
        }
        return self::secret((string) $input[$field], $field, $label);
    }

    public static function baseUrl(string $raw, bool $required): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            if ($required) {
                throw ValidationException::withFields(['base_url' => 'Enter the base URL, including the host and port.']);
            }

            return '';
        }
        if (strlen($raw) > 300) {
            throw ValidationException::withFields(['base_url' => 'That URL is too long.']);
        }
        $parts = parse_url($raw);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw ValidationException::withFields(['base_url' => 'Enter a URL like http://host:port.']);
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw ValidationException::withFields(['base_url' => 'The URL must start with http:// or https://.']);
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withFields(['base_url' => 'Put the key or password in its own field, not in the URL.']);
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withFields(['base_url' => 'Leave the query and fragment off the base URL.']);
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path === '/') {
            $path = '';
        }
        $path = rtrim($path, '/');
        if (str_contains($path, ':') || str_contains($path, '\\') || str_contains($path, ' ')) {
            throw ValidationException::withFields(['base_url' => 'That URL path is not valid.']);
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

        return $origin . $path;
    }

    public static function secret(string $raw, string $field, string $label): string
    {
        $secret = trim($raw);
        if ($secret === '' || strlen($secret) > 500 || str_contains($secret, "\n") || str_contains($secret, "\r")) {
            throw ValidationException::withFields([$field => 'Enter the ' . strtolower($label) . '.']);
        }
        if (($field === 'api_key' || $field === 'token') && preg_match('/\s/', $secret) === 1) {
            throw ValidationException::withFields([$field => 'That API key is not valid.']);
        }

        return $secret;
    }

    public static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }

        throw ValidationException::withFields(['enabled' => 'enabled must be true or false.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function readAll(int $userId): array
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
     * @param array<string, mixed> $value
     */
    private function writeAll(int $userId, array $value): void
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
