<?php

declare(strict_types=1);

namespace Dashboard\System;

/**
 * Application-health rows for the long-running Python services.
 *
 * The probe itself lives in lifedash-healthcheck (systemd state, loopback
 * listen address, MCP /healthz, registry tool count, version/revision) so the
 * settings page and the CLI report the same facts.
 */
final class ServiceHealth
{
    private const SNIPPET = <<<'PY'
import json, sys
from pathlib import Path
root = Path(sys.argv[1])
sys.path.insert(0, str(root / "services"))
from lifedash.healthcheck import check_services
json.dump(check_services(), sys.stdout)
PY;

    /** @var callable(list<string>): string */
    private $run;

    private string $root;

    /**
     * @param (callable(list<string>): string)|null $run
     */
    public function __construct(?callable $run = null, ?string $root = null)
    {
        $this->root = $root ?? ROOT_DIR;
        $this->run  = $run ?? static function (array $command): string {
            return self::execute($command);
        };
    }

    /**
     * @return list<string>
     */
    public static function command(string $root, ?string $python = null): array
    {
        $python ??= self::pythonBinary($root);
        $prefix = is_executable('/usr/bin/timeout') ? ['/usr/bin/timeout', '8'] : [];

        return [...$prefix, $python, '-c', self::SNIPPET, $root];
    }

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        try {
            $raw = ($this->run)(self::command($this->root));
        } catch (\Throwable $e) {
            return self::unavailable($e->getMessage());
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return self::unavailable('lifedash-healthcheck returned invalid JSON');
        }

        $byName = [];

        foreach ($decoded as $item) {
            if (is_array($item) && isset($item['name'])) {
                $byName[(string) $item['name']] = $item;
            }
        }

        $rows = [];

        foreach (['lifedash-mcp', 'lifedash-push'] as $name) {
            $item = $byName[$name] ?? null;

            if (!is_array($item)) {
                $rows[] = [
                    'label'  => $name,
                    'status' => 'warn',
                    'detail' => 'not reported by lifedash-healthcheck',
                ];
                continue;
            }

            $status = (string) ($item['status'] ?? 'warn');

            if (!in_array($status, ['ok', 'warn', 'fail'], true)) {
                $status = 'warn';
            }

            $rows[] = [
                'label'  => $name,
                'status' => $status,
                'detail' => (string) ($item['detail'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param list<string> $command
     */
    private static function execute(array $command): string
    {
        $python = self::interpreterFrom($command);
        if (!self::usableVenvPython($python)) {
            throw new \RuntimeException('Python environment is not available to the web server');
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($command, $descriptors, $pipes, ROOT_DIR);

        if (!is_resource($proc)) {
            throw new \RuntimeException('lifedash-healthcheck could not be started');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code   = proc_close($proc);
        $stdout = is_string($stdout) ? trim($stdout) : '';

        if ($stdout !== '' && str_starts_with($stdout, '[')) {
            return $stdout;
        }

        $err = trim(is_string($stderr) ? $stderr : '');

        throw new \RuntimeException($err !== '' ? $err : 'lifedash-healthcheck exit ' . $code);
    }

    private static function pythonBinary(string $root): string
    {
        foreach (['/srv/lifedash/venv/bin/python', dirname($root) . '/venv/bin/python'] as $bin) {
            if (self::usableVenvPython($bin)) {
                return $bin;
            }
        }

        return '/srv/lifedash/venv/bin/python';
    }

    private static function interpreterFrom(array $command): string
    {
        $dashC = array_search('-c', $command, true);
        if ($dashC === false || $dashC < 1) {
            return '';
        }

        return (string) $command[$dashC - 1];
    }

    public static function usableVenvPython(string $bin): bool
    {
        if (!str_starts_with($bin, '/') || !str_contains($bin, '/venv/bin/python')) {
            return false;
        }

        return is_executable($bin);
    }

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    private static function unavailable(string $detail): array
    {
        $detail = trim((string) preg_replace('/\s+/', ' ', $detail));

        if ($detail === '') {
            $detail = 'lifedash-healthcheck unavailable';
        }

        if (strlen($detail) > 180) {
            $detail = substr($detail, 0, 177) . '...';
        }

        $row = static fn (string $label): array => [
            'label'  => $label,
            'status' => 'warn',
            'detail' => $detail,
        ];

        return [$row('lifedash-mcp'), $row('lifedash-push')];
    }
}
