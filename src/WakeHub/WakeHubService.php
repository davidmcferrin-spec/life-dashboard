<?php

declare(strict_types=1);

namespace Dashboard\WakeHub;

use Dashboard\Audit\AuditLog;
use Dashboard\DB\Database;
use Dashboard\Support\ValidationException;

/**
 * Dashboard-facing Wake Hub calls. Writes are audited with the device and the action.
 */
final class WakeHubService
{
    public function __construct(
        private readonly Database $db,
        private readonly AuditLog $audit,
        private readonly WakeHubSettings $settings,
        private readonly mixed $transport = null,
    ) {}

    public static function fromDatabase(Database $db): self
    {
        return new self($db, new AuditLog($db), new WakeHubSettings($db));
    }

    /**
     * @return array{base_url: string, token_hint: string, configured: bool}
     */
    public function view(int $userId): array
    {
        return $this->settings->view($userId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{base_url: string, token_hint: string, configured: bool}
     */
    public function save(int $userId, array $input): array
    {
        return $this->settings->save($userId, $input);
    }

    /**
     * @return array{ok: true, configured: true, devices: int}
     */
    public function test(int $userId): array
    {
        $devices = $this->devices($userId)['devices'];

        return [
            'ok'         => true,
            'configured' => true,
            'devices'    => count($devices),
        ];
    }

    /**
     * @return array{configured: true, devices: list<array<string, mixed>>}
     */
    public function devices(int $userId): array
    {
        $client = $this->client($userId);
        $out = [];
        foreach ($this->rows($client, '/api/v1/devices') as $row) {
            $item = $this->item('device', $row, true);
            if ($item === null) {
                continue;
            }
            $methods = [];
            foreach (is_array($row['methods'] ?? null) ? $row['methods'] : [] as $method) {
                if (is_array($method) && isset($method['kind'])) {
                    $methods[] = (string) $method['kind'];
                }
            }
            $item['methods'] = $methods;
            $out[] = $item;
        }
        foreach (['amt' => '/api/v1/amt', 'plug' => '/api/v1/plugs', 'jetkvm' => '/api/v1/jetkvm', 'ups' => '/api/v1/ups', 'roku' => '/api/v1/rokus'] as $kind => $path) {
            foreach ($this->rows($client, $path) as $row) {
                $item = $this->item($kind, $row, false);
                if ($item !== null) {
                    $out[] = $item;
                }
            }
        }
        foreach ($this->rows($client, '/api/v1/pdus') as $row) {
            $pdu = $this->item('pdu', $row, false);
            if ($pdu === null) {
                continue;
            }
            $outlets = is_array($row['outlets'] ?? null) ? $row['outlets'] : [];
            $out[] = $pdu;
            foreach ($outlets as $outlet) {
                if (!is_array($outlet)) {
                    continue;
                }
                $number = (int) ($outlet['number'] ?? 0);
                if ($number < 1) {
                    continue;
                }
                $label = trim((string) ($outlet['name'] ?? ''));
                $out[] = [
                    'id'        => 'pdu:' . $pdu['hub_id'] . ':' . $number,
                    'type'      => 'outlet',
                    'hub_id'    => $number,
                    'pdu_id'    => $pdu['hub_id'],
                    'name'      => $pdu['name'] . ' · ' . ($label !== '' ? $label : 'Outlet ' . $number),
                    'host'      => $pdu['host'],
                    'enabled'   => true,
                    'reachable' => null,
                ];
            }
        }

        return ['configured' => true, 'devices' => $out];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $userId, int|string $target): array
    {
        $parsed = WakeHubTarget::parse($target);
        $status = $this->client($userId)->statusOf($parsed);

        return $this->outcome($parsed, 'status', $status);
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function wake(int $userId, int|string $target, string $actor, ?string $tool, array $stamp): array
    {
        $parsed = WakeHubTarget::parse($target);
        try {
            $result = $this->client($userId)->wake($parsed);
        } catch (WakeHubException $e) {
            $this->record($userId, $actor, $tool, $parsed, 'wake', false, $stamp, $e->getMessage());
            throw $e;
        }
        $this->record($userId, $actor, $tool, $parsed, 'wake', true, $stamp, (string) ($result['name'] ?? ''));

        return $this->outcome($parsed, 'wake', $result);
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function power(int $userId, int|string $target, string $action, bool $confirm, string $method, string $actor, ?string $tool, array $stamp): array
    {
        $parsed = WakeHubTarget::parse($target);
        $action = WakeHubActions::normalize($action);
        if (!in_array($action, WakeHubActions::DEVICE, true)) {
            throw ValidationException::withFields(['action' => 'action must be on, off, cycle, reboot, or shutdown.']);
        }
        $method = strtolower(trim($method));
        if ($method === '') {
            $method = 'auto';
        }
        if ($parsed->kind === 'device' && !in_array($method, WakeHubActions::METHODS, true)) {
            throw ValidationException::withFields(['method' => 'method must be auto, wol, pdu, amt, jetkvm, plug, ups, or roku.']);
        }
        if (WakeHubActions::destructive($action) && $confirm !== true) {
            $this->record($userId, $actor, $tool, $parsed, $action, false, $stamp, 'confirmation_required');
            throw new WakeHubException(
                $action . ' can turn a machine off or cycle its power. Set confirm to true to send it.',
                'confirmation_required',
                409,
                ['device_id' => $parsed->ref, 'type' => $parsed->kind, 'action' => $action]
            );
        }
        try {
            $result = $this->client($userId)->power($parsed, $action, $confirm, $method);
        } catch (WakeHubException $e) {
            $this->record($userId, $actor, $tool, $parsed, $action, false, $stamp, $e->getMessage());
            throw $e;
        }
        $this->record($userId, $actor, $tool, $parsed, $action, true, $stamp, $method);

        return $this->outcome($parsed, $action, $result);
    }

    private function client(int $userId): WakeHubClient
    {
        $creds = $this->settings->credentials($userId);
        if ($creds === null) {
            throw new WakeHubException(
                'Wake Hub is not configured. Save the base URL and token under Settings → Wake Hub.',
                'wakehub_unconfigured',
                409
            );
        }

        return new WakeHubClient($creds['base_url'], $creds['token'], $this->transport);
    }

    /**
     * @return array{device_id: string, metrics: list<array<string, mixed>>}
     */
    public function metrics(int $userId, int $deviceId): array
    {
        $parsed = WakeHubTarget::parse($deviceId);

        return [
            'device_id' => $parsed->ref,
            'metrics'   => $this->client($userId)->metrics($parsed->id),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(WakeHubClient $client, string $path): array
    {
        try {
            return $client->list($path);
        } catch (WakeHubException $e) {
            $hub = (int) ($e->extra['hub_status'] ?? 0);
            if ($e->status === 404 || $hub === 404) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function item(string $kind, array $row, bool $enabledFlag): ?array
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            return null;
        }

        return [
            'id'        => $kind . ':' . $id,
            'type'      => $kind,
            'hub_id'    => $id,
            'name'      => (string) ($row['name'] ?? ''),
            'host'      => isset($row['host']) && $row['host'] !== null ? (string) $row['host'] : '',
            'enabled'   => $enabledFlag ? (bool) ($row['enabled'] ?? false) : true,
            'reachable' => null,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function outcome(WakeHubTarget $target, string $action, array $result): array
    {
        return [
            'device_id'  => $target->ref,
            'type'       => $target->kind,
            'hub_id'     => $target->outlet ?? $target->id,
            'action'     => $action,
            'device'     => (string) ($result['name'] ?? $result['target_name'] ?? ''),
            'sent'       => $result['sent'] ?? null,
            'reachable'  => $result['reachable'] ?? null,
            'elapsed_s'  => $result['elapsed_s'] ?? null,
            'result'     => $result,
        ];
    }

    /**
     * @param array<string, mixed> $stamp
     */
    private function record(int $userId, string $actor, ?string $tool, WakeHubTarget $target, string $action, bool $ok, array $stamp, string $detail): void
    {
        $extra = $stamp;
        unset($extra['verb']);
        $extra['entity'] = 'wake_hub';
        $extra['device_id'] = $target->ref;
        $extra['type'] = $target->kind;
        $extra['hub_id'] = $target->outlet ?? $target->id;
        $extra['action'] = $action;
        $extra['ok'] = $ok;
        $note = trim($detail);
        if ($note !== '' && !str_contains($note, 'Bearer ')) {
            $extra['detail'] = mb_substr($this->redactNote($note), 0, 160);
        }
        $this->audit->record($userId, $actor, $tool, 'wakehub.' . $action, 'wakehub:' . $target->ref, $extra);
    }

    private function redactNote(string $note): string
    {
        return (string) (preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $note) ?? $note);
    }
}
