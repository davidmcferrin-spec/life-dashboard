<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

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
}
