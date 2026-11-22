<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Spotify\SpotifyLibrary;
use Dashboard\Support\ValidationException;

final class SpotifyPlaylistPrivacyTest extends PgsqlTestCase
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

    public function testCreateIsPrivateUnlessPublicIsTrueAndDedupeUpdatesTheSameRow(): void
    {
        $created = $this->library->createPlaylist($this->user, [
            'name' => 'Vintage Christmas Classics',
            'source' => 'music-director',
            'dedupe_key' => 'md:vintage-christmas',
        ], 'mcp');
        $id = $created['playlist']['id'];
        self::assertFalse($created['playlist']['public']);
        self::assertSame('music-director', $created['playlist']['source']);
        self::assertSame('md:vintage-christmas', $created['playlist']['dedupe_key']);
        $change = $this->library->changes($this->user, $id)[0];
        self::assertSame('create', $change['op']);
        self::assertFalse($change['payload']['public']);

        $again = $this->library->createPlaylist($this->user, [
            'name' => 'Vintage Christmas Classics',
            'description' => 'Quiet ones',
            'public' => false,
            'source' => 'music-director',
            'dedupe_key' => 'md:vintage-christmas',
        ], 'mcp');
        self::assertSame($id, $again['playlist']['id']);
        self::assertSame('Quiet ones', $again['playlist']['description']);
        self::assertFalse($again['playlist']['public']);
        $open = $this->library->changes($this->user, $id);
        self::assertCount(1, $open);
        self::assertSame('create', $open[0]['op']);
        self::assertSame('Quiet ones', $open[0]['payload']['description']);
        self::assertFalse($open[0]['payload']['public']);
        $rows = $this->db->fetchAll('SELECT id FROM spotify_playlists WHERE user_id = ?', [$this->user]);
        self::assertCount(1, $rows);
        $listed = $this->library->playlists($this->user);
        self::assertSame('md:vintage-christmas', $listed[0]['dedupe_key']);
        self::assertSame('music-director', $listed[0]['source']);

        $shared = $this->library->createPlaylist($this->user, [
            'name' => 'Share',
            'public' => true,
        ], 'david');
        self::assertTrue($shared['playlist']['public']);
    }

    public function testFollowedPlaylistPrivacyIsRefused(): void
    {
        $followed = (int) $this->db->fetchScalar(
            "INSERT INTO spotify_playlists (user_id, spotify_id, name, is_public, is_owned, origin, owner_name)
             VALUES (?, 'theirs', 'Subscribed', 1, 0, 'synced', 'Someone Else') RETURNING id",
            [$this->user]
        );
        try {
            $this->library->setPrivacy($this->user, $followed, false, 'mcp');
            self::fail('A followed playlist must not be edited.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Only playlists you own', $e->getMessage());
        }
    }

    public function testPlanSkipsADislikedTitleStoredOnTheTrack(): void
    {
        $this->db->execute(
            "INSERT INTO spotify_tracks (user_id, spotify_id, name, artists)
             VALUES (?, 'shoes01', 'The Christmas Shoes', ?::jsonb)",
            [$this->user, json_encode([['name' => 'Newsong']], JSON_THROW_ON_ERROR)]
        );
        $this->library->mixtape()->saveMany($this->user, [
            ['uri' => 'spotify:track:shoes01', 'energy' => 3, 'bpm' => 90, 'camelot' => '8A'],
            ['uri' => 'spotify:track:keep01', 'energy' => 6, 'bpm' => 100, 'camelot' => '8A'],
        ]);
        $saved = $this->library->saveDislikes($this->user, ['titles' => ['Christmas Shoes']], false);
        self::assertSame(['Christmas Shoes'], $saved['profile']['dislikes']['titles']);
        $plan = $this->library->mixtape()->plan($this->user, [
            'spotify:track:shoes01',
            'spotify:track:keep01',
        ], null, null);
        self::assertSame(['spotify:track:keep01'], array_column($plan['order'], 'uri'));
        self::assertSame(['spotify:track:shoes01'], array_column($plan['blocked'], 'uri'));
        $read = $this->library->taste($this->user);
        self::assertSame(['Christmas Shoes'], $read['profile']['dislikes']['titles']);
    }
}
