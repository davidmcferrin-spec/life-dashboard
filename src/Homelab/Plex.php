<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\Support\ValidationException;

/**
 * Plex Media Server. The token is the X-Plex-Token header. Responses are JSON.
 */
final class Plex
{
    public function __construct(private readonly Http $http) {}

    /**
     * @return array{version: string, name: string, id: string}
     */
    public function status(): array
    {
        $identity = $this->container($this->http->json('GET', '/identity'));
        $root = $this->container($this->http->json('GET', '/'));

        return [
            'version' => (string) ($identity['version'] ?? $root['version'] ?? ''),
            'name' => (string) ($root['friendlyName'] ?? 'Plex'),
            'id' => (string) ($identity['machineIdentifier'] ?? ''),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function sessions(): array
    {
        $box = $this->container($this->http->json('GET', '/status/sessions'));
        $items = [];
        foreach ($this->rows($box, 'Metadata') as $row) {
            $user = is_array($row['User'] ?? null) ? $row['User'] : [];
            $player = is_array($row['Player'] ?? null) ? $row['Player'] : [];
            $items[] = [
                'title' => (string) ($row['title'] ?? $row['grandparentTitle'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'user' => (string) ($user['title'] ?? ''),
                'player' => (string) ($player['product'] ?? $player['title'] ?? ''),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function libraries(): array
    {
        $box = $this->container($this->http->json('GET', '/library/sections'));
        $items = [];
        foreach ($this->rows($box, 'Directory') as $row) {
            $key = (string) ($row['key'] ?? '');
            $count = null;
            if ($key !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $key) === 1) {
                $all = $this->container($this->http->json('GET', '/library/sections/' . $key . '/all', null, [
                    'X-Plex-Container-Start' => 0,
                    'X-Plex-Container-Size' => 0,
                ]));
                if (isset($all['totalSize']) && is_numeric($all['totalSize'])) {
                    $count = (int) $all['totalSize'];
                } elseif (isset($all['size']) && is_numeric($all['size'])) {
                    $count = (int) $all['size'];
                }
            }
            $items[] = [
                'key' => $key,
                'title' => (string) ($row['title'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'count' => $count,
            ];
            if (count($items) >= 12) {
                break;
            }
        }

        return ['items' => $items];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function recent(): array
    {
        $box = $this->container($this->http->json('GET', '/library/recentlyAdded'));
        $items = [];
        foreach ($this->rows($box, 'Metadata') as $row) {
            $items[] = [
                'title' => (string) ($row['title'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'library' => (string) ($row['librarySectionTitle'] ?? ''),
                'year' => Servarr::intish($row['year'] ?? 0),
            ];
            if (count($items) >= 16) {
                break;
            }
        }

        return ['items' => $items];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 200 || str_contains($query, "\n")) {
            throw ValidationException::withFields(['query' => 'Enter a search.']);
        }
        $box = $this->container($this->http->json('GET', '/hubs/search', null, ['query' => $query, 'limit' => 8]));
        $items = [];
        foreach ($this->rows($box, 'Hub') as $hub) {
            $hubTitle = (string) ($hub['title'] ?? '');
            foreach ($this->rows($hub, 'Metadata') as $row) {
                $items[] = [
                    'title' => (string) ($row['title'] ?? ''),
                    'type' => (string) ($row['type'] ?? $hubTitle),
                    'year' => Servarr::intish($row['year'] ?? 0),
                ];
                if (count($items) >= 20) {
                    break 2;
                }
            }
        }

        return ['items' => $items];
    }

    /**
     * @return array{key: string, refreshed: true}
     */
    public function refresh(string $key): array
    {
        $key = trim($key);
        if ($key === '' || preg_match('/^[A-Za-z0-9._-]+$/', $key) !== 1) {
            throw ValidationException::withFields(['key' => 'Choose a library section.']);
        }
        $this->http->call('GET', '/library/sections/' . $key . '/refresh', null, [], 20, 'application/json');

        return ['key' => $key, 'refreshed' => true];
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return array<string, mixed>
     */
    private function container(array $payload): array
    {
        if (array_is_list($payload)) {
            throw new HomelabException('Plex returned an unexpected response.', 'homelab_invalid', 502);
        }
        $box = $payload['MediaContainer'] ?? $payload;
        if (!is_array($box) || array_is_list($box)) {
            throw new HomelabException('Plex returned an unexpected response.', 'homelab_invalid', 502);
        }

        return $box;
    }

    /**
     * @param array<string, mixed> $box
     * @return list<array<string, mixed>>
     */
    private function rows(array $box, string $key): array
    {
        $rows = [];
        foreach (is_array($box[$key] ?? null) ? $box[$key] : [] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
