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
        $devices = $this->client($userId)->devices();

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
        $devices = $client->devices();
        $reachable = [];
        try {
            foreach ($client->statusAll() as $row) {
                $id = (int) ($row['device_id'] ?? 0);
                if ($id > 0) {
                    $reachable[$id] = $row['reachable'] ?? null;
                }
            }
        } catch (WakeHubException) {
            // The inventory is still useful when a probe does not answer.
            $reachable = [];
        }
        $out = [];
        foreach ($devices as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $methods = [];
            foreach (is_array($row['methods'] ?? null) ? $row['methods'] : [] as $method) {
                if (is_array($method) && isset($method['kind'])) {
                    $methods[] = (string) $method['kind'];
                }
            }
            $out[] = [
                'id'        => $id,
                'name'      => (string) ($row['name'] ?? ''),
                'host'      => isset($row['host']) && $row['host'] !== null ? (string) $row['host'] : '',
                'enabled'   => (bool) ($row['enabled'] ?? false),
                'reachable' => $reachable[$id] ?? null,
                'methods'   => $methods,
            ];
        }

        return ['configured' => true, 'devices' => $out];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $userId, int $deviceId): array
    {
        $this->deviceId($deviceId);

        return [
            'device_id' => $deviceId,
            'status'    => $this->client($userId)->status($deviceId),
        ];
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function wake(int $userId, int $deviceId, string $actor, ?string $tool, array $stamp): array
    {
        $this->deviceId($deviceId);
        try {
            $result = $this->client($userId)->wake($deviceId);
        } catch (WakeHubException $e) {
            $this->record($userId, $actor, $tool, $deviceId, 'wake', false, $stamp, $e->getMessage());
            throw $e;
        }
        $name = (string) ($result['name'] ?? '');
        $this->record($userId, $actor, $tool, $deviceId, 'wake', true, $stamp, $name);

        return [
            'device_id' => $deviceId,
            'action'    => 'wake',
            'device'    => $name,
            'result'    => $result,
        ];
    }

    /**
     * @param array<string, mixed> $stamp
     * @return array<string, mixed>
     */
    public function power(int $userId, int $deviceId, string $action, bool $confirm, string $method, string $actor, ?string $tool, array $stamp): array
    {
        $this->deviceId($deviceId);
        $action = WakeHubActions::normalize($action);
        if (!in_array($action, WakeHubActions::DEVICE, true)) {
            throw ValidationException::withFields(['action' => 'action must be on, off, cycle, reboot, or shutdown.']);
        }
        $method = strtolower(trim($method));
        if ($method === '') {
            $method = 'auto';
        }
        if (!in_array($method, WakeHubActions::METHODS, true)) {
            throw ValidationException::withFields(['method' => 'method must be auto, wol, pdu, amt, jetkvm, plug, or ups.']);
        }
        if (WakeHubActions::destructive($action) && $confirm !== true) {
            $this->record($userId, $actor, $tool, $deviceId, $action, false, $stamp, 'confirmation_required');
            throw new WakeHubException(
                $action . ' can turn a machine off or cycle its power. Set confirm to true to send it.',
                'confirmation_required',
                409,
                ['device_id' => $deviceId, 'action' => $action]
            );
        }
        try {
            $result = $this->client($userId)->power($deviceId, $action, $confirm, $method);
        } catch (WakeHubException $e) {
            $this->record($userId, $actor, $tool, $deviceId, $action, false, $stamp, $e->codeName);
            throw $e;
        }
        $this->record($userId, $actor, $tool, $deviceId, $action, true, $stamp, $method);

        return [
            'device_id' => $deviceId,
            'action'    => $action,
            'device'    => (string) ($result['name'] ?? $result['target_name'] ?? ''),
            'result'    => $result,
        ];
    }

    /**
     * @return array{device_id: int, metrics: list<array<string, mixed>>}
     */
    public function metrics(int $userId, int $deviceId): array
    {
        $this->deviceId($deviceId);

        return [
            'device_id' => $deviceId,
            'metrics'   => $this->client($userId)->metrics($deviceId),
        ];
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

        return new WakeHubClient($creds['base_url'], $creds['token'], null, $this->transport);
    }

    private function deviceId(int $deviceId): void
    {
        if ($deviceId < 1) {
            throw ValidationException::withFields(['device_id' => 'device_id must be a Wake Hub device id.']);
        }
    }

    /**
     * @param array<string, mixed> $stamp
     */
    private function record(int $userId, string $actor, ?string $tool, int $deviceId, string $action, bool $ok, array $stamp, string $detail): void
    {
        $extra = $stamp;
        unset($extra['verb']);
        $extra['entity'] = 'wake_hub';
        $extra['device_id'] = $deviceId;
        $extra['action'] = $action;
        $extra['ok'] = $ok;
        $note = trim($detail);
        if ($note !== '' && !str_contains($note, 'Bearer ')) {
            $extra['detail'] = mb_substr($note, 0, 160);
        }
        $this->audit->record($userId, $actor, $tool, 'wakehub.' . $action, 'wakehub:' . $deviceId, $extra);
    }
}
