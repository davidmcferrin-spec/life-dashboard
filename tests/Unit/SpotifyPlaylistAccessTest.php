<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Spotify\SpotifyAccount;
use Dashboard\Spotify\SpotifyLibrary;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class SpotifyPlaylistAccessTest extends TestCase
{
    public function testDraftsAndOwnedPlaylistsCanBeEdited(): void
    {
        self::assertTrue(SpotifyLibrary::canEdit(['spotify_id' => null, 'is_owned' => 0]));
        self::assertTrue(SpotifyLibrary::canEdit(['spotify_id' => 'abc', 'is_owned' => 1]));
        self::assertFalse(SpotifyLibrary::canEdit(['spotify_id' => 'abc', 'is_owned' => 0, 'owner_name' => 'Someone Else']));
    }

    public function testReadOnlyMessageNamesTheOwner(): void
    {
        $message = SpotifyLibrary::readOnlyMessage(['owner_name' => 'Someone Else']);
        self::assertStringContainsString('owned by Someone Else', $message);
        self::assertStringContainsString('Only playlists you own', SpotifyLibrary::readOnlyMessage(['owner_name' => '']));
    }

    public function testPromptsAttachOnlyToAiPlaylistsCreatedHere(): void
    {
        SpotifyLibrary::assertPromptTarget(['origin' => 'ai_created', 'spotify_id' => 'abc', 'is_owned' => 1]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Prompts attach only to AI playlists created here.');
        SpotifyLibrary::assertPromptTarget(['origin' => 'synced', 'spotify_id' => 'abc', 'is_owned' => 1]);
    }

    public function testNewPlaylistIsPrivateUnlessPublicIsExplicit(): void
    {
        self::assertFalse(SpotifyLibrary::requestedPublic([]));
        self::assertFalse(SpotifyLibrary::requestedPublic(['public' => null]));
        self::assertFalse(SpotifyLibrary::requestedPublic(['public' => false]));
        self::assertFalse(SpotifyLibrary::requestedPublic(['public' => 'false']));
        self::assertTrue(SpotifyLibrary::requestedPublic(['public' => true]));
        self::assertContains('playlist-modify-private', SpotifyAccount::SCOPES);
    }

    public function testTokenScopeIsTheGrantAndAMissingFieldDoesNotInventOne(): void
    {
        self::assertSame(
            'playlist-modify-public',
            SpotifyAccount::grantedScopes('playlist-modify-public', 'playlist-modify-private playlist-modify-public')
        );
        self::assertSame(
            'playlist-modify-public',
            SpotifyAccount::grantedScopes(null, 'playlist-modify-public')
        );
        self::assertSame('', SpotifyAccount::grantedScopes(null, null));
        self::assertNotContains(
            'playlist-modify-private',
            SpotifyAccount::missingAccountScopes(['playlist-modify-private', 'streaming', 'user-read-email', 'user-read-private'])
        );
        self::assertContains('playlist-modify-private', SpotifyAccount::missingAccountScopes(['playlist-modify-public']));
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/settings/spotify.php');
        self::assertStringContainsString('playlist-modify-private', $page);
        self::assertStringContainsString('>Reconnect<', $page);
        self::assertStringContainsString('/settings/spotify/connect', $page);
    }
}
