<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\Support\ValidationException;

/**
 * Jellyfin API. 10.11 wants Authorization: MediaBrowser Token. X-Emby-Token is only added after a 401.
 * /Items/Latest exists from 10.9. Older servers use /Users/{userId}/Items/Latest.
 * An API key has no user, so 10.9+ also needs userId.
 */
final class Jellyfin
{
    private ?string $resolvedUser = null;

    private bool $legacy = false;

    /** @var array{version: string, name: string, id: string, os: string}|null */
    private ?array $info = null;

    public function __construct(
        private readonly Http $http,
        private readonly string $user = '',
        private readonly string $token = '',
    ) {}

    public static function authorization(string $token): string
    {
        $token = str_replace(['\\', '"'], ['\\\\', '\\"'], $token);

        return 'MediaBrowser Token="' . $token . '", Client="Life-Dashboard", Device="server", DeviceId="lifedash", Version="1"';
    }

    /**
     * @return array{version: string, name: string, id: string, os: string}
     */
    public function status(): array
    {
        if ($this->info !== null) {
            return $this->info;
        }
        try {
            $row = $this->object($this->api('/System/Info'));
        } catch (HomelabException $e) {
            if ((int) ($e->extra['upstream_status'] ?? 0) !== 404) {
                throw $e;
            }
            // A Networking Base URL such as /jellyfin makes every path 404 at the origin.
            foreach (['/System/Info/Public', '/jellyfin/System/Info/Public'] as $probe) {
                try {
                    $this->api($probe);
                } catch (HomelabException) {
                    continue;
                }
            }
            throw new HomelabException(
                'Jellyfin returned 404 for /System/Info at ' . $this->http->origin() . '; if Jellyfin has a Base URL set (Dashboard > Networking), add it to the URL here, e.g. http://tower:8096/jellyfin',
                'homelab_error',
                422,
                $e->extra
            );
        }

        $this->info = [
            'version' => (string) ($row['Version'] ?? $row['version'] ?? ''),
            'name' => (string) ($row['ServerName'] ?? $row['serverName'] ?? 'Jellyfin'),
            'id' => (string) ($row['Id'] ?? $row['id'] ?? ''),
            'os' => (string) ($row['OperatingSystem'] ?? $row['operatingSystem'] ?? ''),
        ];

        return $this->info;
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function sessions(): array
    {
        $items = [];
        foreach ($this->list($this->api('/Sessions')) as $row) {
            $now = is_array($row['NowPlayingItem'] ?? null) ? $row['NowPlayingItem'] : null;
            $items[] = [
                'user' => (string) ($row['UserName'] ?? ''),
                'client' => (string) ($row['Client'] ?? ''),
                'device' => (string) ($row['DeviceName'] ?? ''),
                'now_playing' => $now === null ? '' : (string) ($now['Name'] ?? $now['SeriesName'] ?? ''),
                'playing' => $now !== null,
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $row = $this->object($this->api('/Items/Counts', ['userId' => $this->userId()]));
        $out = [];
        foreach (['MovieCount', 'SeriesCount', 'EpisodeCount', 'SongCount', 'AlbumCount', 'MusicVideoCount', 'BoxSetCount'] as $key) {
            $out[self::snake($key)] = Servarr::intish($row[$key] ?? 0);
        }

        return $out;
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function recent(): array
    {
        $userId = $this->userId();
        if (self::atLeast($this->status()['version'], 10, 9)) {
            $payload = $this->api('/Items/Latest', ['userId' => $userId, 'limit' => 16]);
        } else {
            $payload = $this->api('/Users/' . rawurlencode($userId) . '/Items/Latest', ['limit' => 16]);
        }
        $items = [];
        foreach ($this->items($payload) as $row) {
            $items[] = [
                'name' => (string) ($row['Name'] ?? $row['name'] ?? ''),
                'type' => (string) ($row['Type'] ?? $row['type'] ?? ''),
                'year' => Servarr::intish($row['ProductionYear'] ?? $row['productionYear'] ?? 0),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function search(string $term): array
    {
        $term = trim($term);
        if ($term === '' || strlen($term) > 200 || str_contains($term, "\n")) {
            throw ValidationException::withFields(['term' => 'Enter a search.']);
        }
        $payload = $this->api('/Search/Hints', [
            'searchTerm' => $term,
            'limit' => 20,
            'userId' => $this->userId(),
        ]);
        $hints = [];
        if (!array_is_list($payload) && is_array($payload['SearchHints'] ?? null)) {
            $hints = $payload['SearchHints'];
        } elseif (array_is_list($payload)) {
            $hints = $payload;
        }
        $items = [];
        foreach ($hints as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'name' => (string) ($row['Name'] ?? $row['name'] ?? ''),
                'type' => (string) ($row['Type'] ?? $row['type'] ?? ''),
                'year' => Servarr::intish($row['ProductionYear'] ?? $row['productionYear'] ?? 0),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>|list<mixed>
     */
    private function api(string $path, array $query = []): array
    {
        try {
            return $this->http->json('GET', $path, null, $query);
        } catch (HomelabException $e) {
            if ($this->legacy || $this->token === '' || (int) ($e->extra['upstream_status'] ?? 0) !== 401) {
                throw $e;
            }
            // Servers older than 10.11 still authenticate with the legacy header.
            $this->legacy = true;
            $this->http->withHeader('X-Emby-Token', $this->token);

            return $this->http->json('GET', $path, null, $query);
        }
    }

    private function userId(): string
    {
        if ($this->resolvedUser !== null) {
            return $this->resolvedUser;
        }
        $this->status();
        $wanted = trim($this->user);
        $admin = '';
        $first = '';
        $named = '';
        foreach ($this->list($this->api('/Users')) as $row) {
            $id = trim((string) ($row['Id'] ?? ''));
            if ($id === '' || str_contains($id, '/')) {
                continue;
            }
            $name = (string) ($row['Name'] ?? '');
            $policy = is_array($row['Policy'] ?? null) ? $row['Policy'] : [];
            $isAdmin = !empty($policy['IsAdministrator']) || !empty($row['IsAdministrator']);
            if ($first === '') {
                $first = $id;
            }
            if ($isAdmin && $admin === '') {
                $admin = $id;
            }
            if ($wanted !== '' && (strcasecmp($name, $wanted) === 0 || strcasecmp($id, $wanted) === 0)) {
                $named = $id;
            }
        }
        if ($wanted !== '' && $named === '') {
            throw new HomelabException(
                'Jellyfin has no user matching ' . $wanted . '. HTTP 200 /Users',
                'homelab_error',
                422,
                ['path' => '/Users', 'upstream_status' => 200]
            );
        }
        $id = $named !== '' ? $named : ($admin !== '' ? $admin : $first);
        if ($id === '') {
            throw new HomelabException(
                'Jellyfin returned no users. HTTP 200 /Users',
                'homelab_error',
                422,
                ['path' => '/Users', 'upstream_status' => 200]
            );
        }
        $this->resolvedUser = $id;

        return $id;
    }

    public static function atLeast(string $version, int $major, int $minor): bool
    {
        if (preg_match('/(\d+)\.(\d+)/', $version, $match) !== 1) {
            return true;
        }

        return ((int) $match[1] > $major) || ((int) $match[1] === $major && (int) $match[2] >= $minor);
    }

    /**
     * 10.8 and 10.9 both return a bare list. Some responses use an Items envelope.
     *
     * @param array<string, mixed>|list<mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function items(array $payload): array
    {
        if (array_is_list($payload)) {
            return $this->list($payload);
        }
        foreach (['Items', 'items'] as $key) {
            if (is_array($payload[$key] ?? null) && array_is_list($payload[$key])) {
                return $this->list($payload[$key]);
            }
        }
        throw new HomelabException('Jellyfin returned an unexpected list. HTTP 200 /Items/Latest', 'homelab_invalid', 502, ['path' => '/Items/Latest']);
    }

    private function object(array $payload): array
    {
        if (array_is_list($payload)) {
            throw new HomelabException('Jellyfin returned an unexpected response.', 'homelab_invalid', 502);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function list(array $payload): array
    {
        if (!array_is_list($payload)) {
            throw new HomelabException('Jellyfin returned an unexpected list.', 'homelab_invalid', 502);
        }
        $rows = [];
        foreach ($payload as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function snake(string $key): string
    {
        $name = preg_replace('/Count$/', '', $key) ?? $key;
        $name = strtolower((string) (preg_replace('/([a-z])([A-Z])/', '$1_$2', $name) ?? $name));

        return $name;
    }
}
