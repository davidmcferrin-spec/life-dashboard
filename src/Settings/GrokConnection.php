<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\DB\Database;

/**
 * Server Grok API URL and secret.
 * The secret stays in the encrypted system_settings row and is never returned.
 */
final class GrokConnection
{
    public const KEY = 'grok';

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

        $secret = trim((string) ($input['api_secret'] ?? ''));
        if ($secret !== '') {
            self::assertSecret($secret);
            $stored['api_secret'] = $secret;
        }

        (new SystemSettingsRepository($this->db))->setJson(self::KEY, $stored, $userId);
    }

    /**
     * @return array{api_url: string, secret_hint: string}
     */
    public function view(): array
    {
        $stored = $this->read();

        return [
            'api_url'     => $stored['api_url'] ?? '',
            'secret_hint' => SecretMask::hint($stored['api_secret'] ?? ''),
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
            throw new \InvalidArgumentException('Grok API URL must be http or https with a host.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Grok API URL must be http or https with a host.');
        }
        if (strlen($text) > 300) {
            throw new \InvalidArgumentException('Grok API URL is too long.');
        }

        return $text;
    }

    public static function assertSecret(string $secret): void
    {
        if ($secret === '' || strlen($secret) > 500 || preg_match('/[\r\n\0]/', $secret) === 1) {
            throw new \InvalidArgumentException('That API secret cannot be saved.');
        }
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
        foreach (['api_url', 'api_secret'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && $raw[$key] !== '') {
                $out[$key] = $raw[$key];
            }
        }

        return $out;
    }
}
