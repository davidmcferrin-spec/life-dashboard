<?php

declare(strict_types=1);

namespace Dashboard\Home;

use Dashboard\DB\Database;
use Dashboard\Support\JsonHttp;

/**
 * A short, fact-backed read of Home Assistant.
 * When the URL or token is missing, the result says so and invents no states.
 */
final class HomeStatus
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $settings = new HomeSettings($this->db);
        $view = $settings->view();
        $connection = $settings->connection();
        if ($connection === null) {
            return [
                'configured' => false,
                'reachable'  => false,
                'detail'     => 'Home Assistant is not connected. Add the URL and token in Settings.',
                'people'     => [],
            ];
        }
        $headers = ['Authorization' => 'Bearer ' . $connection['token']];
        $root = JsonHttp::get($connection['base'] . '/api/', $headers, 5, $connection['token']);
        if ($root['status'] < 200 || $root['status'] >= 300) {
            return [
                'configured' => true,
                'base_url'   => $view['base_url'],
                'reachable'  => false,
                'detail'     => $root['error'] !== '' ? $root['error'] : 'Home Assistant did not answer.',
                'people'     => [],
            ];
        }
        $states = JsonHttp::get($connection['base'] . '/api/states', $headers, 5, $connection['token']);
        if ($states['json'] === null || !array_is_list($states['json'])) {
            return [
                'configured' => true,
                'base_url'   => $view['base_url'],
                'reachable'  => true,
                'detail'     => 'Home Assistant answered. The state list was not readable.',
                'people'     => [],
            ];
        }
        $people = [];
        foreach ($states['json'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['entity_id'] ?? '');
            if (!str_starts_with($id, 'person.')) {
                continue;
            }
            $name = '';
            $attributes = $row['attributes'] ?? null;
            if (is_array($attributes) && isset($attributes['friendly_name']) && is_string($attributes['friendly_name'])) {
                $name = mb_substr(trim($attributes['friendly_name']), 0, 80);
            }
            $people[] = [
                'entity_id' => mb_substr($id, 0, 80),
                'name'      => $name,
                'state'     => mb_substr(trim((string) ($row['state'] ?? '')), 0, 40),
            ];
            if (count($people) >= 5) {
                break;
            }
        }

        return [
            'configured'   => true,
            'base_url'     => $view['base_url'],
            'reachable'    => true,
            'entity_count' => count($states['json']),
            'people'       => $people,
            'detail'       => 'Home Assistant answered.',
        ];
    }
}
