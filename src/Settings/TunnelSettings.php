<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\Config;
use Dashboard\DB\Database;

/**
 * Dashboard-created Cloudflare tunnel. The token is encrypted in system_settings.
 * The root helper copies it to a root-only file the systemd unit reads.
 */
final class TunnelSettings
{
    public const KEY = 'tunnel';

    public const APACHE_PORT = 80;

    public const MCP_PORT = 8765;

    public function __construct(
        private readonly Database $db,
        private readonly ?ServiceControl $services = null,
    ) {}

    /**
     * @param array<string, string> $input
     */
    public function save(array $input, int $userId): void
    {
        $stored = $this->read();
        $clear = !empty($input['clear_token']);
        $token = trim((string) ($input['token'] ?? ''));
        if ($clear && $token === '') {
            unset($stored['token']);
        }
        if ($token !== '') {
            self::assertToken($token);
            $stored['token'] = $token;
        }
        foreach (['dash_host' => 'Dashboard hostname', 'mcp_host' => 'MCP hostname'] as $key => $label) {
            $host = strtolower(trim((string) ($input[$key] ?? '')));
            if ($host === '') {
                unset($stored[$key]);
                continue;
            }
            if (!self::hostOk($host)) {
                throw new \InvalidArgumentException($label . ' is not a DNS name.');
            }
            $stored[$key] = $host;
        }
        $dash = $stored['dash_host'] ?? '';
        $mcp = $stored['mcp_host'] ?? '';
        if ($dash !== '' && $dash === $mcp) {
            throw new \InvalidArgumentException('Dashboard and MCP hostnames must be different.');
        }
        (new SystemSettingsRepository($this->db))->setJson(self::KEY, $stored, $userId);
        if ($clear && $token === '') {
            $this->queue('tunnel-clear');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function view(): array
    {
        return self::present($this->read(), $this->services()->status(), self::suggestHost('PUBLIC_BASE_URL'), self::suggestHost('MCP_PUBLIC_URL'));
    }

    /**
     * @param array<string, string> $stored
     * @param array{connected: bool, detail: string, checked_at: ?string} $status
     * @return array<string, mixed>
     */
    public static function present(array $stored, array $status, string $suggestDash = '', string $suggestMcp = ''): array
    {
        $dash = $stored['dash_host'] ?? '';
        $mcp = $stored['mcp_host'] ?? '';
        $token = $stored['token'] ?? '';
        return [
            'dash_host'    => $dash,
            'mcp_host'     => $mcp,
            'suggest_dash' => $dash === '' ? $suggestDash : '',
            'suggest_mcp'  => $mcp === '' ? $suggestMcp : '',
            'token_hint'   => SecretMask::hint($token),
            'token_set'    => $token !== '',
            'routes'       => self::routes($dash, $mcp),
            'status'       => $status,
        ];
    }

    /**
     * @return list<array{label: string, hostname: string, service: string, copy: string}>
     */
    public static function routes(string $dash, string $mcp, int $apachePort = self::APACHE_PORT, int $mcpPort = self::MCP_PORT): array
    {
        $dashHost = $dash !== '' ? $dash : 'dash.<domain>';
        $mcpHost = $mcp !== '' ? $mcp : 'mcp.<domain>';
        $dashService = 'http://localhost:' . $apachePort;
        $mcpService = 'http://localhost:' . $mcpPort;
        return [
            [
                'label'    => 'Dashboard',
                'hostname' => $dashHost,
                'service'  => $dashService,
                'copy'     => $dashHost . ' -> ' . $dashService,
            ],
            [
                'label'    => 'MCP',
                'hostname' => $mcpHost,
                'service'  => $mcpService,
                'copy'     => $mcpHost . ' -> ' . $mcpService,
            ],
        ];
    }

    public function act(string $action): array
    {
        $stored = $this->read();
        $token = $stored['token'] ?? '';
        if (in_array($action, ['tunnel-start', 'tunnel-restart'], true) && $token === '') {
            throw new \InvalidArgumentException('Paste a tunnel token and save it before starting the tunnel.');
        }
        $fields = [];
        if (in_array($action, ['tunnel-start', 'tunnel-restart'], true)) {
            $fields['token'] = $token;
        }
        return $this->services()->request($action, $fields);
    }

    public function queueStatus(): void
    {
        $services = $this->services();
        if (!$services->ready() || !$services->stale()) {
            return;
        }
        try {
            $services->request('tunnel-status');
        } catch (\Throwable) {
            // The page still shows the last status file.
        }
    }

    public static function hostOk(string $host): bool
    {
        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $host) === 1;
    }

    public static function suggestHost(string $configKey): string
    {
        $raw = Config::maybe($configKey);
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }
        $host = strtolower((string) parse_url(trim($raw), PHP_URL_HOST));
        return self::hostOk($host) ? $host : '';
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
        foreach (['dash_host', 'mcp_host', 'token'] as $key) {
            if (isset($raw[$key]) && is_string($raw[$key]) && trim($raw[$key]) !== '') {
                $out[$key] = trim($raw[$key]);
            }
        }
        return $out;
    }

    private function services(): ServiceControl
    {
        return $this->services ?? new ServiceControl();
    }

    private function queue(string $action): void
    {
        try {
            $this->services()->request($action);
        } catch (\Throwable) {
            // Saving the row still succeeded. The next Start retries the helper.
        }
    }

    private static function assertToken(string $token): void
    {
        if (strlen($token) > 4000 || preg_match('/\s/', $token) === 1) {
            throw new \InvalidArgumentException('That tunnel token cannot be saved.');
        }
    }
}
