<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\Support\ValidationException;

/**
 * Sonarr and Radarr v3. Both speak the same API with a different noun.
 * Header is X-Api-Key, set on the HTTP client before this runs.
 */
final class Servarr
{
    public function __construct(
        private readonly string $kind,
        private readonly Http $http,
    ) {
        if ($kind !== 'sonarr' && $kind !== 'radarr') {
            throw new HomelabException('Unknown Servarr app.', 'homelab_invalid', 422);
        }
    }

    /**
     * @return array{version: string, app: string, instance: string, os: string}
     */
    public function status(): array
    {
        $row = $this->object($this->http->json('GET', '/api/v3/system/status'));

        return [
            'version' => (string) ($row['version'] ?? ''),
            'app' => (string) ($row['appName'] ?? Catalog::label($this->kind)),
            'instance' => (string) ($row['instanceName'] ?? ''),
            'os' => (string) ($row['osName'] ?? ''),
        ];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function queue(): array
    {
        $query = ['pageSize' => 40];
        if ($this->kind === 'sonarr') {
            $query['includeUnknownSeriesItems'] = 'true';
        }
        $payload = $this->http->json('GET', '/api/v3/queue', null, $query);
        $rows = $this->records($payload);
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'title' => (string) ($row['title'] ?? ''),
                'status' => (string) ($row['status'] ?? $row['trackedDownloadStatus'] ?? ''),
                'protocol' => (string) ($row['protocol'] ?? ''),
                'size' => self::intish($row['size'] ?? 0),
                'left' => self::intish($row['sizeleft'] ?? 0),
                'indexer' => (string) ($row['indexer'] ?? ''),
            ];
        }

        return ['total' => $this->total($payload, count($items)), 'items' => $items];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function calendar(string $start, string $end): array
    {
        self::day($start, 'start');
        self::day($end, 'end');
        $payload = $this->http->json('GET', '/api/v3/calendar', null, [
            'start' => $start,
            'end' => $end,
            'unmonitored' => 'false',
        ]);
        $items = [];
        foreach ($this->records($payload) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $series = is_array($row['series'] ?? null) ? $row['series'] : [];
            $parent = $this->kind === 'sonarr'
                ? (string) ($series['title'] ?? $row['seriesTitle'] ?? '')
                : '';
            $items[] = [
                'id' => self::intish($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'parent' => $parent,
                'when' => (string) ($row['airDateUtc'] ?? $row['inCinemas'] ?? ''),
                'has_file' => (bool) ($row['hasFile'] ?? false),
                'monitored' => (bool) ($row['monitored'] ?? false),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function missing(): array
    {
        $payload = $this->http->json('GET', '/api/v3/wanted/missing', null, ['pageSize' => 40]);
        $items = [];
        foreach ($this->records($payload) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $series = is_array($row['series'] ?? null) ? $row['series'] : [];
            $items[] = [
                'id' => self::intish($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'parent' => (string) ($series['title'] ?? ''),
                'when' => (string) ($row['airDateUtc'] ?? $row['inCinemas'] ?? ''),
            ];
        }

        return ['total' => $this->total($payload, count($items)), 'items' => $items];
    }

    /**
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function library(): array
    {
        $path = $this->kind === 'sonarr' ? '/api/v3/series' : '/api/v3/movie';
        $payload = $this->http->json('GET', $path);
        $items = [];
        foreach ($this->records($payload) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $remote = $this->kind === 'sonarr' ? ($row['tvdbId'] ?? 0) : ($row['tmdbId'] ?? 0);
            $complete = $this->kind === 'sonarr'
                ? self::intish($row['episodeFileCount'] ?? 0) . '/' . self::intish($row['episodeCount'] ?? 0)
                : ((bool) ($row['hasFile'] ?? false) ? 'file' : 'missing');
            $items[] = [
                'id' => self::intish($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'year' => self::intish($row['year'] ?? 0),
                'monitored' => (bool) ($row['monitored'] ?? false),
                'remote_id' => self::intish($remote),
                'complete' => $complete,
            ];
        }

        return ['total' => count($items), 'items' => array_slice($items, 0, 40)];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function lookup(string $term): array
    {
        $term = trim($term);
        if ($term === '' || strlen($term) > 200 || str_contains($term, "\n")) {
            throw ValidationException::withFields(['term' => 'Enter a title to look up.']);
        }
        $path = $this->kind === 'sonarr' ? '/api/v3/series/lookup' : '/api/v3/movie/lookup';
        $payload = $this->http->json('GET', $path, null, ['term' => $term]);
        $items = [];
        foreach ($this->records($payload) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $remote = $this->kind === 'sonarr' ? ($row['tvdbId'] ?? 0) : ($row['tmdbId'] ?? 0);
            $overview = trim((string) ($row['overview'] ?? ''));
            $items[] = [
                'title' => (string) ($row['title'] ?? ''),
                'year' => self::intish($row['year'] ?? 0),
                'remote_id' => self::intish($remote),
                'overview' => mb_substr($overview, 0, 240),
                'already' => self::intish($row['id'] ?? 0) > 0,
            ];
            if (count($items) >= 12) {
                break;
            }
        }

        return ['items' => $items];
    }

    /**
     * @return array{profiles: list<array{id: int, name: string}>, folders: list<array{id: int, path: string, free: int}>}
     */
    public function options(): array
    {
        $profiles = [];
        foreach ($this->records($this->http->json('GET', '/api/v3/qualityprofile')) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $profiles[] = ['id' => self::intish($row['id'] ?? 0), 'name' => (string) ($row['name'] ?? '')];
        }
        $folders = [];
        foreach ($this->records($this->http->json('GET', '/api/v3/rootfolder')) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $folders[] = [
                'id' => self::intish($row['id'] ?? 0),
                'path' => (string) ($row['path'] ?? ''),
                'free' => self::intish($row['freeSpace'] ?? 0),
            ];
        }

        return ['profiles' => $profiles, 'folders' => $folders];
    }

    /**
     * @return array{items: list<array{source: string, type: string, message: string}>}
     */
    public function health(): array
    {
        $items = [];
        foreach ($this->records($this->http->json('GET', '/api/v3/health')) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'source' => (string) ($row['source'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, title: string}
     */
    public function add(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $folder = trim((string) ($input['root_folder'] ?? ''));
        $profile = self::intish($input['quality_profile_id'] ?? 0);
        $remote = self::intish($input['remote_id'] ?? ($this->kind === 'sonarr' ? ($input['tvdb_id'] ?? 0) : ($input['tmdb_id'] ?? 0)));
        $search = self::boolish($input['search'] ?? false, 'search');
        if ($title === '' || strlen($title) > 300) {
            throw ValidationException::withFields(['title' => 'Enter the title.']);
        }
        if ($folder === '' || strlen($folder) > 400 || str_contains($folder, "\n")) {
            throw ValidationException::withFields(['root_folder' => 'Choose a root folder from the list.']);
        }
        if ($profile < 1) {
            throw ValidationException::withFields(['quality_profile_id' => 'Choose a quality profile from the list.']);
        }
        if ($remote < 1) {
            $field = $this->kind === 'sonarr' ? 'tvdb_id' : 'tmdb_id';
            throw ValidationException::withFields([$field => 'Look the title up first and use the id it returns.']);
        }
        if ($this->kind === 'sonarr') {
            $body = [
                'title' => $title,
                'tvdbId' => $remote,
                'qualityProfileId' => $profile,
                'rootFolderPath' => $folder,
                'monitored' => true,
                'seasonFolder' => true,
                'addOptions' => [
                    'searchForMissingEpisodes' => $search,
                    'monitor' => 'all',
                ],
            ];
            $created = $this->object($this->http->json('POST', '/api/v3/series', $body, [], 20));
        } else {
            $body = [
                'title' => $title,
                'tmdbId' => $remote,
                'qualityProfileId' => $profile,
                'rootFolderPath' => $folder,
                'monitored' => true,
                'minimumAvailability' => 'released',
                'addOptions' => ['searchForMovie' => $search],
            ];
            $created = $this->object($this->http->json('POST', '/api/v3/movie', $body, [], 20));
        }

        return ['id' => self::intish($created['id'] ?? 0), 'title' => (string) ($created['title'] ?? $title)];
    }

    /**
     * @return array{id: int, name: string, status: string}
     */
    public function search(int $id): array
    {
        if ($id < 1) {
            throw ValidationException::withFields(['id' => 'Choose an item from the library.']);
        }
        $body = $this->kind === 'sonarr'
            ? ['name' => 'SeriesSearch', 'seriesId' => $id]
            : ['name' => 'MoviesSearch', 'movieIds' => [$id]];
        $command = $this->object($this->http->json('POST', '/api/v3/command', $body, [], 20));

        return [
            'id' => self::intish($command['id'] ?? 0),
            'name' => (string) ($command['name'] ?? $body['name']),
            'status' => (string) ($command['status'] ?? ''),
        ];
    }

    /**
     * @return array{id: int, deleted: true, delete_files: bool}
     */
    public function delete(int $id, bool $deleteFiles): array
    {
        if ($id < 1) {
            throw ValidationException::withFields(['id' => 'Choose an item from the library.']);
        }
        $path = ($this->kind === 'sonarr' ? '/api/v3/series/' : '/api/v3/movie/') . $id;
        $this->http->call('DELETE', $path, null, ['deleteFiles' => $deleteFiles ? 'true' : 'false'], 20, 'application/json');

        return ['id' => $id, 'deleted' => true, 'delete_files' => $deleteFiles];
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return list<mixed>
     */
    private function records(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        $records = $payload['records'] ?? null;
        if (is_array($records)) {
            return array_values($records);
        }

        return [];
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    private function total(array $payload, int $fallback): int
    {
        if (!array_is_list($payload) && isset($payload['totalRecords']) && is_numeric($payload['totalRecords'])) {
            return (int) $payload['totalRecords'];
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return array<string, mixed>
     */
    private function object(array $payload): array
    {
        if (array_is_list($payload)) {
            throw new HomelabException(Catalog::label($this->kind) . ' returned an unexpected response.', 'homelab_invalid', 502);
        }

        return $payload;
    }

    public static function day(string $value, string $field): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw ValidationException::withFields([$field => 'Use a date as YYYY-MM-DD.']);
        }
    }

    public static function boolish(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === null || $value === '') {
            return false;
        }

        throw ValidationException::withFields([$field => $field . ' must be true or false.']);
    }

    public static function intish(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
