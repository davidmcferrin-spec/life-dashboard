<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\System\ServiceHealth;
use PHPUnit\Framework\TestCase;

final class ServiceHealthTest extends TestCase
{
    public function testChecksMapHealthcheckJsonOntoPageRows(): void
    {
        $health = new ServiceHealth(static function (array $command): string {
            self::assertContains('/workspace', $command);

            return json_encode([
                [
                    'name'   => 'lifedash-mcp',
                    'status' => 'warn',
                    'detail' => 'systemd active; healthz 200; listen 0.0.0.0:8765; 65 tools; 0.1.0 abc1234',
                ],
                [
                    'name'   => 'lifedash-push',
                    'status' => 'ok',
                    'detail' => 'systemd active; listen 127.0.0.1:8766',
                ],
            ], JSON_THROW_ON_ERROR);
        }, '/workspace');

        self::assertSame([
            [
                'label'  => 'lifedash-mcp',
                'status' => 'warn',
                'detail' => 'systemd active; healthz 200; listen 0.0.0.0:8765; 65 tools; 0.1.0 abc1234',
            ],
            [
                'label'  => 'lifedash-push',
                'status' => 'ok',
                'detail' => 'systemd active; listen 127.0.0.1:8766',
            ],
        ], $health->checks());
    }

    public function testMissingServiceAndBadJsonWarn(): void
    {
        $missing = new ServiceHealth(static fn (array $command): string => '[{"name":"lifedash-mcp","status":"ok","detail":"up"}]');
        $rows    = $missing->checks();
        self::assertSame('lifedash-mcp', $rows[0]['label']);
        self::assertSame('ok', $rows[0]['status']);
        self::assertSame('warn', $rows[1]['status']);
        self::assertSame('lifedash-push', $rows[1]['label']);

        $bad = new ServiceHealth(static fn (array $command): string => 'not-json');
        foreach ($bad->checks() as $row) {
            self::assertSame('warn', $row['status']);
            self::assertStringContainsString('invalid JSON', $row['detail']);
        }
    }

    public function testRunnerFailureWarnsBothServices(): void
    {
        $health = new ServiceHealth(static function (array $command): string {
            throw new \RuntimeException('python3: command not found');
        });

        $rows = $health->checks();
        self::assertSame(['lifedash-mcp', 'lifedash-push'], array_column($rows, 'label'));
        self::assertSame(['warn', 'warn'], array_column($rows, 'status'));
        self::assertSame('python3: command not found', $rows[0]['detail']);
    }

    public function testCommandUsesAVenvInterpreter(): void
    {
        $dir = sys_get_temp_dir() . '/ld-health-' . bin2hex(random_bytes(4));
        $bin = $dir . '/venv/bin/python';
        mkdir(dirname($bin), 0700, true);
        file_put_contents($bin, "#!/bin/sh\nexit 0\n");
        chmod($bin, 0755);

        try {
            self::assertTrue(ServiceHealth::usableVenvPython($bin));
            self::assertFalse(ServiceHealth::usableVenvPython('/usr/bin/python3'));
            $command = ServiceHealth::command('/workspace', $bin);
            self::assertContains($bin, $command);
            self::assertNotContains('python3', $command);
            self::assertContains('/workspace', $command);
            self::assertMatchesRegularExpression('/check_services/', implode("\n", $command));
        } finally {
            @unlink($bin);
        }
    }

    public function testCommandDoesNotFallBackToSystemPython(): void
    {
        $command = ServiceHealth::command('/workspace');
        self::assertNotContains('python3', $command);
        $named = false;
        foreach ($command as $arg) {
            if (is_string($arg) && str_contains($arg, '/venv/bin/python')) {
                $named = true;
            }
        }
        self::assertTrue($named);

        if (is_executable('/srv/lifedash/venv/bin/python') || is_executable('/venv/bin/python')) {
            return;
        }
        $rows = (new ServiceHealth())->checks();
        self::assertSame('Python environment is not available to the web server', $rows[0]['detail']);
        self::assertSame('warn', $rows[0]['status']);
    }
}
