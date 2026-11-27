<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\Support\ValidationException;

/**
 * Jellyfin API. The key is the X-Emby-Token header.
 */
final class Jellyfin
{
    public function __construct(private readonly Http $http) {}

    /**
     * @return array{version: string, name: string, id: string, os: string}
     */
    public function status(): array
    {
        $row = $this->object($this->http->json('GET', '/System/Info'));

        return [
            'version' => (string) ($row['Version'] ?? ''),
            'name' => (string) ($row['ServerName'] ?? 'Jellyfin'),
            'id' => (string) ($row['Id'] ?? ''),
            'os' => (string) ($row['OperatingSystem'] ?? ''),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function sessions(): array
    {
        $items = [];
        foreach ($this->list($this->http->json('GET', '/Sessions')) as $row) {
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
        $row = $this->object($this->http->json('GET', '/Items/Counts'));
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
        $payload = $this->http->json('GET', '/Items/Latest', null, ['Limit' => 16]);
        $items = [];
        foreach ($this->list($payload) as $row) {
            $items[] = [
                'name' => (string) ($row['Name'] ?? ''),
                'type' => (string) ($row['Type'] ?? ''),
                'year' => Servarr::intish($row['ProductionYear'] ?? 0),
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
        $payload = $this->http->json('GET', '/Search/Hints', null, ['searchTerm' => $term, 'limit' => 20]);
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
                'name' => (string) ($row['Name'] ?? ''),
                'type' => (string) ($row['Type'] ?? ''),
                'year' => Servarr::intish($row['ProductionYear'] ?? 0),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return array<string, mixed>
     */
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
