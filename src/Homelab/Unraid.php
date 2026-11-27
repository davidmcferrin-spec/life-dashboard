<?php

declare(strict_types=1);

namespace Dashboard\Homelab;

use Dashboard\Support\ValidationException;

/**
 * Unraid 7 GraphQL API. The key is the x-api-key header, sent as X-Api-Key.
 * Reboot and power-off are not operations in the published schema, so they are not offered.
 */
final class Unraid
{
    public function __construct(private readonly Http $http) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $data = $this->graphql(<<<'GQL'
            query {
              online
              info {
                versions { core { unraid api } }
                os { hostname uptime }
                cpu { brand cores threads }
              }
              metrics {
                cpu { percentTotal }
                memory { total used available percentTotal }
              }
              array { state }
            }
            GQL);

        $info = is_array($data['info'] ?? null) ? $data['info'] : [];
        $versions = is_array($info['versions'] ?? null) ? $info['versions'] : [];
        $core = is_array($versions['core'] ?? null) ? $versions['core'] : [];
        $os = is_array($info['os'] ?? null) ? $info['os'] : [];
        $cpu = is_array($info['cpu'] ?? null) ? $info['cpu'] : [];
        $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];
        $metricCpu = is_array($metrics['cpu'] ?? null) ? $metrics['cpu'] : [];
        $memory = is_array($metrics['memory'] ?? null) ? $metrics['memory'] : [];
        $array = is_array($data['array'] ?? null) ? $data['array'] : [];
        $boot = (string) ($os['uptime'] ?? '');

        return [
            'version' => (string) ($core['unraid'] ?? ''),
            'api' => (string) ($core['api'] ?? ''),
            'hostname' => (string) ($os['hostname'] ?? ''),
            'boot_time' => $boot,
            'uptime_seconds' => self::uptime($boot),
            'cpu' => (string) ($cpu['brand'] ?? ''),
            'cores' => Servarr::intish($cpu['cores'] ?? 0),
            'threads' => Servarr::intish($cpu['threads'] ?? 0),
            'cpu_percent' => is_numeric($metricCpu['percentTotal'] ?? null) ? (float) $metricCpu['percentTotal'] : null,
            'memory_total' => Servarr::intish($memory['total'] ?? 0),
            'memory_used' => Servarr::intish($memory['used'] ?? 0),
            'memory_available' => Servarr::intish($memory['available'] ?? 0),
            'memory_percent' => is_numeric($memory['percentTotal'] ?? null) ? (float) $memory['percentTotal'] : null,
            'array' => (string) ($array['state'] ?? ''),
            'online' => (bool) ($data['online'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function disks(): array
    {
        $data = $this->graphql(<<<'GQL'
            query {
              array {
                state
                disks { id name device size status temp fsType numErrors isSpinning }
                parities { id name device size status temp numErrors }
                caches { id name device size status temp fsType }
                parityCheckStatus { status progress errors speed }
              }
            }
            GQL);
        $array = is_array($data['array'] ?? null) ? $data['array'] : [];
        $parity = is_array($array['parityCheckStatus'] ?? null) ? $array['parityCheckStatus'] : [];

        return [
            'state' => (string) ($array['state'] ?? ''),
            'parity' => [
                'status' => (string) ($parity['status'] ?? ''),
                'progress' => Servarr::intish($parity['progress'] ?? 0),
                'errors' => Servarr::intish($parity['errors'] ?? 0),
                'speed' => (string) ($parity['speed'] ?? ''),
            ],
            'disks' => $this->diskList($array['disks'] ?? null, 'data'),
            'parities' => $this->diskList($array['parities'] ?? null, 'parity'),
            'caches' => $this->diskList($array['caches'] ?? null, 'cache'),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function containers(): array
    {
        $data = $this->graphql(<<<'GQL'
            query {
              docker { containers { id names image state status } }
            }
            GQL);
        $docker = is_array($data['docker'] ?? null) ? $data['docker'] : [];
        $items = [];
        foreach (is_array($docker['containers'] ?? null) ? $docker['containers'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $names = [];
            foreach (is_array($row['names'] ?? null) ? $row['names'] : [] as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = ltrim($name, '/');
                }
            }
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => $names[0] ?? '',
                'image' => (string) ($row['image'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function vms(): array
    {
        $data = $this->graphql(<<<'GQL'
            query {
              vms { domains { id name state } domain { id name state } }
            }
            GQL);
        $vms = is_array($data['vms'] ?? null) ? $data['vms'] : [];
        $rows = $vms['domains'] ?? null;
        if (!is_array($rows) || $rows === []) {
            $rows = $vms['domain'] ?? [];
        }
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
            ];
        }

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function container(string $action, string $id): array
    {
        $action = strtolower($action);
        if (!in_array($action, ['start', 'stop', 'restart'], true)) {
            throw ValidationException::withFields(['action' => 'action must be start, stop, or restart.']);
        }
        $id = self::target($id);
        $data = $this->graphql(
            'mutation ($id: PrefixedID!) { docker { ' . $action . '(id: $id) { id state status } } }',
            ['id' => $id]
        );
        $docker = is_array($data['docker'] ?? null) ? $data['docker'] : [];
        $row = is_array($docker[$action] ?? null) ? $docker[$action] : [];

        return [
            'id' => (string) ($row['id'] ?? $id),
            'action' => $action,
            'state' => (string) ($row['state'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function vm(string $action, string $id): array
    {
        $action = strtolower($action);
        if ($action !== 'start' && $action !== 'stop') {
            throw ValidationException::withFields(['action' => 'action must be start or stop.']);
        }
        $id = self::target($id);
        $data = $this->graphql(
            'mutation ($id: PrefixedID!) { vm { ' . $action . '(id: $id) } }',
            ['id' => $id]
        );
        $vm = is_array($data['vm'] ?? null) ? $data['vm'] : [];

        return [
            'id' => $id,
            'action' => $action,
            'ok' => (bool) ($vm[$action] ?? false),
        ];
    }

    /**
     * @return array{state: string}
     */
    public function arrayState(string $state): array
    {
        $state = strtoupper(trim($state));
        if ($state !== 'START' && $state !== 'STOP') {
            throw ValidationException::withFields(['state' => 'state must be START or STOP.']);
        }
        $data = $this->graphql(
            'mutation ($state: ArrayStateInputState!) { array { setState(input: { desiredState: $state }) { state } } }',
            ['state' => $state]
        );
        $array = is_array($data['array'] ?? null) ? $data['array'] : [];
        $set = is_array($array['setState'] ?? null) ? $array['setState'] : [];

        return ['state' => (string) ($set['state'] ?? '')];
    }

    public static function target(string $raw): string
    {
        $id = trim($raw);
        if ($id === '' || strlen($id) > 200 || preg_match('/^[A-Za-z0-9:._-]+$/', $id) !== 1) {
            throw ValidationException::withFields(['id' => 'That id is not valid.']);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables = []): array
    {
        $body = ['query' => $query];
        if ($variables !== []) {
            $body['variables'] = $variables;
        }
        $payload = $this->http->json('POST', '/graphql', $body, [], 20);
        if (array_is_list($payload)) {
            throw new HomelabException('Unraid returned an unexpected response.', 'homelab_invalid', 502);
        }
        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $first = $errors[0] ?? null;
            $message = is_array($first) ? trim((string) ($first['message'] ?? '')) : '';
            throw new HomelabException(
                $message !== '' ? $message : 'Unraid rejected the request.',
                'homelab_error',
                502
            );
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw new HomelabException('Unraid returned an unexpected response.', 'homelab_invalid', 502);
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function diskList(mixed $rows, string $role): array
    {
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $temp = $row['temp'] ?? null;
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'device' => (string) ($row['device'] ?? ''),
                'role' => $role,
                'size_kb' => Servarr::intish($row['size'] ?? 0),
                'status' => (string) ($row['status'] ?? ''),
                'temp_c' => is_numeric($temp) ? (int) $temp : null,
                'fs' => (string) ($row['fsType'] ?? ''),
                'errors' => Servarr::intish($row['numErrors'] ?? 0),
                'spinning' => (bool) ($row['isSpinning'] ?? false),
            ];
        }

        return $items;
    }

    private static function uptime(string $boot): ?int
    {
        if ($boot === '') {
            return null;
        }
        $stamp = strtotime($boot);
        if ($stamp === false) {
            return null;
        }
        $seconds = time() - $stamp;

        return $seconds >= 0 ? $seconds : null;
    }
}
