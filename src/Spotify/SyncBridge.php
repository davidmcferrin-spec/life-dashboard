<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\Config;
use Dashboard\Settings\ServiceControl;

/** Queues a Spotify sync. The venv worker owns the diff and conflict rules. */
final class SyncBridge
{
    public const PYTHON_UNAVAILABLE = 'Python environment for Spotify sync is not available to the web server';

    public function __construct(
        private readonly ?string $python = null,
        private readonly ?ServiceControl $services = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pull(int $userId, ?int $playlistId = null): array
    {
        return $this->dispatch('pull', $userId, $playlistId);
    }

    /**
     * @return array<string, mixed>
     */
    public function push(int $userId, ?int $playlistId = null): array
    {
        return $this->dispatch('push', $userId, $playlistId);
    }

    /**
     * Connect must succeed even when the worker is not ready. The timer syncs a new account.
     */
    public function pullDeferred(int $userId, ?int $playlistId = null): void
    {
        try {
            $this->pull($userId, $playlistId);
        } catch (\Throwable $e) {
            if ($e->getMessage() !== self::PYTHON_UNAVAILABLE) {
                error_log('[Dashboard][Spotify] first sync deferred: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param list<string> $candidates
     */
    public static function usableInterpreter(array $candidates): ?string
    {
        foreach ($candidates as $bin) {
            if (!is_string($bin) || !str_starts_with($bin, '/') || !str_contains($bin, '/venv/')) {
                continue;
            }
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function candidatePaths(): array
    {
        $paths = [];
        $configured = Config::maybe('LIFEDASH_PYTHON');
        if (is_string($configured) && $configured !== '' && !str_starts_with($configured, 'CHANGE_ME')) {
            $paths[] = $configured;
        }
        $paths[] = '/srv/lifedash/venv/bin/python';
        if (defined('ROOT_DIR')) {
            $paths[] = dirname(ROOT_DIR) . '/venv/bin/python';
        }

        return $paths;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatch(string $action, int $userId, ?int $playlistId): array
    {
        if ($this->python !== null) {
            return $this->run($action, $userId, $playlistId, $this->python);
        }
        $services = $this->services ?? new ServiceControl();
        if ($services->ready()) {
            $fields = ['user_id' => (string) $userId];
            if ($playlistId !== null) {
                $fields['playlist_id'] = (string) $playlistId;
            }
            $queued = $services->request('spotify-' . $action, $fields);

            return ['ok' => true, 'queued' => true, 'id' => $queued['id']];
        }
        $python = self::usableInterpreter(self::candidatePaths());
        if ($python === null) {
            error_log(self::PYTHON_UNAVAILABLE);
            throw new SpotifyException(self::PYTHON_UNAVAILABLE);
        }

        return $this->run($action, $userId, $playlistId, $python);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(string $action, int $userId, ?int $playlistId, string $python): array
    {
        $cmd = [$python, '-m', 'lifedash.spotify_sync', $action, '--user', (string) $userId];
        if ($playlistId !== null) {
            $cmd[] = '--playlist';
            $cmd[] = (string) $playlistId;
        }
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['PYTHONPATH'] = ROOT_DIR . '/services';
        $env['PYTHONUNBUFFERED'] = '1';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, ROOT_DIR, $env);
        if (!is_resource($proc)) {
            throw new SpotifyException('Spotify sync is not available on this server.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = time();
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if ((time() - $started) > 120) {
                proc_terminate($proc, 9);
                proc_close($proc);
                throw new SpotifyException('Spotify sync timed out.');
            }
            usleep(100000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            $detail = SpotifyHttp::scrub(trim($stderr) !== '' ? trim($stderr) : trim($stdout));
            throw new SpotifyException($detail !== '' ? $detail : 'Spotify sync failed.', 502);
        }
        if ($code !== 0 || empty($decoded['ok'])) {
            $error = SpotifyHttp::scrub((string) ($decoded['error'] ?? 'Spotify sync failed.'));
            throw new SpotifyException($error, 502);
        }

        return $decoded;
    }
}
