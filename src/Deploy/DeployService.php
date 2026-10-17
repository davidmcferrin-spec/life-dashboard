<?php

declare(strict_types=1);

namespace Dashboard\Deploy;

use Dashboard\DB\Database;
use Dashboard\DB\Schema;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Queue a deploy of origin/main. PHP writes a request file and returns.
 * A root systemd unit runs lifedash-deploy. This process never calls sudo.
 */
final class DeployService
{
    public const RATE_LIMIT_SECONDS = 300;

    public const MCP_DISABLED = 'MCP updates are disabled in Settings';

    public const SPOOL_DIR = '/var/lib/lifedash/deploy';

    private const LOG_TAIL = 65536;

    public function __construct(
        private readonly ?Database $db = null,
        private readonly ?string $appDir = null,
        private readonly ?string $spoolDir = null,
        private readonly ?bool $mcpUpdates = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $git = $this->gitStatus();
        $last = $this->readStateFile('last.json');
        return [
            'branch'              => $git['branch'],
            'head'                => $git['head'],
            'origin_main'         => $git['origin_main'],
            'behind'              => $git['behind'],
            'ahead'               => $git['ahead'],
            'diverged'            => $git['ahead'] !== [],
            'migrations_pending'  => $this->pendingMigrations(),
            'last'                => $this->publicJob($last),
            'queued'              => $this->queued(),
            'spool'               => $this->spoolReady(),
            'rate_limit_seconds'  => self::RATE_LIMIT_SECONDS,
            'rate_limited_until'  => $this->rateLimitedUntil($last),
            'mcp_updates'         => $this->mcpUpdatesEnabled(),
            'git_error'           => $git['error'],
        ];
    }

    /**
     * Absent row and a missing table are off. A token deploy is refused until an admin turns this on.
     */
    public function mcpUpdatesEnabled(): bool
    {
        if ($this->mcpUpdates !== null) {
            return $this->mcpUpdates;
        }
        if ($this->db === null) {
            return false;
        }
        try {
            $value = $this->db->fetchScalar(
                "SELECT enabled FROM server_flags WHERE flag_key = 'mcp_updates'"
            );
        } catch (\Throwable) {
            return false;
        }
        return (int) $value === 1;
    }

    /**
     * @return array{mcp_updates: bool, changed: bool, previous: bool}
     */
    public function setMcpUpdates(bool $enabled, int $userId): array
    {
        if ($this->db === null) {
            throw new ValidationException('MCP updates cannot be stored without a database.');
        }
        $previous = $this->mcpUpdatesEnabled();
        if ($previous === $enabled) {
            return ['mcp_updates' => $enabled, 'changed' => false, 'previous' => $previous];
        }
        $flag = $enabled ? 1 : 0;
        $this->db->execute(
            "INSERT INTO server_flags (flag_key, enabled, updated_by_user_id)
             VALUES ('mcp_updates', ?, ?)
             ON CONFLICT (flag_key) DO UPDATE
             SET enabled = EXCLUDED.enabled,
                 updated_at = (now() AT TIME ZONE 'UTC'),
                 updated_by_user_id = EXCLUDED.updated_by_user_id",
            [$flag, $userId]
        );
        return ['mcp_updates' => $enabled, 'changed' => true, 'previous' => $previous];
    }

    /**
     * Admin session requests have no token. Those still run when MCP updates are off.
     */
    public static function mcpRequestAllowed(bool $enabled, ?int $tokenId): bool
    {
        if ($tokenId === null || $tokenId < 1) {
            return true;
        }
        return $enabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function log(?string $jobId): array
    {
        $jobId = $jobId !== null && $jobId !== '' ? strtolower($jobId) : $this->lastJobId();
        if ($jobId === null || preg_match('/^[0-9a-f]{32}$/', $jobId) !== 1) {
            throw ValidationException::withFields(['job_id' => 'job_id must be 32 lowercase hex characters.']);
        }
        $path = $this->containedFile($this->jobsDir(), $jobId . '.log');
        if ($path === null) {
            throw new NotFoundException('No log for that deploy.');
        }
        $meta = $this->readContainedJson($this->jobsDir(), $jobId . '.json');
        return [
            'job_id' => $jobId,
            'state'  => is_array($meta) ? ($meta['state'] ?? null) : null,
            'log'    => $this->tail($path, self::LOG_TAIL),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function request(string $confirmSha, bool $dryRun, int $userId, ?int $tokenId, ?string $tokenName): array
    {
        if (!self::mcpRequestAllowed($this->mcpUpdatesEnabled(), $tokenId)) {
            throw new ValidationException(self::MCP_DISABLED);
        }
        $confirmSha = trim($confirmSha);
        if (preg_match('/^[0-9a-f]{40}$/', $confirmSha) !== 1) {
            throw ValidationException::withFields(['confirm_sha' => 'confirm_sha must be 40 lowercase hex characters.']);
        }
        $git = $this->gitStatus();
        if ($git['error'] !== null) {
            throw new ValidationException($git['error']);
        }
        if ($git['branch'] !== 'main') {
            throw new ValidationException('Deploys run only when the checkout is on main.');
        }
        if ($git['origin_main'] === null || !hash_equals($git['origin_main'], $confirmSha)) {
            throw ValidationException::withFields([
                'confirm_sha' => 'confirm_sha must equal the current origin/main (' . ($git['origin_main'] ?? 'unknown') . ').',
            ]);
        }
        if ($git['ahead'] !== []) {
            throw new ValidationException('Local main has commits that are not on origin/main.');
        }

        $base = [
            'dry_run'      => $dryRun,
            'confirm_sha'  => $confirmSha,
            'origin_main'  => $git['origin_main'],
            'head'         => $git['head'],
            'branch'       => 'main',
        ];
        if ($dryRun) {
            return $base + [
                'would_deploy' => true,
                'state'        => 'dry_run',
                'http_status'  => 200,
            ];
        }

        if (!$this->spoolReady()) {
            throw new ValidationException('Deploy spool is not installed. An admin runs the steps in docs/deploy/self-update.md.');
        }
        if ($this->queued() !== []) {
            throw new ValidationException('A deploy is already queued.');
        }
        $last = $this->readStateFile('last.json');
        $until = $this->rateLimitedUntil($last);
        if ($until !== null) {
            throw new ValidationException('A deploy ran recently. The next one can start after ' . $until . '.');
        }

        $jobId = bin2hex(random_bytes(16));
        $requestedAt = gmdate('Y-m-d\TH:i:s\Z');
        $payload = [
            'job_id'       => $jobId,
            'confirm_sha'  => $confirmSha,
            'token_id'     => $tokenId,
            'token_name'   => $this->cleanName($tokenName),
            'user_id'      => $userId,
            'requested_at' => $requestedAt,
        ];
        $this->writeRequest($jobId, $payload);

        return $base + [
            'job_id'      => $jobId,
            'state'       => 'queued',
            'http_status' => 202,
            'job'         => [
                'id'           => $jobId,
                'confirm_sha'  => $confirmSha,
                'state'        => 'queued',
                'requested_at' => $requestedAt,
            ],
        ];
    }

    /**
     * @param list<string> $files
     * @param list<string> $applied
     * @return list<string>
     */
    public static function pendingNames(array $files, array $applied): array
    {
        return array_values(array_diff($files, $applied));
    }

    /**
     * @return array{branch: ?string, head: ?string, origin_main: ?string, behind: list<array{sha: string, title: string}>, ahead: list<array{sha: string, title: string}>, error: ?string}
     */
    private function gitStatus(): array
    {
        $empty = [
            'branch' => null,
            'head' => null,
            'origin_main' => null,
            'behind' => [],
            'ahead' => [],
            'error' => null,
        ];
        $branch = $this->git(['rev-parse', '--abbrev-ref', 'HEAD']);
        $head = $this->git(['rev-parse', 'HEAD']);
        if ($branch['code'] !== 0 || $head['code'] !== 0) {
            $empty['error'] = 'The app checkout is not a git repository.';
            return $empty;
        }
        $origin = $this->git(['rev-parse', '--verify', 'origin/main']);
        $branchName = trim($branch['out']);
        $headSha = $this->shaOrNull($head['out']);
        $originSha = $origin['code'] === 0 ? $this->shaOrNull($origin['out']) : null;
        $behind = [];
        $ahead = [];
        if ($originSha !== null && $headSha !== null) {
            $behind = $this->commits('HEAD', 'origin/main');
            $ahead = $this->commits('origin/main', 'HEAD');
        }
        return [
            'branch' => $branchName !== '' ? $branchName : null,
            'head' => $headSha,
            'origin_main' => $originSha,
            'behind' => $behind,
            'ahead' => $ahead,
            'error' => $originSha === null ? 'origin/main is not in this checkout yet.' : null,
        ];
    }

    /**
     * @return list<array{sha: string, title: string}>
     */
    private function commits(string $from, string $to): array
    {
        if (!in_array($from, ['HEAD', 'origin/main'], true) || !in_array($to, ['HEAD', 'origin/main'], true)) {
            return [];
        }
        $result = $this->git(['log', '--format=%H%x09%s', '-n', '20', $from . '..' . $to]);
        if ($result['code'] !== 0) {
            return [];
        }
        $rows = [];
        foreach (preg_split("/\r\n|\n|\r/", trim($result['out'])) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            [$sha, $title] = array_pad(explode("\t", $line, 2), 2, '');
            $sha = $this->shaOrNull($sha);
            if ($sha === null) {
                continue;
            }
            $title = preg_replace('/[\x00-\x1F\x7F]/', '', $title) ?? '';
            $rows[] = ['sha' => $sha, 'title' => mb_substr($title, 0, 120)];
        }
        return $rows;
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private function git(array $args): array
    {
        foreach ($args as $arg) {
            if (!is_string($arg) || str_contains($arg, "\0")) {
                return ['code' => 1, 'out' => '', 'err' => 'bad git argument'];
            }
        }
        $bin = is_executable('/usr/bin/git') ? '/usr/bin/git' : 'git';
        $cmd = array_merge([$bin, '-C', $this->appDir(), '-c', 'safe.directory=' . $this->appDir()], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return ['code' => 1, 'out' => '', 'err' => 'git could not be started'];
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [
            'code' => $code,
            'out' => is_string($out) ? $out : '',
            'err' => is_string($err) ? mb_substr($err, 0, 200) : '',
        ];
    }

    /**
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        if ($this->db === null) {
            return [];
        }
        try {
            $applied = array_map(
                static fn (array $row): string => (string) $row['name'],
                $this->db->fetchAll('SELECT name FROM migrations')
            );
            return self::pendingNames(array_keys(Schema::pgsqlMigrationFiles()), $applied);
        } catch (\Throwable) {
            return [];
        }
    }

    private function appDir(): string
    {
        if ($this->appDir !== null) {
            return $this->safePath($this->appDir);
        }
        $fromEnv = getenv('LIFEDASH_DEPLOY_APP_DIR');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $this->safePath($fromEnv);
        }
        return ROOT_DIR;
    }

    private function spoolRoot(): string
    {
        if ($this->spoolDir !== null) {
            return $this->safePath($this->spoolDir);
        }
        $fromEnv = getenv('LIFEDASH_DEPLOY_SPOOL');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $this->safePath($fromEnv);
        }
        return self::SPOOL_DIR;
    }

    private function safePath(string $path): string
    {
        if (!str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '..')) {
            throw new ValidationException('Deploy path is not absolute.');
        }
        if (preg_match('#\A/[A-Za-z0-9_./-]+\z#', $path) !== 1) {
            throw new ValidationException('Deploy path is not absolute.');
        }
        return rtrim($path, '/');
    }

    private function incomingDir(): string
    {
        return $this->spoolRoot() . '/incoming';
    }

    private function stateDir(): string
    {
        return $this->spoolRoot() . '/state';
    }

    private function jobsDir(): string
    {
        return $this->stateDir() . '/jobs';
    }

    private function spoolReady(): bool
    {
        $dir = $this->incomingDir();
        return is_dir($dir) && is_writable($dir);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeRequest(string $jobId, array $payload): void
    {
        $dir = $this->incomingDir();
        $tmp = $dir . '/.' . $jobId . '.tmp';
        $dest = $dir . '/' . $jobId . '.json';
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new ValidationException('Could not write the deploy request.');
        }
        chmod($tmp, 0640);
        if (!rename($tmp, $dest)) {
            @unlink($tmp);
            throw new ValidationException('Could not queue the deploy request.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queued(): array
    {
        $dir = $this->incomingDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $names = scandir($dir) ?: [];
        foreach ($names as $name) {
            if (preg_match('/^[0-9a-f]{32}\.json$/', $name) !== 1) {
                continue;
            }
            $data = $this->readContainedJson($dir, $name);
            if (!is_array($data)) {
                continue;
            }
            $id = $this->jobOrNull((string) ($data['job_id'] ?? ''));
            $sha = $this->shaOrNull((string) ($data['confirm_sha'] ?? ''));
            if ($id === null || $sha === null) {
                continue;
            }
            $out[] = [
                'id'           => $id,
                'confirm_sha'  => $sha,
                'state'        => 'queued',
                'requested_at' => is_string($data['requested_at'] ?? null) ? (string) $data['requested_at'] : null,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStateFile(string $name): ?array
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*\.json$/', $name) !== 1) {
            return null;
        }
        return $this->readContainedJson($this->stateDir(), $name);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readContainedJson(string $dir, string $name): ?array
    {
        $path = $this->containedFile($dir, $name);
        if ($path === null) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function containedFile(string $dir, string $name): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $name) !== 1) {
            return null;
        }
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            return null;
        }
        $real = realpath($path);
        $root = realpath($dir);
        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return $real;
    }

    private function lastJobId(): ?string
    {
        $last = $this->readStateFile('last.json');
        if (!is_array($last)) {
            return null;
        }
        return $this->jobOrNull((string) ($last['job_id'] ?? ''));
    }

    /**
     * @param array<string, mixed>|null $last
     */
    private function rateLimitedUntil(?array $last): ?string
    {
        if (!is_array($last)) {
            return null;
        }
        $state = (string) ($last['state'] ?? '');
        if (!in_array($state, ['queued', 'running', 'succeeded', 'failed'], true)) {
            return null;
        }
        $started = (string) ($last['started_at'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $started) !== 1) {
            return null;
        }
        $then = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $started, new \DateTimeZone('UTC'));
        if ($then === false) {
            return null;
        }
        $opens = $then->modify('+' . self::RATE_LIMIT_SECONDS . ' seconds');
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($opens <= $now) {
            return null;
        }
        return $opens->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function publicJob(?array $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $id = $this->jobOrNull((string) ($row['job_id'] ?? ''));
        if ($id === null) {
            return null;
        }
        $sha = $this->shaOrNull((string) ($row['confirm_sha'] ?? ''));
        return [
            'job_id'      => $id,
            'confirm_sha' => $sha,
            'state'       => is_string($row['state'] ?? null) ? (string) $row['state'] : null,
            'started_at'  => is_string($row['started_at'] ?? null) ? (string) $row['started_at'] : null,
            'finished_at' => is_string($row['finished_at'] ?? null) ? (string) $row['finished_at'] : null,
            'token_id'    => isset($row['token_id']) && is_numeric($row['token_id']) ? (int) $row['token_id'] : null,
            'token_name'  => $this->cleanName(isset($row['token_name']) ? (string) $row['token_name'] : null),
            'summary'     => is_string($row['summary'] ?? null) ? mb_substr((string) $row['summary'], 0, 200) : null,
        ];
    }

    private function tail(string $path, int $max): string
    {
        $size = filesize($path);
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        if (is_int($size) && $size > $max) {
            fseek($fh, -$max, SEEK_END);
        }
        $text = stream_get_contents($fh);
        fclose($fh);
        return is_string($text) ? $text : '';
    }

    private function cleanName(?string $name): string
    {
        $name = trim((string) $name);
        $name = preg_replace('/[^A-Za-z0-9 ._-]/', '', $name) ?? '';
        return mb_substr($name, 0, 64);
    }

    private function shaOrNull(string $value): ?string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[0-9a-f]{40}$/', $value) === 1 ? $value : null;
    }

    private function jobOrNull(string $value): ?string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[0-9a-f]{32}$/', $value) === 1 ? $value : null;
    }
}
