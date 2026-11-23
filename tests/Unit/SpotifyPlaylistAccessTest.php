<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Mcp\AgentPrompt;
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

    public function testThumbsUpSavesToLikedSongsOnlyWhenAsked(): void
    {
        self::assertFalse(SpotifyLibrary::shouldSaveLiked('up', null, false));
        self::assertTrue(SpotifyLibrary::shouldSaveLiked('up', null, true));
        self::assertTrue(SpotifyLibrary::shouldSaveLiked('up', true, false));
        self::assertFalse(SpotifyLibrary::shouldSaveLiked('up', false, true));
        self::assertFalse(SpotifyLibrary::shouldSaveLiked('down', true, true));
        self::assertSame(
            'Thumbs up saved.',
            SpotifyLibrary::ratingSummary(['rating' => 'up'])
        );
        self::assertSame(
            'Thumbs up saved, and the track is in Liked Songs.',
            SpotifyLibrary::ratingSummary(['rating' => 'up', 'saved_liked' => true])
        );
        self::assertStringContainsString(
            'not for your playlists',
            SpotifyLibrary::ratingSummary([
                'rating' => 'down',
                'removed' => false,
                'remove_skipped' => 'This playlist is read-only. Only playlists you own can be edited or pushed.',
            ])
        );
    }

    public function testRatingReadsUseSpotifyReadAndThePlaylistPageShowsTheButtons(): void
    {
        self::assertSame('spotify:read', Scope::forPath('/api/v1/spotify/ratings', false));
        self::assertSame('spotify:read', Scope::forPath('/api/v1/spotify/dislikes', false));
        self::assertSame('spotify:read', Scope::forPath('/api/v1/spotify/taste', false));
        self::assertSame('spotify:write', Scope::forPath('/api/v1/spotify/playlists/4/rate', true));
        self::assertSame('spotify:write', Scope::forPath('/api/v1/spotify/likes', true));

        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/settings/spotify.php');
        self::assertStringContainsString('Save thumbs up to Liked Songs', $page);
        self::assertStringContainsString('/settings/spotify/likes', $page);

        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/spotify.js');
        self::assertStringContainsString('data-rate="up"', $script);
        self::assertStringContainsString('data-rate="down"', $script);
        self::assertStringContainsString('save_liked', $script);
        self::assertStringContainsString('music-like-on-up', $script);

        $prompt = AgentPrompt::text();
        self::assertStringContainsString('list_track_ratings', $prompt);
        self::assertStringContainsString('get_disliked_tracks', $prompt);
        self::assertStringContainsString('do_not_use', $prompt);
    }
}
