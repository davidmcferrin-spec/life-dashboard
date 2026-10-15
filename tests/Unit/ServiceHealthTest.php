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

    public function testCommandTargetsTheAppCheckout(): void
    {
        $command = ServiceHealth::command('/workspace');
        self::assertContains('/workspace', $command);
        self::assertContains('python3', $command);
        self::assertTrue((bool) preg_match('/check_services/', implode("\n", $command)));
    }
}
