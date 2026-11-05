<?php

declare(strict_types=1);

namespace Dashboard\Home;

use Dashboard\DB\Database;
use Dashboard\Health\HealthSettings;
use Dashboard\Settings\SecretMask;
use Dashboard\Settings\SystemSettingsRepository;

/**
 * Home Assistant URL and long-lived token, encrypted with the other settings.
 * The token is not returned to the browser.
 */
final class HomeSettings
{
    public const KEY = 'home_assistant';

    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $input
     */
    public function save(array $input, int $userId): void
    {
        $stored = $this->read();
        $url = HealthSettings::url((string) ($input['base_url'] ?? ''));
        if ($url === '') {
            unset($stored['base_url']);
        } else {
            $stored['base_url'] = $url;
        }
        $secret = trim((string) ($input['api_secret'] ?? ''));
        if ($secret !== '') {
            HealthSettings::assertSecret($secret);
            $stored['token'] = $secret;
        }
        (new SystemSettingsRepository($this->db))->setJson(self::KEY, $stored, $userId);
    }

    /**
     * @return array{base_url: string, secret_hint: string, configured: bool}
     */
    public function view(): array
    {
        $stored = $this->read();

        return [
            'base_url'    => $stored['base_url'] ?? '',
            'secret_hint' => SecretMask::hint($stored['token'] ?? ''),
            'configured'  => ($stored['base_url'] ?? '') !== '' && ($stored['token'] ?? '') !== '',
        ];
    }

    /**
     * @return array{base: string, token: string}|null
     */
    public function connection(): ?array
    {
        $stored = $this->read();
        if (($stored['base_url'] ?? '') === '' || ($stored['token'] ?? '') === '') {
            return null;
        }

        return ['base' => rtrim((string) $stored['base_url'], '/'), 'token' => (string) $stored['token']];
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
        foreach (['base_url', 'token'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && $raw[$key] !== '') {
                $out[$key] = $raw[$key];
            }
        }

        return $out;
    }
}
