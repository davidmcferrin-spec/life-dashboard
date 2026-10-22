<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\Config;
use Dashboard\DB\Database;

/**
 * Admin-managed integration settings. Secrets are encrypted in system_settings
 * and overlaid on the environment for this process. Boot secrets stay in the environment.
 */
final class SetupConfig
{
    public const KEY = 'setup';

    public function __construct(private readonly Database $db) {}

    public static function apply(Database $db): void
    {
        $stored = (new SystemSettingsRepository($db))->getJson(self::KEY);
        if (!is_array($stored)) {
            return;
        }
        foreach (self::overlayKeys() as $key) {
            if (!array_key_exists($key, $stored)) {
                continue;
            }
            $value = trim((string) $stored[$key]);
            if ($value !== '') {
                Config::overlay($key, $value);
            }
        }
    }

    /**
     * @return array{groups: list<array<string, mixed>>}
     */
    public function view(): array
    {
        $stored = $this->read();
        $groups = [];
        foreach (self::catalog() as $id => $group) {
            $fields = [];
            foreach ($group['fields'] as $field) {
                $fields[] = $this->publicField($field, $stored);
            }
            $groups[] = [
                'id'     => $id,
                'label'  => $group['label'],
                'blurb'  => $group['blurb'],
                'status' => self::groupStatus($group, $stored),
                'fields' => $fields,
            ];
        }
        return ['groups' => $groups];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function save(string $groupId, array $values, int $userId): void
    {
        $catalog = self::catalog();
        if (!isset($catalog[$groupId])) {
            throw new \InvalidArgumentException('Unknown setup group.');
        }
        $stored = self::mergeGroup($catalog[$groupId], $this->read(), $values);
        (new SystemSettingsRepository($this->db))->setJson(self::KEY, $stored, $userId);
        foreach (self::overlayKeys() as $key) {
            $value = array_key_exists($key, $stored) ? trim((string) $stored[$key]) : '';
            Config::overlay($key, $value === '' ? null : $value);
        }
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, string> $stored
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function mergeGroup(array $group, array $stored, array $values): array
    {
        $ui = [];
        foreach ($group['fields'] as $field) {
            if (!empty($field['ui'])) {
                $ui[$field['key']] = self::boolString($values[$field['key']] ?? '0');
            }
        }
        foreach ($group['fields'] as $field) {
            if (!empty($field['ui'])) {
                continue;
            }
            $shown = self::shown($field, $ui, $values, $stored);
            $controlledByUi = self::controlledByUi($field, $ui);
            if (!$shown) {
                if ($controlledByUi) {
                    unset($stored[$field['key']]);
                }
                continue;
            }
            if (!empty($values['clear']) && is_array($values['clear']) && in_array($field['key'], $values['clear'], true)) {
                unset($stored[$field['key']]);
                continue;
            }
            $raw = $values[$field['key']] ?? '';
            if (!empty($field['secret'])) {
                $text = trim((string) $raw);
                if ($text === '') {
                    continue;
                }
                self::assertSecret($text);
                $stored[$field['key']] = $text;
                continue;
            }
            $normalized = self::normalize($field, $raw);
            if ($normalized === '') {
                unset($stored[$field['key']]);
            } else {
                $stored[$field['key']] = $normalized;
            }
        }
        return $stored;
    }

    /**
     * @return array<string, array{label: string, blurb: string, fields: list<array<string, mixed>>}>
     */
    public static function catalog(): array
    {
        return [
            'weather' => [
                'label' => 'Weather',
                'blurb' => 'OpenWeatherMap',
                'fields' => [
                    ['key' => 'OWM_API_KEY', 'label' => 'API key', 'secret' => true],
                ],
            ],
            'stocks' => [
                'label' => 'Stocks',
                'blurb' => 'Alpha Vantage',
                'fields' => [
                    ['key' => 'ALPHAVANTAGE_API_KEY', 'label' => 'API key', 'secret' => true],
                ],
            ],
            'flights' => [
                'label' => 'Flights',
                'blurb' => 'OpenSky or FlightAware',
                'fields' => [
                    ['key' => 'FLIGHT_PROVIDER', 'label' => 'Provider', 'type' => 'enum', 'options' => ['opensky' => 'OpenSky', 'flightaware' => 'FlightAware'], 'default' => 'opensky'],
                    ['key' => 'OPENSKY_CLIENT_ID', 'label' => 'Client id', 'secret' => true, 'when' => ['FLIGHT_PROVIDER' => 'opensky']],
                    ['key' => 'OPENSKY_CLIENT_SECRET', 'label' => 'Client secret', 'secret' => true, 'when' => ['FLIGHT_PROVIDER' => 'opensky']],
                    ['key' => 'FLIGHTAWARE_API_KEY', 'label' => 'AeroAPI key', 'secret' => true, 'when' => ['FLIGHT_PROVIDER' => 'flightaware']],
                ],
            ],
            'packages' => [
                'label' => 'Packages',
                'blurb' => 'UPS, FedEx, and DHL',
                'fields' => [
                    ['key' => 'use_ups', 'label' => 'UPS', 'type' => 'bool', 'ui' => true],
                    ['key' => 'UPS_CLIENT_ID', 'label' => 'UPS client id', 'secret' => true, 'when' => ['use_ups' => '1']],
                    ['key' => 'UPS_CLIENT_SECRET', 'label' => 'UPS client secret', 'secret' => true, 'when' => ['use_ups' => '1']],
                    ['key' => 'UPS_USE_SANDBOX', 'label' => 'UPS sandbox', 'type' => 'bool', 'when' => ['use_ups' => '1']],
                    ['key' => 'use_fedex', 'label' => 'FedEx', 'type' => 'bool', 'ui' => true],
                    ['key' => 'FEDEX_CLIENT_ID', 'label' => 'FedEx client id', 'secret' => true, 'when' => ['use_fedex' => '1']],
                    ['key' => 'FEDEX_CLIENT_SECRET', 'label' => 'FedEx client secret', 'secret' => true, 'when' => ['use_fedex' => '1']],
                    ['key' => 'FEDEX_USE_SANDBOX', 'label' => 'FedEx sandbox', 'type' => 'bool', 'when' => ['use_fedex' => '1']],
                    ['key' => 'use_dhl', 'label' => 'DHL', 'type' => 'bool', 'ui' => true],
                    ['key' => 'DHL_API_KEY', 'label' => 'DHL API key', 'secret' => true, 'when' => ['use_dhl' => '1']],
                ],
            ],
            'x' => [
                'label' => 'X',
                'blurb' => 'Bearer token for account posts',
                'fields' => [
                    ['key' => 'X_BEARER_TOKEN', 'label' => 'Bearer token', 'secret' => true],
                ],
            ],
            'reddit' => [
                'label' => 'Reddit',
                'blurb' => 'Optional. Public JSON works with no keys. Add a script app if Reddit starts refusing requests.',
                'fields' => [
                    ['key' => 'REDDIT_CLIENT_ID', 'label' => 'App id', 'secret' => true],
                    ['key' => 'REDDIT_CLIENT_SECRET', 'label' => 'App secret', 'secret' => true],
                    ['key' => 'REDDIT_USER_AGENT', 'label' => 'User-Agent', 'type' => 'text', 'placeholder' => 'web:life-dashboard:1.0 (by /u/yourname)'],
                ],
            ],
            'tunnel' => [
                'label' => 'Tunnel',
                'blurb' => 'Public hostnames. Leave MCP bind on all until existing bots use the tunnel. MCP_ALLOW_PLAIN_HTTP in the server environment defaults to on. Settings → Agents can override it.',
                'fields' => [
                    ['key' => 'PUBLIC_BASE_URL', 'label' => 'Dashboard URL', 'type' => 'url', 'placeholder' => 'https://dash.example.com'],
                    ['key' => 'MCP_PUBLIC_URL', 'label' => 'MCP URL', 'type' => 'url', 'placeholder' => 'https://mcp.example.com/mcp'],
                    ['key' => 'MCP_BIND', 'label' => 'MCP bind', 'type' => 'text', 'default' => 'all', 'placeholder' => 'all'],
                ],
            ],
            'ollama' => [
                'label' => 'Local model',
                'blurb' => 'Ollama embeddings',
                'fields' => [
                    ['key' => 'OLLAMA_URL', 'label' => 'URL', 'type' => 'url', 'placeholder' => 'http://127.0.0.1:11434'],
                    ['key' => 'OLLAMA_EMBED_MODEL', 'label' => 'Embedding model', 'type' => 'text', 'default' => 'nomic-embed-text', 'placeholder' => 'nomic-embed-text'],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function overlayKeys(): array
    {
        $keys = [];
        foreach (self::catalog() as $group) {
            foreach ($group['fields'] as $field) {
                if (empty($field['ui'])) {
                    $keys[] = $field['key'];
                }
            }
        }
        return $keys;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, string> $stored
     * @return array<string, mixed>
     */
    private function publicField(array $field, array $stored): array
    {
        $key = $field['key'];
        $out = $field;
        if (!empty($field['ui'])) {
            $out['value'] = $this->uiChecked($key, $stored) ? '1' : '0';
            return $out;
        }
        if (!empty($field['secret'])) {
            $out['source'] = $this->source($key, $stored);
            $out['value'] = '';
            return $out;
        }
        $value = $this->plain($field, $stored);
        if (($field['type'] ?? '') === 'bool') {
            $value = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        }
        $out['value'] = $value;
        $out['source'] = $this->source($key, $stored);
        return $out;
    }

    /**
     * @param array<string, string> $stored
     */
    private function uiChecked(string $key, array $stored): bool
    {
        $needles = match ($key) {
            'use_ups'   => ['UPS_CLIENT_ID', 'UPS_CLIENT_SECRET', 'UPS_USE_SANDBOX'],
            'use_fedex' => ['FEDEX_CLIENT_ID', 'FEDEX_CLIENT_SECRET', 'FEDEX_USE_SANDBOX'],
            'use_dhl'   => ['DHL_API_KEY'],
            default     => [],
        };
        foreach ($needles as $needle) {
            if (isset($stored[$needle]) && $stored[$needle] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, string> $stored
     */
    private function plain(array $field, array $stored): string
    {
        $key = $field['key'];
        if (array_key_exists($key, $stored) && $stored[$key] !== '') {
            return $stored[$key];
        }
        $env = Config::envValue($key);
        if ($env !== null) {
            return ($field['type'] ?? '') === 'bool' ? (Config::bool($key) ? '1' : '0') : $env;
        }
        $default = (string) ($field['default'] ?? '');
        return ($field['type'] ?? '') === 'bool' ? '0' : $default;
    }

    /**
     * @param array<string, string> $stored
     */
    private function source(string $key, array $stored): string
    {
        if (isset($stored[$key]) && $stored[$key] !== '') {
            return 'saved';
        }
        return Config::envValue($key) !== null ? 'env' : 'none';
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, string> $stored
     */
    private static function groupStatus(array $group, array $stored): string
    {
        $saved = false;
        $env = false;
        foreach ($group['fields'] as $field) {
            if (!empty($field['ui'])) {
                continue;
            }
            $key = $field['key'];
            if (isset($stored[$key]) && $stored[$key] !== '') {
                $saved = true;
            } elseif (Config::envValue($key) !== null) {
                $env = true;
            }
        }
        if ($saved) {
            return 'Saved';
        }
        return $env ? 'From environment' : 'Not set';
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
        foreach ($raw as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, string> $ui
     * @param array<string, mixed> $values
     * @param array<string, string> $stored
     */
    private static function shown(array $field, array $ui, array $values, array $stored): bool
    {
        foreach ($field['when'] ?? [] as $key => $expected) {
            $current = $ui[$key] ?? (array_key_exists($key, $values) ? self::boolOrRaw($values[$key]) : ($stored[$key] ?? ''));
            if ((string) $current !== (string) $expected) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, string> $ui
     */
    private static function controlledByUi(array $field, array $ui): bool
    {
        foreach (array_keys($field['when'] ?? []) as $key) {
            if (isset($ui[$key])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function normalize(array $field, mixed $raw): string
    {
        $type = $field['type'] ?? 'text';
        if ($type === 'bool') {
            return self::boolString($raw) === '1' ? 'true' : 'false';
        }
        if ($type === 'enum') {
            $value = strtolower(trim((string) $raw));
            $options = $field['options'] ?? [];
            if (!is_array($options) || !array_key_exists($value, $options)) {
                throw new \InvalidArgumentException($field['label'] . ' is not a valid choice.');
            }
            return $value;
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return '';
        }
        if ($type === 'url') {
            $parts = parse_url($text);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = (string) ($parts['host'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user'])) {
                throw new \InvalidArgumentException('URL must be http or https with a host.');
            }
            if (strlen($text) > 200) {
                throw new \InvalidArgumentException('URL is too long.');
            }
            return $text;
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $text) !== 1) {
            throw new \InvalidArgumentException($field['label'] . ' has unsupported characters.');
        }
        return $text;
    }

    private static function assertSecret(string $text): void
    {
        if (strlen($text) > 500 || str_contains($text, "\n") || str_contains($text, "\r") || str_contains($text, "\0")) {
            throw new \InvalidArgumentException('That value cannot be saved.');
        }
    }

    private static function boolString(mixed $raw): string
    {
        $text = strtolower(trim((string) $raw));
        return in_array($text, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }

    private static function boolOrRaw(mixed $raw): string
    {
        if (is_bool($raw)) {
            return $raw ? '1' : '0';
        }
        $text = trim((string) $raw);
        if (in_array(strtolower($text), ['1', 'true', 'yes', 'on', '0', 'false', 'no', 'off'], true)) {
            return self::boolString($raw);
        }
        return $text;
    }
}
