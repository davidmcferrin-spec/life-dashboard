<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\Config;

/** Runs the Python sync worker. The diff and conflict rules live there once. */
final class SyncBridge
{
    public function __construct(private readonly ?string $python = null) {}

    /**
     * @return array<string, mixed>
     */
    public function pull(int $userId, ?int $playlistId = null): array
    {
        return $this->run('pull', $userId, $playlistId);
    }

    /**
     * @return array<string, mixed>
     */
    public function push(int $userId, ?int $playlistId = null): array
    {
        return $this->run('push', $userId, $playlistId);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(string $action, int $userId, ?int $playlistId): array
    {
        $python = $this->python ?? self::pythonBinary();
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

    private static function pythonBinary(): string
    {
        $configured = Config::maybe('LIFEDASH_PYTHON');
        if (is_string($configured) && $configured !== '' && !str_starts_with($configured, 'CHANGE_ME') && is_executable($configured)) {
            return $configured;
        }
        if (is_executable('/srv/lifedash/venv/bin/python')) {
            return '/srv/lifedash/venv/bin/python';
        }
        return 'python3';
    }
}
