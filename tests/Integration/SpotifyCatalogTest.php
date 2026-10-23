<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Spotify\SpotifyLibrary;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

final class SpotifyCatalogTest extends PgsqlTestCase
{
    private SpotifyLibrary $library;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h')");
        $this->user = (int) $this->db->lastInsertId();
        $this->library = new SpotifyLibrary($this->db);
    }

    public function testDraftDeleteRemovesTheRowAndAFollowedPlaylistIsRefused(): void
    {
        $created = $this->library->createPlaylist($this->user, ['name' => 'Night'], 'david');
        $id = $created['playlist']['id'];
        $gone = $this->library->deletePlaylist($this->user, $id, 'mcp');
        self::assertTrue($gone['deleted']);
        self::assertFalse($gone['staged']);
        try {
            $this->library->playlist($this->user, $id);
            self::fail('The draft should be gone.');
        } catch (NotFoundException $e) {
            self::assertSame('Playlist not found.', $e->getMessage());
        }

        $followed = (int) $this->db->fetchScalar(
            "INSERT INTO spotify_playlists (user_id, spotify_id, name, is_owned, origin, owner_name)
             VALUES (?, 'remote1', 'Theirs', 0, 'synced', 'Someone Else') RETURNING id",
            [$this->user]
        );
        try {
            $this->library->deletePlaylist($this->user, $followed, 'mcp');
            self::fail('A followed playlist should be refused.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Only playlists you own', $e->getMessage());
        }

        $owned = (int) $this->db->fetchScalar(
            "INSERT INTO spotify_playlists (user_id, spotify_id, name, is_owned, origin)
             VALUES (?, 'remote2', 'Mine', 1, 'synced') RETURNING id",
            [$this->user]
        );
        $staged = $this->library->deletePlaylist($this->user, $owned, 'ai');
        self::assertTrue($staged['staged']);
        self::assertFalse($staged['deleted']);
        $ops = array_column($this->library->changes($this->user, $owned), 'op');
        self::assertSame(['delete'], $ops);
        self::assertSame('Mine', $this->library->playlist($this->user, $owned)['playlist']['name']);
    }

    public function testSavedTracksRoundTripInTheLocalList(): void
    {
        $this->library->rememberSaved($this->user, [[
            'id' => 'abc',
            'name' => 'North Line',
            'artists' => ['Ada'],
            'album' => 'Night',
            'duration_ms' => 180000,
            'explicit' => false,
        ]]);
        $saved = $this->library->saved($this->user, 10);
        self::assertSame('North Line', $saved[0]['track']['name']);
        self::assertSame('abc', $saved[0]['track']['id']);

        $this->library->forgetSaved($this->user, ['abc']);
        self::assertSame([], $this->library->saved($this->user, 10));
    }
}
