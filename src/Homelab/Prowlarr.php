<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\Support\ValidationException;

/**
 * Prowlarr v1. The API key is the X-Api-Key header on the HTTP client.
 */
final class Prowlarr
{
    public function __construct(private readonly Http $http) {}

    /**
     * @return array{version: string, app: string, instance: string, os: string}
     */
    public function status(): array
    {
        $row = $this->http->json('GET', '/api/v1/system/status');
        if (array_is_list($row)) {
            throw new HomelabException('Prowlarr returned an unexpected status.', 'homelab_invalid', 502);
        }

        return [
            'version' => (string) ($row['version'] ?? ''),
            'app' => (string) ($row['appName'] ?? 'Prowlarr'),
            'instance' => (string) ($row['instanceName'] ?? ''),
            'os' => (string) ($row['osName'] ?? ''),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function indexers(): array
    {
        $items = [];
        foreach ($this->list($this->http->json('GET', '/api/v1/indexer')) as $row) {
            $items[] = [
                'id' => Servarr::intish($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'enabled' => (bool) ($row['enable'] ?? false),
                'protocol' => (string) ($row['protocol'] ?? ''),
                'privacy' => (string) ($row['privacy'] ?? ''),
                'implementation' => (string) ($row['implementation'] ?? ''),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array{items: list<array{source: string, type: string, message: string}>}
     */
    public function health(): array
    {
        $items = [];
        foreach ($this->list($this->http->json('GET', '/api/v1/health')) as $row) {
            $items[] = [
                'source' => (string) ($row['source'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
            ];
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
        $payload = $this->http->json('GET', '/api/v1/search', null, [
            'query' => $query,
            'type' => 'search',
        ], 25);
        $items = [];
        foreach ($this->list($payload) as $row) {
            $items[] = [
                'title' => (string) ($row['title'] ?? ''),
                'indexer' => (string) ($row['indexer'] ?? ''),
                'size' => Servarr::intish($row['size'] ?? 0),
                'seeders' => Servarr::intish($row['seeders'] ?? 0),
                'leechers' => Servarr::intish($row['leechers'] ?? 0),
                'protocol' => (string) ($row['protocol'] ?? ''),
            ];
            if (count($items) >= 40) {
                break;
            }
        }

        return ['items' => $items];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function apps(): array
    {
        $items = [];
        foreach ($this->list($this->http->json('GET', '/api/v1/applications')) as $row) {
            $items[] = [
                'id' => Servarr::intish($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'implementation' => (string) ($row['implementation'] ?? ''),
                'sync_level' => (string) ($row['syncLevel'] ?? ''),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function list(array $payload): array
    {
        if (!array_is_list($payload)) {
            throw new HomelabException('Prowlarr returned an unexpected list.', 'homelab_invalid', 502);
        }
        $rows = [];
        foreach ($payload as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
