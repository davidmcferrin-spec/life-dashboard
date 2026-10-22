<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Settings\ServiceControl;
use Dashboard\Spotify\SpotifyException;
use Dashboard\Spotify\SyncBridge;
use PHPUnit\Framework\TestCase;

final class SyncBridgeTest extends TestCase
{
    public function testUsableInterpreterIgnoresSystemPython(): void
    {
        $dir = sys_get_temp_dir() . '/ld-sync-' . bin2hex(random_bytes(4));
        $bin = $dir . '/venv/bin/python';
        mkdir(dirname($bin), 0700, true);
        file_put_contents($bin, "#!/bin/sh\nexit 0\n");
        chmod($bin, 0755);

        try {
            self::assertSame($bin, SyncBridge::usableInterpreter([$bin, 'python3', '/usr/bin/python3']));
            self::assertNull(SyncBridge::usableInterpreter(['python3', '/usr/bin/python3', '/tmp/missing/venv/bin/python']));
            self::assertNotContains('python3', SyncBridge::candidatePaths());
        } finally {
            @unlink($bin);
        }
    }

    public function testInjectedInterpreterRuns(): void
    {
        $dir = sys_get_temp_dir() . '/ld-sync-run-' . bin2hex(random_bytes(4));
        $bin = $dir . '/venv/bin/python';
        mkdir(dirname($bin), 0700, true);
        file_put_contents($bin, "#!/bin/sh\nprintf '%s\n' '{\"ok\":true,\"refreshed\":2}'\n");
        chmod($bin, 0755);

        try {
            $result = (new SyncBridge($bin))->pull(3);
            self::assertTrue($result['ok']);
            self::assertSame(2, $result['refreshed']);
        } finally {
            @unlink($bin);
        }
    }

    public function testReadySpoolQueuesInsteadOfRunningPython(): void
    {
        $spool = sys_get_temp_dir() . '/ld-spool-' . bin2hex(random_bytes(4));
        mkdir($spool . '/incoming', 0777, true);

        try {
            $result = (new SyncBridge(null, new ServiceControl($spool)))->pull(7, 9);
            self::assertTrue($result['ok']);
            self::assertTrue($result['queued']);
            $files = glob($spool . '/incoming/*.json');
            self::assertIsArray($files);
            self::assertCount(1, $files);
            $body = json_decode((string) file_get_contents($files[0]), true);
            self::assertSame('spotify-pull', $body['action']);
            self::assertSame('7', $body['user_id']);
            self::assertSame('9', $body['playlist_id']);
        } finally {
            array_map('unlink', glob($spool . '/incoming/*') ?: []);
            @rmdir($spool . '/incoming');
            @rmdir($spool);
        }
    }

    public function testMissingInterpreterThrowsAndConnectStillDefers(): void
    {
        if (SyncBridge::usableInterpreter(SyncBridge::candidatePaths()) !== null) {
            self::markTestSkipped('A venv interpreter is executable in this environment.');
        }

        $log = sys_get_temp_dir() . '/ld-spotify-log-' . bin2hex(random_bytes(4));
        $previous = ini_set('error_log', $log);
        $spool = sys_get_temp_dir() . '/ld-no-spool-' . bin2hex(random_bytes(4));
        $bridge = new SyncBridge(null, new ServiceControl($spool));

        try {
            $bridge->pullDeferred(4);
            self::assertStringContainsString(SyncBridge::PYTHON_UNAVAILABLE, (string) file_get_contents($log));

            try {
                $bridge->pull(4);
                self::fail('A missing interpreter must be reported.');
            } catch (SpotifyException $e) {
                self::assertSame(SyncBridge::PYTHON_UNAVAILABLE, $e->getMessage());
            }
        } finally {
            if (is_string($previous)) {
                ini_set('error_log', $previous);
            }
            @unlink($log);
        }
    }
}
