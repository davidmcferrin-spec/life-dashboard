<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Spotify\SpotifyLibrary;
use Dashboard\Support\ValidationException;

final class SpotifyTrackRatingTest extends PgsqlTestCase
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

    public function testThumbsDownOnAnOwnedPlaylistStagesRemovalAndADoNotUseRow(): void
    {
        $playlist = $this->playlist('owned1', 'Mine', 1, 'created');
        $this->trackOn($playlist, 'abc', 'North Line');

        $rated = $this->library->rateTrack($this->user, $playlist, 'spotify:track:abc', 'down', 'david', [
            'name' => 'North Line',
            'artists' => ['Ada'],
        ]);

        self::assertSame('user', $rated['source']);
        self::assertTrue($rated['disliked']);
        self::assertTrue($rated['removed']);
        self::assertTrue($rated['should_push']);
        self::assertNull($rated['remove_skipped']);

        $row = $this->db->fetchOne(
            'SELECT track_uri, playlist_id, rating, source FROM spotify_track_ratings WHERE user_id = ?',
            [$this->user]
        );
        self::assertSame('spotify:track:abc', $row['track_uri']);
        self::assertSame($playlist, (int) $row['playlist_id']);
        self::assertSame('down', $row['rating']);
        self::assertSame('user', $row['source']);
        self::assertNotSame('', (string) $row['source']);

        $ops = array_column($this->library->changes($this->user, $playlist), 'op');
        self::assertSame(['remove'], $ops);
        $detail = $this->library->playlist($this->user, $playlist);
        self::assertSame([], array_column($detail['preview'], 'uri'));

        $disliked = $this->library->dislikedTracks($this->user);
        self::assertSame('spotify:track:abc', $disliked[0]['track_uri']);
        self::assertSame('North Line', $disliked[0]['name']);
        self::assertSame(['Ada'], $disliked[0]['artists']);
        self::assertSame('user', $disliked[0]['source']);

        $profile = $this->library->taste($this->user)['profile'];
        self::assertContains('spotify:track:abc', $profile['dislikes']['tracks']);
        self::assertSame('spotify:track:abc', $profile['do_not_use'][0]['track_uri']);

        $plan = $this->library->mixtape()->plan($this->user, ['spotify:track:abc', 'spotify:track:keep'], null, null, [
            'tracks' => [
                ['uri' => 'spotify:track:abc', 'name' => 'North Line'],
                ['uri' => 'spotify:track:keep', 'name' => 'Keep'],
            ],
        ]);
        self::assertContains('spotify:track:abc', array_column($plan['blocked'], 'uri'));
        self::assertNotContains('spotify:track:abc', array_column($plan['order'] ?? [], 'uri'));

        $shown = $this->library->taste($this->user);
        $this->library->saveTaste($this->user, $shown['profile']);
        $stored = json_decode((string) $this->db->fetchScalar(
            'SELECT body FROM spotify_taste_profile WHERE user_id = ?',
            [$this->user]
        ), true);
        self::assertArrayNotHasKey('do_not_use', $stored);
        self::assertNotContains('spotify:track:abc', $stored['dislikes']['tracks']);
        self::assertContains('spotify:track:abc', $this->library->taste($this->user)['profile']['dislikes']['tracks']);
    }

    public function testThumbsDownOnAFollowedPlaylistDoesNotStageOrPush(): void
    {
        $playlist = $this->playlist('theirs', 'Theirs', 0, 'synced', 'Someone Else');
        $this->trackOn($playlist, 'zzz', 'Shared Song');

        $rated = $this->library->rateTrack($this->user, $playlist, 'spotify:track:zzz', 'down', 'david');

        self::assertSame('user', $rated['source']);
        self::assertTrue($rated['disliked']);
        self::assertFalse($rated['removed']);
        self::assertFalse($rated['should_push']);
        self::assertStringContainsString('Only playlists you own', (string) $rated['remove_skipped']);
        self::assertSame([], $this->library->changes($this->user, $playlist));
        self::assertSame(['spotify:track:zzz'], array_column($this->library->playlist($this->user, $playlist)['preview'], 'uri'));
        self::assertSame('spotify:track:zzz', $this->library->dislikedTracks($this->user)[0]['track_uri']);
    }

    public function testThumbsUpLogsTheRatingAndClearsADislikeWithoutStaging(): void
    {
        $playlist = $this->playlist('owned2', 'Mine', 1, 'synced');
        $this->trackOn($playlist, 'abc', 'North Line');
        $this->library->rateTrack($this->user, $playlist, 'spotify:track:abc', 'down', 'david');

        $up = $this->library->rateTrack($this->user, $playlist, 'spotify:track:abc', 'up', 'david');

        self::assertSame('up', $up['rating']);
        self::assertSame('user', $up['source']);
        self::assertFalse($up['disliked']);
        self::assertFalse($up['should_push']);
        self::assertSame([], $this->library->dislikedTracks($this->user));
        self::assertNotContains('spotify:track:abc', $this->library->taste($this->user)['profile']['dislikes']['tracks']);

        $ratings = $this->library->ratings($this->user, $playlist, null, 10);
        self::assertSame(['up', 'down'], array_column($ratings, 'rating'));
        self::assertSame(['user', 'user'], array_column($ratings, 'source'));
        self::assertSame('North Line', $ratings[0]['name']);
    }

    public function testABadUriWritesNothing(): void
    {
        $playlist = $this->playlist('owned3', 'Mine', 1, 'created');
        try {
            $this->library->rateTrack($this->user, $playlist, 'not-a-track', 'up', 'david');
            self::fail('A bad URI should be refused.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('spotify:track', $e->getMessage());
        }
        self::assertSame(0, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM spotify_track_ratings WHERE user_id = ?',
            [$this->user]
        ));
    }

    private function playlist(string $spotifyId, string $name, int $owned, string $origin, ?string $owner = null): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO spotify_playlists (user_id, spotify_id, name, is_owned, origin, owner_name)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$this->user, $spotifyId, $name, $owned, $origin, $owner]
        );
    }

    private function trackOn(int $playlistId, string $spotifyId, string $name): void
    {
        $trackId = (int) $this->db->fetchScalar(
            'INSERT INTO spotify_tracks (user_id, spotify_id, name, artists, album, duration_ms)
             VALUES (?, ?, ?, ?::jsonb, \'Night\', 180000) RETURNING id',
            [$this->user, $spotifyId, $name, '["Ada"]']
        );
        $this->db->execute(
            'INSERT INTO spotify_playlist_tracks (user_id, playlist_id, track_id, position) VALUES (?, ?, ?, 0)',
            [$this->user, $playlistId, $trackId]
        );
    }
}
