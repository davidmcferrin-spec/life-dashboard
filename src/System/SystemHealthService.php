<?php

declare(strict_types=1);

namespace Dashboard\System;

use Dashboard\Config;
use Dashboard\DB\Database;

/**
 * SystemHealthService — cron log tailing, last-run detection, and app health checks.
 */
class SystemHealthService
{
    /** @var array<string, array{label: string, log: string, staleMinutes: int, scheduleHint: string}> */
    private const JOBS = [
        'feeds' => [
            'label'         => 'RSS feeds',
            'log'           => 'feeds.log',
            'staleMinutes'  => 45,
            'scheduleHint'  => 'Every ~30 min',
        ],
        'flights' => [
            'label'         => 'Flight polls',
            'log'           => 'flights.log',
            'staleMinutes'  => 45,
            'scheduleHint'  => 'Every ~30 min',
        ],
        'packages' => [
            'label'         => 'Package polls',
            'log'           => 'packages.log',
            'staleMinutes'  => 150,
            'scheduleHint'  => 'Every ~2 hours',
        ],
        'pings' => [
            'label'         => 'Ping checks',
            'log'           => 'ping.log',
            'staleMinutes'  => 45,
            'scheduleHint'  => 'Every ~15–30 min',
        ],
        'stocks' => [
            'label'         => 'Stock quotes',
            'log'           => 'stocks.log',
            'staleMinutes'  => 720,
            'scheduleHint'  => '3× daily',
        ],
        'media' => [
            'label'         => 'YouTube, Reddit, X',
            'log'           => 'media.log',
            'staleMinutes'  => 45,
            'scheduleHint'  => 'Every ~30 min',
        ],
        'reddit' => [
            'label'         => 'Tracked subreddits',
            'log'           => 'reddit.log',
            'staleMinutes'  => 45,
            'scheduleHint'  => 'Every ~30 min',
        ],
        'shares' => [
            'label'         => 'Share link purge',
            'log'           => 'shares.log',
            'staleMinutes'  => 1560,
            'scheduleHint'  => 'Daily',
        ],
    ];

    private const TAIL_CHUNK_BYTES = 65536;

    private Database $db;

    private ServiceHealth $services;

    public function __construct(?Database $db = null, ?ServiceHealth $services = null)
    {
        $this->db       = $db ?? Database::getInstance();
        $this->services = $services ?? new ServiceHealth();
    }

    /**
     * @return list<string>
     */
    public function jobIds(): array
    {
        return array_keys(self::JOBS);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getCronJobs(): array
    {
        $jobs = [];

        foreach (self::JOBS as $id => $def) {
            $path     = $this->logPath($id);
            $exists   = is_file($path);
            $readable = $exists && is_readable($path);
            $size     = $readable ? (int) filesize($path) : 0;
            $lastRun  = null;
            $status   = 'missing';

            if (!$exists) {
                $status = 'missing';
            } elseif (!$readable) {
                $status = 'missing';
            } elseif ($size === 0) {
                $status = 'empty';
            } else {
                $tail    = $this->readTailBytes($path);
                $lastRun = $this->parseLastTimestamp($tail);
                $status  = $lastRun === null ? 'empty' : $this->cronStatus($lastRun, $def['staleMinutes']);
            }

            $jobs[$id] = [
                'id'            => $id,
                'label'         => $def['label'],
                'schedule_hint' => $def['scheduleHint'],
                'last_run_at'   => $lastRun,
                'status'        => $status,
                'log_exists'    => $exists,
                'log_size'      => $size,
                'log_file'      => $def['log'],
            ];
        }

        return $jobs;
    }

    public function tailLog(string $jobId, int $lines = 150): string
    {
        $this->assertJobId($jobId);
        $path = $this->logPath($jobId);

        if (!is_readable($path)) {
            return '';
        }

        $tail = $this->readTailBytes($path);

        if ($tail === '') {
            return '';
        }

        $parts = explode("\n", $tail);

        if (count($parts) > $lines) {
            $parts = array_slice($parts, -$lines);
        }

        return implode("\n", $parts);
    }

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    public function getHealthChecks(): array
    {
        $checks = [];

        $phpOk = PHP_VERSION_ID >= 80500;
        $checks[] = [
            'label'  => 'PHP version',
            'status' => $phpOk ? 'ok' : 'warn',
            'detail' => PHP_VERSION . ($phpOk ? '' : ' (8.5+ recommended)'),
        ];

        try {
            $this->db->fetchScalar('SELECT 1');
            $checks[] = [
                'label'  => 'Database',
                'status' => 'ok',
                'detail' => $this->db->driver() . ' connection OK',
            ];
        } catch (\Throwable $e) {
            $checks[] = [
                'label'  => 'Database',
                'status' => 'fail',
                'detail' => 'Connection failed',
            ];
        }

        if ($this->db->isPgsql()) {
            $checks = array_merge($checks, $this->pgsqlChecks());
        }

        if ($this->db->driver() === 'sqlite') {
            $dbPath = $this->resolveSqlitePath();
            if ($dbPath === null) {
                $checks[] = [
                    'label'  => 'SQLite database file',
                    'status' => 'fail',
                    'detail' => 'Path not found',
                ];
            } else {
                $writable = is_writable($dbPath);
                $checks[] = [
                    'label'  => 'SQLite database file',
                    'status' => $writable ? 'ok' : 'fail',
                    'detail' => $writable ? 'Writable' : 'Not writable',
                ];
            }
        }

        foreach (['logs' => STORAGE_DIR . '/logs', 'cache' => STORAGE_DIR . '/cache'] as $name => $dir) {
            $checks[] = $this->directoryCheck("Storage / {$name}", $dir);
        }

        $checks[] = $this->diskSpaceCheck();

        $checks[] = [
            'label'  => 'Weather API key',
            'status' => Config::maybe('OWM_API_KEY') !== null ? 'ok' : 'warn',
            'detail' => Config::maybe('OWM_API_KEY') !== null ? 'Configured' : 'Not set',
        ];

        $checks[] = [
            'label'  => 'Stocks API key',
            'status' => Config::maybe('ALPHAVANTAGE_API_KEY') !== null ? 'ok' : 'warn',
            'detail' => Config::maybe('ALPHAVANTAGE_API_KEY') !== null ? 'Configured' : 'Not set',
        ];

        $flightProvider = strtolower(Config::get('FLIGHT_PROVIDER', 'opensky'));
        if ($flightProvider === 'flightaware') {
            $checks[] = [
                'label'  => 'FlightAware API key',
                'status' => Config::maybe('FLIGHTAWARE_API_KEY') !== null ? 'ok' : 'warn',
                'detail' => Config::maybe('FLIGHTAWARE_API_KEY') !== null ? 'Configured' : 'Not set',
            ];
        } else {
            $openskyOk = Config::maybe('OPENSKY_CLIENT_ID') !== null
                && Config::maybe('OPENSKY_CLIENT_SECRET') !== null;
            $checks[] = [
                'label'  => 'OpenSky credentials',
                'status' => $openskyOk ? 'ok' : 'warn',
                'detail' => $openskyOk ? 'Configured' : 'Not set',
            ];
        }

        return array_merge($checks, $this->services->checks());
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function directoryCheck(string $label, string $dir): array
    {
        if (!is_dir($dir)) {
            return [
                'label'  => $label,
                'status' => 'warn',
                'detail' => 'Directory missing',
            ];
        }

        if (!is_writable($dir)) {
            return [
                'label'  => $label,
                'status' => 'fail',
                'detail' => 'Not writable',
            ];
        }

        return [
            'label'  => $label,
            'status' => 'ok',
            'detail' => 'Writable',
        ];
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function diskSpaceCheck(): array
    {
        if (!function_exists('disk_free_space')) {
            return [
                'label'  => 'Disk space',
                'status' => 'ok',
                'detail' => 'Unavailable on this host',
            ];
        }

        $free = @disk_free_space(STORAGE_DIR);

        if ($free === false) {
            return [
                'label'  => 'Disk space',
                'status' => 'ok',
                'detail' => 'Unable to read',
            ];
        }

        $mb = (int) round($free / 1024 / 1024);

        if ($mb < 100) {
            return [
                'label'  => 'Disk space',
                'status' => 'warn',
                'detail' => "{$mb} MB free",
            ];
        }

        return [
            'label'  => 'Disk space',
            'status' => 'ok',
            'detail' => "{$mb} MB free",
        ];
    }

    private function cronStatus(string $lastRunAt, int $staleMinutes): string
    {
        $lastTs = strtotime($lastRunAt);

        if ($lastTs === false) {
            return 'empty';
        }

        $ageMinutes = (time() - $lastTs) / 60;

        return $ageMinutes <= $staleMinutes ? 'ok' : 'stale';
    }

    private function parseLastTimestamp(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        if (preg_match_all('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $content, $matches) !== 0) {
            $last = end($matches[1]);

            return is_string($last) ? $last : null;
        }

        return null;
    }

    private function readTailBytes(string $path): string
    {
        $size = filesize($path);

        if ($size === false || $size === 0) {
            return '';
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $offset = max(0, $size - self::TAIL_CHUNK_BYTES);
        fseek($handle, $offset);
        $data = (string) fread($handle, self::TAIL_CHUNK_BYTES);
        fclose($handle);

        if ($offset > 0) {
            $newline = strpos($data, "\n");

            if ($newline !== false) {
                $data = substr($data, $newline + 1);
            }
        }

        return rtrim($data, "\r\n");
    }

    private function logPath(string $jobId): string
    {
        $this->assertJobId($jobId);

        return STORAGE_DIR . '/logs/' . self::JOBS[$jobId]['log'];
    }

    private function assertJobId(string $jobId): void
    {
        if (!isset(self::JOBS[$jobId])) {
            throw new \InvalidArgumentException("Unknown cron job: {$jobId}");
        }
    }

    private function resolveSqlitePath(): ?string
    {
        $path = Config::get('DB_SQLITE_PATH', '../storage/dashboard.db');

        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            $path = ROOT_DIR . '/' . ltrim($path, './');
        }

        return is_file($path) ? $path : null;
    }

    /**
     * PostgreSQL-specific health: server version, database size and pending migrations.
     *
     * @return list<array{label: string, status: string, detail: string}>
     */
    private function pgsqlChecks(): array
    {
        $checks = [];

        try {
            $version = (string) $this->db->fetchScalar('SHOW server_version');
            $major   = (int) $version;
            $checks[] = [
                'label'  => 'PostgreSQL server',
                'status' => $major >= 16 ? 'ok' : 'warn',
                'detail' => $version . ($major >= 16 ? '' : ' (16+ recommended)'),
            ];

            $bytes = (int) $this->db->fetchScalar('SELECT pg_database_size(current_database())');
            $checks[] = [
                'label'  => 'PostgreSQL database size',
                'status' => 'ok',
                'detail' => number_format($bytes / 1048576, 1) . ' MB',
            ];

            $ran     = array_column($this->db->fetchAll('SELECT name FROM migrations'), 'name');
            $pending = array_diff(array_keys(\Dashboard\DB\Schema::pgsqlMigrationFiles()), $ran);
            $checks[] = [
                'label'  => 'Database migrations',
                'status' => $pending === [] ? 'ok' : 'warn',
                'detail' => $pending === []
                    ? count($ran) . ' applied'
                    : count($pending) . ' pending — run php scripts/migrate.php',
            ];
        } catch (\Throwable $e) {
            error_log('[Dashboard][Health] PostgreSQL checks failed: ' . $e->getMessage());
            $checks[] = [
                'label'  => 'PostgreSQL details',
                'status' => 'warn',
                'detail' => 'Could not query server details',
            ];
        }

        return $checks;
    }
}
