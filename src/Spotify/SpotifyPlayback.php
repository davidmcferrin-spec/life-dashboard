<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\Support\ValidationException;

/** Playback and search. These stay in PHP so the browser is not waiting on the sync worker. */
final class SpotifyPlayback
{
    public function __construct(
        private readonly SpotifyAccount $account,
        private readonly SpotifyHttp $http = new SpotifyHttp(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(int $userId, string $q, string $type): array
    {
        $q = trim($q);
        if ($q === '' || strlen($q) > 200) {
            throw ValidationException::withFields(['q' => 'Search needs 1 to 200 characters.']);
        }
        $allowed = ['track', 'artist', 'album'];
        $types = array_values(array_filter(array_map('trim', explode(',', $type)), static fn (string $t): bool => $t !== ''));
        if ($types === []) {
            $types = ['track'];
        }
        foreach ($types as $one) {
            if (!in_array($one, $allowed, true)) {
                throw ValidationException::withFields(['type' => 'type must be track, artist, or album.']);
            }
        }
        $data = $this->account->call($userId, fn (string $token): array => $this->http->api(
            'GET',
            '/search',
            $token,
            null,
            self::searchParams($q, implode(',', $types)),
        ));
        return [
            'tracks' => self::tracks($data['tracks']['items'] ?? []),
            'artists' => self::artists($data['artists']['items'] ?? []),
            'albums' => self::albums($data['albums']['items'] ?? []),
        ];
    }

    /**
     * Spotify search answers 400 Invalid limit when limit is above 10.
     *
     * @return array{q: string, type: string, limit: int}
     */
    public static function searchParams(string $q, string $type): array
    {
        return [
            'q' => $q,
            'type' => $type,
            'limit' => 10,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function track(int $userId, string $id): array
    {
        if (preg_match('/^[A-Za-z0-9]+$/', $id) !== 1) {
            throw ValidationException::withFields(['id' => 'Unknown Spotify track id.']);
        }
        $item = $this->account->call($userId, fn (string $token): array => $this->http->api('GET', '/tracks/' . $id, $token));
        $tracks = self::tracks([$item]);
        if ($tracks === []) {
            throw new SpotifyException('Spotify did not return that track.', 404);
        }
        return ['track' => $tracks[0]];
    }

    /**
     * @return array<string, mixed>
     */
    public function now(int $userId): array
    {
        $data = $this->account->call($userId, fn (string $token): array => $this->http->api('GET', '/me/player', $token));
        $now = self::nowPlaying($data);
        $this->writeCache($userId, $now);
        return ['now' => $now];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cachedNow(int $userId, bool $refresh): ?array
    {
        $path = self::cachePath($userId);
        if (!$refresh && is_file($path)) {
            $raw = file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && isset($decoded['fetched_at']) && (time() - (int) $decoded['fetched_at']) < 15) {
                unset($decoded['fetched_at']);
                return $decoded;
            }
        }
        try {
            $now = $this->now($userId)['now'];
            return $now;
        } catch (SpotifyException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function devices(int $userId): array
    {
        $data = $this->account->call($userId, fn (string $token): array => $this->http->api('GET', '/me/player/devices', $token));
        $devices = [];
        foreach ($data['devices'] ?? [] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $devices[] = [
                'id' => (string) ($device['id'] ?? ''),
                'name' => (string) ($device['name'] ?? ''),
                'type' => (string) ($device['type'] ?? ''),
                'is_active' => !empty($device['is_active']),
                'volume_percent' => isset($device['volume_percent']) ? (int) $device['volume_percent'] : null,
            ];
        }
        return ['devices' => $devices];
    }

    /**
     * @return array<string, mixed>
     */
    public function queue(int $userId): array
    {
        $data = $this->account->call($userId, fn (string $token): array => $this->http->api('GET', '/me/player/queue', $token));
        return [
            'now' => isset($data['currently_playing']) && is_array($data['currently_playing'])
                ? (self::tracks([$data['currently_playing']])[0] ?? null)
                : null,
            'queue' => self::tracks($data['queue'] ?? []),
        ];
    }

    /**
     * Browser-only. The refresh token is not included.
     *
     * @return array{access_token: string, expires_in: int}
     */
    public function sdkToken(int $userId): array
    {
        $token = $this->account->accessToken($userId);
        return ['access_token' => $token, 'expires_in' => 60];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function command(int $userId, string $action, array $body): array
    {
        $device = self::deviceId($body['device_id'] ?? null);
        $query = $device !== null ? ['device_id' => $device] : [];
        $this->account->call($userId, function (string $token) use ($action, $body, $query): array {
            return match ($action) {
                'play' => $this->http->api('PUT', '/me/player/play', $token, self::playJson($body), $query),
                'pause' => $this->http->api('PUT', '/me/player/pause', $token, null, $query),
                'next' => $this->http->api('POST', '/me/player/next', $token, null, $query),
                'previous' => $this->http->api('POST', '/me/player/previous', $token, null, $query),
                'seek' => $this->http->api('PUT', '/me/player/seek', $token, null, $query + [
                    'position_ms' => self::bounded($body['position_ms'] ?? null, 'position_ms', 0, 86400000),
                ]),
                'volume' => $this->http->api('PUT', '/me/player/volume', $token, null, $query + [
                    'volume_percent' => self::bounded($body['volume_percent'] ?? null, 'volume_percent', 0, 100),
                ]),
                'shuffle' => $this->http->api('PUT', '/me/player/shuffle', $token, null, $query + [
                    'state' => self::boolState($body['state'] ?? null),
                ]),
                'repeat' => $this->http->api('PUT', '/me/player/repeat', $token, null, $query + [
                    'state' => self::repeat($body['state'] ?? null),
                ]),
                'queue' => $this->http->api('POST', '/me/player/queue', $token, null, $query + [
                    'uri' => self::oneUri($body['uri'] ?? null),
                ]),
                'transfer' => $this->http->api('PUT', '/me/player', $token, self::transferPayload($body)),
                default => throw ValidationException::withFields(['action' => 'Unknown playback action.']),
            };
        });
        return ['ok' => true];
    }

    /**
     * @param array<string, mixed>|null $now
     */
    private function writeCache(int $userId, ?array $now): void
    {
        $dir = dirname(self::cachePath($userId));
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $payload = $now ?? ['is_playing' => false];
        $payload['fetched_at'] = time();
        file_put_contents(self::cachePath($userId), json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private static function cachePath(int $userId): string
    {
        return ROOT_DIR . '/storage/cache/spotify-now-' . $userId . '.json';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function nowPlaying(array $data): ?array
    {
        $item = $data['item'] ?? null;
        if (!is_array($item)) {
            return [
                'is_playing' => !empty($data['is_playing']),
                'name' => null,
                'artists' => [],
                'image_url' => null,
                'progress_ms' => (int) ($data['progress_ms'] ?? 0),
                'duration_ms' => 0,
                'uri' => null,
                'device' => is_array($data['device'] ?? null) ? (string) ($data['device']['name'] ?? '') : null,
                'shuffle' => !empty($data['shuffle_state']),
                'repeat' => self::repeatMode($data['repeat_state'] ?? null),
            ];
        }
        $tracks = self::tracks([$item]);
        $track = $tracks[0] ?? null;
        if ($track === null) {
            return null;
        }
        $track['is_playing'] = !empty($data['is_playing']);
        $track['progress_ms'] = (int) ($data['progress_ms'] ?? 0);
        $track['device'] = is_array($data['device'] ?? null) ? (string) ($data['device']['name'] ?? '') : null;
        $track['shuffle'] = !empty($data['shuffle_state']);
        $track['repeat'] = self::repeatMode($data['repeat_state'] ?? null);
        return $track;
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    public static function tracks(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || ($item['type'] ?? 'track') === 'episode' || empty($item['id'])) {
                continue;
            }
            $artists = [];
            foreach ($item['artists'] ?? [] as $artist) {
                if (is_array($artist) && isset($artist['name'])) {
                    $artists[] = (string) $artist['name'];
                }
            }
            $images = $item['album']['images'] ?? [];
            $image = is_array($images) && isset($images[0]['url']) ? (string) $images[0]['url'] : null;
            $out[] = [
                'id' => (string) $item['id'],
                'uri' => (string) ($item['uri'] ?? ('spotify:track:' . $item['id'])),
                'name' => (string) ($item['name'] ?? ''),
                'artists' => $artists,
                'album' => isset($item['album']['name']) ? (string) $item['album']['name'] : null,
                'duration_ms' => (int) ($item['duration_ms'] ?? 0),
                'explicit' => !empty($item['explicit']),
                'image_url' => $image,
            ];
        }
        return $out;
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private static function artists(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $images = $item['images'] ?? [];
            $out[] = [
                'id' => (string) $item['id'],
                'uri' => (string) ($item['uri'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'image_url' => is_array($images) && isset($images[0]['url']) ? (string) $images[0]['url'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param mixed $items
     * @return list<array<string, mixed>>
     */
    private static function albums(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $images = $item['images'] ?? [];
            $artists = [];
            foreach ($item['artists'] ?? [] as $artist) {
                if (is_array($artist) && isset($artist['name'])) {
                    $artists[] = (string) $artist['name'];
                }
            }
            $out[] = [
                'id' => (string) $item['id'],
                'uri' => (string) ($item['uri'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'artists' => $artists,
                'image_url' => is_array($images) && isset($images[0]['url']) ? (string) $images[0]['url'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function playBody(array $body): array
    {
        $uris = $body['uris'] ?? null;
        $context = isset($body['context_uri']) ? trim((string) $body['context_uri']) : '';
        if ($context !== '' && preg_match('/^spotify:(playlist|album|artist):[A-Za-z0-9]+$/', $context) !== 1) {
            throw ValidationException::withFields(['context_uri' => 'context_uri must be a Spotify playlist, album, or artist URI.']);
        }
        if (is_array($uris) && $uris !== []) {
            return ['uris' => SpotifyLibrary::uris($uris)];
        }
        if ($context !== '') {
            $payload = ['context_uri' => $context];
            if (isset($body['offset']) && is_numeric($body['offset'])) {
                $payload['offset'] = ['position' => max(0, (int) $body['offset'])];
            }
            return $payload;
        }
        return [];
    }

    /**
     * An empty play body resumes the active device. JSON must be an object.
     *
     * @param array<string, mixed> $body
     */
    private static function playJson(array $body): array|\stdClass
    {
        $payload = self::playBody($body);
        return $payload === [] ? (object) [] : $payload;
    }

    private static function oneUri(mixed $value): string
    {
        $uris = SpotifyLibrary::uris([$value]);
        return $uris[0];
    }

    private static function deviceId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (string) $value;
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id) !== 1) {
            throw ValidationException::withFields(['device_id' => 'Unknown device id.']);
        }
        return $id;
    }

    /**
     * Omitting play keeps the current playback state. An explicit play forces it.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function transferPayload(array $body): array
    {
        $payload = ['device_ids' => [self::requireDevice($body['device_id'] ?? null)]];
        if (array_key_exists('play', $body)) {
            $payload['play'] = self::truthy($body['play']);
        }
        return $payload;
    }

    private static function requireDevice(mixed $value): string
    {
        return self::deviceId($value) ?? throw ValidationException::withFields(['device_id' => 'Choose a device.']);
    }

    private static function bounded(mixed $value, string $field, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            throw ValidationException::withFields([$field => $field . ' must be a number.']);
        }
        $n = (int) $value;
        if ($n < $min || $n > $max) {
            throw ValidationException::withFields([$field => $field . " must be between {$min} and {$max}."]);
        }
        return $n;
    }

    private static function boolState(mixed $value): string
    {
        return self::truthy($value) ? 'true' : 'false';
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private static function repeatMode(mixed $value): string
    {
        $state = (string) $value;
        return in_array($state, ['off', 'track', 'context'], true) ? $state : 'off';
    }

    private static function repeat(mixed $value): string
    {
        $state = (string) $value;
        if (!in_array($state, ['off', 'track', 'context'], true)) {
            throw ValidationException::withFields(['state' => 'Repeat must be off, track, or context.']);
        }
        return $state;
    }
}
