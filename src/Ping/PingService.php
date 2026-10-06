<?php

declare(strict_types=1);

namespace Dashboard\Ping;

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;
use Dashboard\Support\HttpClient;

final class PingService
{
    private const MAX_TARGETS = 10;

    public function __construct(
        private readonly PingRepository $repo,
        private readonly PreferenceService $prefs,
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    public static function fromDatabase(Database $db): self
    {
        return new self(new PingRepository($db), new PreferenceService($db));
    }

    public function ensureDefaults(int $userId): void
    {
        if ($this->prefs->hasWidgetDefaultsSeeded($userId, 'pings')) {
            return;
        }

        $defaults = [
            ['label' => 'Google DNS', 'target' => '8.8.8.8'],
            ['label' => 'Cloudflare DNS', 'target' => '1.1.1.1'],
            ['label' => 'Google', 'target' => 'https://www.google.com'],
            ['label' => 'Dreamhost', 'target' => 'https://www.dreamhost.com'],
        ];

        $order = 0;
        foreach ($defaults as $row) {
            $this->repo->insert($userId, $row['label'], $row['target'], $order++);
        }

        $this->prefs->markWidgetDefaultsSeeded($userId, 'pings');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $this->ensureDefaults($userId);

        return array_map(
            fn (array $row): array => $this->format($row),
            $this->repo->listForUser($userId, true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, string $label, string $target): array
    {
        $this->ensureDefaults($userId);

        if ($this->repo->countForUser($userId) >= self::MAX_TARGETS) {
            throw new \InvalidArgumentException('Maximum of ' . self::MAX_TARGETS . ' ping targets allowed.');
        }

        $label  = trim($label);
        $target = trim($target);

        if ($label === '' || $target === '') {
            throw new \InvalidArgumentException('Label and target are required.');
        }

        $target = $this->normalizeTarget($target);

        $id = $this->repo->insert($userId, $label, $target, $this->repo->nextSortOrder($userId));
        $row = $this->repo->findByIdForUser($id, $userId);

        if ($row === null) {
            throw new \RuntimeException('Failed to create ping target.');
        }

        return $this->format($row);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, int $userId, array $fields): ?array
    {
        $allowed = [];

        if (isset($fields['label'])) {
            $allowed['label'] = trim((string) $fields['label']);
        }
        if (isset($fields['target'])) {
            $allowed['target'] = $this->normalizeTarget((string) $fields['target']);
        }
        if (array_key_exists('is_active', $fields)) {
            $allowed['is_active'] = $fields['is_active'] ? 1 : 0;
        }

        if ($allowed !== []) {
            $this->repo->update($id, $userId, $allowed);
        }

        $row = $this->repo->findByIdForUser($id, $userId);

        return $row !== null ? $this->format($row) : null;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->repo->delete($id, $userId);
    }

    public function checkTarget(string $target): array
    {
        $target = $this->normalizeTarget($target);
        $start  = hrtime(true);

        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            return $this->checkHttp($target, $start);
        }

        if (filter_var($target, FILTER_VALIDATE_IP)) {
            return $this->checkSocket($target, $start);
        }

        $host = $target;
        $resolved = gethostbyname($host);
        if ($resolved === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return [
                'up'      => false,
                'latency' => null,
                'message' => 'DNS resolution failed',
            ];
        }

        return $this->checkSocket($resolved, $start);
    }

    public function runCheck(array $row): void
    {
        $result = $this->checkTarget((string) $row['target']);
        $this->repo->applyCheck(
            (int) $row['id'],
            $result['up'],
            $result['latency'],
            $result['message'],
        );
    }

    public function runAllActiveChecks(): int
    {
        $targets = $this->repo->listAllActive();
        foreach ($targets as $target) {
            $this->runCheck($target);
        }

        return count($targets);
    }

    private function checkHttp(string $url, int $startNs): array
    {
        try {
            $response = $this->http->request('GET', $url, null, [
                'User-Agent' => 'PersonalDashboard/1.0',
            ], 8);
            $latency = (int) round((hrtime(true) - $startNs) / 1_000_000);
            $up      = $response['status'] >= 200 && $response['status'] < 400;

            return [
                'up'      => $up,
                'latency' => $latency,
                'message' => 'HTTP ' . $response['status'],
            ];
        } catch (\Throwable $e) {
            return [
                'up'      => false,
                'latency' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function checkSocket(string $host, int $startNs): array
    {
        $port = 443;
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);

        if ($socket === false) {
            $port = 80;
            $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        }

        $latency = (int) round((hrtime(true) - $startNs) / 1_000_000);

        if ($socket === false) {
            return [
                'up'      => false,
                'latency' => null,
                'message' => $errstr !== '' ? $errstr : 'Connection failed',
            ];
        }

        fclose($socket);

        return [
            'up'      => true,
            'latency' => $latency,
            'message' => "TCP {$port} ok",
        ];
    }

    private function normalizeTarget(string $target): string
    {
        $target = trim($target);

        if (filter_var($target, FILTER_VALIDATE_IP)) {
            return $target;
        }

        if (!str_contains($target, '://')) {
            $target = 'https://' . $target;
        }

        return mb_substr($target, 0, 255);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'label'           => (string) $row['label'],
            'target'          => (string) $row['target'],
            'is_active'       => (bool) $row['is_active'],
            'is_up'           => $row['is_up'] === null ? null : (bool) $row['is_up'],
            'latency_ms'      => $row['latency_ms'] !== null ? (int) $row['latency_ms'] : null,
            'status_message'  => $row['status_message'] ?? null,
            'last_checked_at' => $row['last_checked_at'] ?? null,
        ];
    }
}
