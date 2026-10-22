<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Spotify\SpotifyAccount;
use Dashboard\Spotify\SpotifyPlayback;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class SpotifyPlayerTest extends TestCase
{
    public function testPlaybackScopesAreRequestedOnConnect(): void
    {
        foreach (SpotifyAccount::PLAYBACK_SCOPES as $scope) {
            self::assertContains($scope, SpotifyAccount::SCOPES);
        }
    }

    public function testMissingPlaybackScopes(): void
    {
        self::assertSame([], SpotifyAccount::missingPlaybackScopes(SpotifyAccount::SCOPES));
        self::assertSame(
            ['streaming'],
            SpotifyAccount::missingPlaybackScopes(array_values(array_diff(SpotifyAccount::SCOPES, ['streaming'])))
        );
        self::assertSame(SpotifyAccount::PLAYBACK_SCOPES, SpotifyAccount::missingPlaybackScopes([]));
        self::assertSame(
            [],
            SpotifyAccount::missingPlaybackScopes(['streaming', '', 'user-read-email', 'user-read-private'])
        );
    }

    public function testTransferOmitsPlayUnlessTheCallerSetsIt(): void
    {
        self::assertSame(
            ['device_ids' => ['desk-1']],
            SpotifyPlayback::transferPayload(['device_id' => 'desk-1'])
        );
        self::assertSame(
            ['device_ids' => ['desk-1'], 'play' => true],
            SpotifyPlayback::transferPayload(['device_id' => 'desk-1', 'play' => true])
        );
        self::assertSame(
            ['device_ids' => ['desk-1'], 'play' => false],
            SpotifyPlayback::transferPayload(['device_id' => 'desk-1', 'play' => false])
        );
        self::assertSame(
            ['device_ids' => ['desk-1'], 'play' => false],
            SpotifyPlayback::transferPayload(['device_id' => 'desk-1', 'play' => 0])
        );
    }

    public function testTransferRequiresADevice(): void
    {
        $this->expectException(ValidationException::class);
        SpotifyPlayback::transferPayload([]);
    }
}
