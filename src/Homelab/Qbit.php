<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\Support\ValidationException;

/**
 * qBittorrent WebUI API v2.
 * Login is a form post that returns an SID cookie. qBittorrent 5 renamed pause and resume
 * to stop and start, so a 404 falls back to the older names.
 */
final class Qbit
{
    private bool $loggedIn = false;

    public function __construct(
        private readonly Http $http,
        private readonly string $username,
        private readonly string $password,
    ) {}

    /**
     * @return array{version: string, download_speed: int, upload_speed: int, connection: string, dht: int}
     */
    public function status(): array
    {
        $this->login();
        $version = trim($this->http->call('GET', '/api/v2/app/version', null, [], 8, 'application/json')['body'], " \t\n\r\0\x0B\"");
        $info = $this->http->json('GET', '/api/v2/transfer/info');
        if (array_is_list($info)) {
            throw new HomelabException('qBittorrent returned an unexpected transfer info.', 'homelab_invalid', 502);
        }

        return [
            'version' => $version,
            'download_speed' => Servarr::intish($info['dl_info_speed'] ?? 0),
            'upload_speed' => Servarr::intish($info['up_info_speed'] ?? 0),
            'connection' => (string) ($info['connection_status'] ?? ''),
            'dht' => Servarr::intish($info['dht_nodes'] ?? 0),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function torrents(string $filter, string $category, string $hashes): array
    {
        $this->login();
        $query = [];
        if ($filter !== '') {
            $query['filter'] = self::filter($filter);
        }
        if ($category !== '') {
            $query['category'] = $category;
        }
        if ($hashes !== '') {
            $query['hashes'] = $hashes;
        }
        $payload = $this->http->json('GET', '/api/v2/torrents/info', null, $query);
        if (!array_is_list($payload)) {
            throw new HomelabException('qBittorrent returned an unexpected torrent list.', 'homelab_invalid', 502);
        }
        $items = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'hash' => (string) ($row['hash'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'progress' => is_numeric($row['progress'] ?? null) ? (float) $row['progress'] : 0.0,
                'size' => Servarr::intish($row['size'] ?? 0),
                'download_speed' => Servarr::intish($row['dlspeed'] ?? 0),
                'upload_speed' => Servarr::intish($row['upspeed'] ?? 0),
                'category' => (string) ($row['category'] ?? ''),
                'eta' => Servarr::intish($row['eta'] ?? 0),
            ];
            if (count($items) >= 80) {
                break;
            }
        }

        return ['items' => $items];
    }

    /**
     * @return array{items: list<array{name: string, save_path: string}>}
     */
    public function categories(): array
    {
        $this->login();
        $payload = $this->http->json('GET', '/api/v2/torrents/categories');
        if (array_is_list($payload)) {
            throw new HomelabException('qBittorrent returned an unexpected category list.', 'homelab_invalid', 502);
        }
        $items = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'name' => (string) ($row['name'] ?? ''),
                'save_path' => (string) ($row['savePath'] ?? ''),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array{hashes: string, action: string}
     */
    public function pause(string $hashes): array
    {
        return $this->verb($hashes, 'stop', 'pause', 'pause');
    }

    /**
     * @return array{hashes: string, action: string}
     */
    public function resume(string $hashes): array
    {
        return $this->verb($hashes, 'start', 'resume', 'resume');
    }

    /**
     * @return array{hashes: string, action: string}
     */
    public function recheck(string $hashes): array
    {
        $this->login();
        $this->http->form('POST', '/api/v2/torrents/recheck', ['hashes' => $hashes]);

        return ['hashes' => $hashes, 'action' => 'recheck'];
    }

    /**
     * @return array{hashes: string, deleted: true, delete_files: bool}
     */
    public function delete(string $hashes, bool $deleteFiles): array
    {
        $this->login();
        $this->http->form('POST', '/api/v2/torrents/delete', [
            'hashes' => $hashes,
            'deleteFiles' => $deleteFiles ? 'true' : 'false',
        ]);

        return ['hashes' => $hashes, 'deleted' => true, 'delete_files' => $deleteFiles];
    }

    /**
     * @return array{added: true, category: string}
     */
    public function add(string $urls, string $category): array
    {
        $urls = trim($urls);
        if ($urls === '' || strlen($urls) > 4000) {
            throw ValidationException::withFields(['urls' => 'Paste a magnet link or an http URL.']);
        }
        if (!preg_match('#^(magnet:\?|https?://)#i', $urls)) {
            throw ValidationException::withFields(['urls' => 'Use a magnet link or an http URL.']);
        }
        $category = trim($category);
        if (strlen($category) > 80 || str_contains($category, "\n")) {
            throw ValidationException::withFields(['category' => 'That category name is not valid.']);
        }
        $this->login();
        $fields = ['urls' => $urls];
        if ($category !== '') {
            $fields['category'] = $category;
        }
        $this->http->form('POST', '/api/v2/torrents/add', $fields, [], 20);

        return ['added' => true, 'category' => $category];
    }

    public static function filter(string $filter): string
    {
        $filter = strtolower(trim($filter));
        if ($filter === '') {
            return 'all';
        }
        $allowed = ['all', 'downloading', 'seeding', 'completed', 'paused', 'stopped', 'active', 'inactive', 'resumed', 'stalled', 'stalled_uploading', 'stalled_downloading', 'errored'];
        if (!in_array($filter, $allowed, true)) {
            throw ValidationException::withFields(['filter' => 'That torrent filter is not one qBittorrent accepts.']);
        }

        return $filter;
    }

    public static function hashes(mixed $value): string
    {
        $list = is_array($value) ? $value : [$value];
        $out = [];
        foreach ($list as $hash) {
            if (!is_string($hash) && !is_int($hash)) {
                throw ValidationException::withFields(['hash' => 'That torrent id is not valid.']);
            }
            $hash = trim((string) $hash);
            if (preg_match('/^[A-Za-z0-9:._-]{1,80}$/', $hash) !== 1) {
                throw ValidationException::withFields(['hash' => 'That torrent id is not valid.']);
            }
            $out[] = $hash;
        }
        if ($out === []) {
            throw ValidationException::withFields(['hash' => 'Choose a torrent.']);
        }

        return implode('|', $out);
    }

    /**
     * @return array{hashes: string, action: string}
     */
    private function verb(string $hashes, string $current, string $legacy, string $action): array
    {
        $this->login();
        try {
            $this->http->form('POST', '/api/v2/torrents/' . $current, ['hashes' => $hashes]);
        } catch (HomelabException $e) {
            if ((int) ($e->extra['upstream_status'] ?? 0) !== 404) {
                throw $e;
            }
            $this->http->form('POST', '/api/v2/torrents/' . $legacy, ['hashes' => $hashes]);
        }

        return ['hashes' => $hashes, 'action' => $action];
    }

    private function login(): void
    {
        if ($this->loggedIn) {
            return;
        }
        $response = $this->http->form('POST', '/api/v2/auth/login', [
            'username' => $this->username,
            'password' => $this->password,
        ]);
        $body = trim($response['body']);
        if (strcasecmp($body, 'Ok.') !== 0) {
            throw new HomelabException(
                'qBittorrent rejected the username or password.',
                'homelab_unauthorized',
                502,
                ['upstream_status' => $response['status']]
            );
        }
        $sid = Http::cookieValue($response['headers'], 'SID');
        if ($sid === '') {
            throw new HomelabException('qBittorrent did not return a session cookie.', 'homelab_unauthorized', 502);
        }
        $this->http->withCookie('SID=' . $sid);
        $this->loggedIn = true;
    }
}
