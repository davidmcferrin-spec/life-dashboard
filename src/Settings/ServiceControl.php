<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\Support\ValidationException;

/**
 * Queue a root action. PHP writes a request file. lifedash-service.path runs it.
 * This process never calls sudo.
 */
final class ServiceControl
{
    public const SPOOL_DIR = '/var/lib/lifedash/service';

    /** @var list<string> */
    public const ACTIONS = [
        'restart-mcp',
        'tunnel-start',
        'tunnel-stop',
        'tunnel-restart',
        'tunnel-status',
        'tunnel-clear',
    ];

    public function __construct(private readonly ?string $spoolDir = null) {}

    public function ready(): bool
    {
        $dir = $this->incoming();
        return is_dir($dir) && is_writable($dir);
    }

    /**
     * @param array<string, string> $fields
     * @return array{id: string, action: string, queued: bool}
     */
    public function request(string $action, array $fields = []): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown service action.');
        }
        if (!$this->ready()) {
            throw new ValidationException('Service control is not installed. Re-run setup on the server.');
        }
        $id = bin2hex(random_bytes(16));
        $payload = [
            'id'           => $id,
            'action'       => $action,
            'requested_at' => gmdate('c'),
        ];
        $token = $fields['token'] ?? '';
        if ($token !== '') {
            $payload['token'] = $token;
        }
        $dir = $this->incoming();
        $tmp = $dir . '/.' . $id . '.tmp';
        $dest = $dir . '/' . $id . '.json';
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new ValidationException('Could not write the service request.');
        }
        chmod($tmp, 0640);
        if (!rename($tmp, $dest)) {
            @unlink($tmp);
            throw new ValidationException('Could not queue the service request.');
        }
        return ['id' => $id, 'action' => $action, 'queued' => true];
    }

    /**
     * @return array{connected: bool, detail: string, checked_at: ?string}
     */
    public function status(): array
    {
        $path = $this->root() . '/state/status.json';
        if (!is_file($path)) {
            return ['connected' => false, 'detail' => 'Not checked yet', 'checked_at' => null];
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['connected' => false, 'detail' => 'Not checked yet', 'checked_at' => null];
        }
        $detail = $data['detail'] ?? '';
        $at = $data['checked_at'] ?? null;
        return [
            'connected'  => !empty($data['connected']),
            'detail'     => is_string($detail) ? $detail : '',
            'checked_at' => is_string($at) && $at !== '' ? $at : null,
        ];
    }

    public function stale(int $seconds = 3): bool
    {
        $status = $this->status();
        if ($status['checked_at'] === null) {
            return true;
        }
        $at = strtotime($status['checked_at']);
        if ($at === false) {
            return true;
        }
        return (time() - $at) >= $seconds;
    }

    private function root(): string
    {
        if ($this->spoolDir !== null) {
            return rtrim($this->spoolDir, '/');
        }
        return self::SPOOL_DIR;
    }

    private function incoming(): string
    {
        return $this->root() . '/incoming';
    }
}
