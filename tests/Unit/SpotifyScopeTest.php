<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class SpotifyScopeTest extends TestCase
{
    public function testPlaybackIsASpotifyVerbOnly(): void
    {
        self::assertSame(['spotify:playback'], Scope::normalize(['spotify:playback']));
        self::assertSame(['*'], Scope::normalize(['*', 'spotify:playback']));
        try {
            Scope::normalize(['metrics:playback']);
            self::fail('playback is not a verb for other areas.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('metrics:playback', $e->getMessage());
        }
    }

    public function testPlaybackPathsDoNotBecomeWrites(): void
    {
        self::assertSame('spotify:playback', Scope::forPath('/api/v1/spotify/playback/play', true));
        self::assertSame('spotify:playback', Scope::forPath('/api/v1/spotify/playback/token', false));
        self::assertSame('spotify:read', Scope::forPath('/api/v1/spotify/devices', false));
        self::assertSame('spotify:read', Scope::forPath('/api/v1/spotify/queue', false));
        self::assertSame('spotify:write', Scope::forPath('/api/v1/spotify/sync', true));
        self::assertSame('spotify:read', Scope::forPath('/api/v1/spotify/playlists', false));
    }

    public function testWriteDoesNotImplyPlaybackAndPlaybackImpliesRead(): void
    {
        self::assertFalse(Scope::allows(['spotify:write'], 'spotify:playback'));
        self::assertFalse(Scope::allows(['spotify:read'], 'spotify:playback'));
        self::assertTrue(Scope::allows(['spotify:playback'], 'spotify:read'));
        self::assertFalse(Scope::allows(['spotify:playback'], 'spotify:write'));
        self::assertTrue(Scope::allows(['*'], 'spotify:playback'));
    }

    public function testBlanketReadDoesNotGrantPlaybackButAFullWriteTokenDoes(): void
    {
        $read = [];
        $write = [];
        foreach (Scope::CORE as $area) {
            $read[] = $area . ':read';
            $write[] = $area . ':write';
        }
        self::assertFalse(Scope::allows($read, 'spotify:playback'));
        self::assertTrue(Scope::allows($read, 'spotify:read'));
        self::assertTrue(Scope::allows($write, 'spotify:playback'));
    }

    public function testSpotifyReadCanSeeSpotifyAuditRows(): void
    {
        self::assertSame(['spotify'], Scope::auditEntityAllow(['spotify:read'], 'spotify'));
        self::assertSame(['spotify'], Scope::auditEntityAllow(['spotify:playback'], 'spotify'));
        try {
            Scope::auditEntityAllow(['todos:read'], 'spotify');
            self::fail('A todos token cannot read Spotify audit rows.');
        } catch (ForbiddenException) {
            self::assertTrue(true);
        }
    }
}
