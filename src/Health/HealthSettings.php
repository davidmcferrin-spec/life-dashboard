<?php

declare(strict_types=1);

namespace Dashboard\Health;

use Dashboard\DB\Database;
use Dashboard\Settings\SecretMask;
use Dashboard\Settings\SystemSettingsRepository;

/**
 * Open Wearables URL, API key, and user id.
 * The key stays in the encrypted system_settings row and is never returned.
 */
final class HealthSettings
{
    public const KEY = 'open_wearables';

    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input, int $userId): void
    {
        $stored = $this->read();
        $url = self::url((string) ($input['api_url'] ?? ''));
        if ($url === '') {
            unset($stored['api_url']);
        } else {
            $stored['api_url'] = $url;
        }
        $owUser = self::userId((string) ($input['user_id'] ?? ''));
        if ($owUser === '') {
            unset($stored['user_id']);
        } else {
            $stored['user_id'] = $owUser;
        }
        $secret = trim((string) ($input['api_secret'] ?? ''));
        if ($secret !== '') {
            self::assertSecret($secret);
            $stored['api_secret'] = $secret;
        }
        (new SystemSettingsRepository($this->db))->setJson(self::KEY, $stored, $userId);
    }

    /**
     * @return array{api_url: string, user_id: string, secret_hint: string, configured: bool}
     */
    public function view(): array
    {
        $stored = $this->read();

        return [
            'api_url'     => $stored['api_url'] ?? '',
            'user_id'     => $stored['user_id'] ?? '',
            'secret_hint' => SecretMask::hint($stored['api_secret'] ?? ''),
            'configured'  => self::configured($stored),
        ];
    }

    /**
     * @return array{base: string, token: string, user_id: string}|null
     */
    public function connection(): ?array
    {
        $stored = $this->read();
        if (!self::configured($stored)) {
            return null;
        }

        return [
            'base'    => rtrim((string) $stored['api_url'], '/'),
            'token'   => (string) $stored['api_secret'],
            'user_id' => (string) $stored['user_id'],
        ];
    }

    public static function url(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return '';
        }
        $parts = parse_url($text);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Open Wearables URL must be http or https with a host.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Open Wearables URL must be http or https with a host.');
        }
        if (strlen($text) > 300) {
            throw new \InvalidArgumentException('Open Wearables URL is too long.');
        }

        return rtrim($text, '/');
    }

    public static function userId(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^[0-9a-fA-F-]{32,40}$/', $text) !== 1) {
            throw new \InvalidArgumentException('Open Wearables user id must be a UUID.');
        }

        return $text;
    }

    public static function assertSecret(string $secret): void
    {
        if ($secret === '' || strlen($secret) > 500 || preg_match('/[\r\n\0]/', $secret) === 1) {
            throw new \InvalidArgumentException('That API key cannot be saved.');
        }
    }

    /**
     * @param array<string, string> $stored
     */
    private static function configured(array $stored): bool
    {
        return ($stored['api_url'] ?? '') !== ''
            && ($stored['api_secret'] ?? '') !== ''
            && ($stored['user_id'] ?? '') !== '';
    }

    /**
     * @return array<string, string>
     */
    private function read(): array
    {
        $raw = (new SystemSettingsRepository($this->db))->getJson(self::KEY);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (['api_url', 'api_secret', 'user_id'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && $raw[$key] !== '') {
                $out[$key] = $raw[$key];
            }
        }

        return $out;
    }
}
