<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Spotify\MusicLibrary;
use Dashboard\Spotify\SpotifyAccount;
use Dashboard\Spotify\SpotifyHttp;
use Dashboard\Spotify\SpotifyLibrary;

final class MusicLibraryTest extends PgsqlTestCase
{
    private MusicLibrary $music;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->user = (int) $this->db->fetchScalar(
            "INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h') RETURNING id"
        );
        $http = new SpotifyHttp(static function (): array {
            throw new \RuntimeException('Spotify should not be called.');
        });
        $this->music = new MusicLibrary(
            $this->db,
            new SpotifyAccount($this->db, $http),
            $http,
            new SpotifyLibrary($this->db)
        );
    }

    public function testGenreTagIsStoredAndUndoRemovesIt(): void
    {
        $saved = $this->music->addGenre($this->user, 'spotify:track:abc', 'Indie Pop');
        $notes = $this->music->notes($this->user, ['spotify:track:abc']);
        self::assertSame('indie pop', $notes['tracks'][0]['genre_tags'][0]['name']);
        self::assertSame('manual', $notes['tracks'][0]['genre_tags'][0]['source']);

        $other = (int) $this->db->fetchScalar(
            "INSERT INTO users (username, email, password_hash) VALUES ('other', 'o@x', 'h') RETURNING id"
        );
        $hidden = $this->music->notes($other, ['spotify:track:abc']);
        self::assertSame([], $hidden['tracks'][0]['genre_tags']);

        $this->music->undo($this->user, $saved['log_id']);
        $after = $this->music->notes($this->user, ['spotify:track:abc']);
        self::assertSame([], $after['tracks'][0]['genre_tags']);
        $activity = $this->music->activity($this->user);
        $entry = $this->entry($activity['entries'], 'Track tagged');
        self::assertTrue($entry['undone']);
        self::assertFalse($entry['can_undo']);
    }

    public function testDrawerUsesStoredLoudnessAndPlaylistMembership(): void
    {
        $track = $this->track('abc', 'North Line');
        $playlist = $this->playlist('Night Drive');
        $this->db->execute(
            'INSERT INTO spotify_playlist_tracks (user_id, playlist_id, track_id, position) VALUES (?, ?, ?, 0)',
            [$this->user, $playlist, $track]
        );
        $this->db->execute(
            'INSERT INTO spotify_track_meta (user_id, spotify_track_id, loudness) VALUES (?, ?, ?::jsonb)',
            [$this->user, 'abc', '[-20,-5]']
        );

        $drawer = $this->music->drawer($this->user, 'spotify:track:abc');
        self::assertSame([0, 100], $drawer['track']['waveform']);
        self::assertSame('Night Drive', $drawer['appears_in'][0]['name']);
        self::assertSame('?tab=playlists&id=' . $playlist, $drawer['appears_in'][0]['href']);
    }

    public function testDrawerWaveformStaysEmptyWithoutLoudness(): void
    {
        $track = $this->track('def', 'South Line');
        $playlist = $this->playlist('Day Drive');
        $this->db->execute(
            'INSERT INTO spotify_playlist_tracks (user_id, playlist_id, track_id, position) VALUES (?, ?, ?, 0)',
            [$this->user, $playlist, $track]
        );

        $drawer = $this->music->drawer($this->user, 'spotify:track:def');
        self::assertSame([], $drawer['track']['waveform']);
        self::assertNull($drawer['track']['loudness']);
        self::assertSame('Day Drive', $drawer['appears_in'][0]['name']);
    }

    public function testActivityLogUndoAndMonitoring(): void
    {
        $idle = $this->music->activity($this->user);
        self::assertSame('idle', $idle['monitoring']['state']);
        self::assertSame('No sync recorded', $idle['monitoring']['label']);
        self::assertSame([], $idle['entries']);

        $this->db->execute(
            "INSERT INTO spotify_season_windows (user_id, season, start_anchor, end_month, end_day)
             VALUES (?, 'christmas', 'thanksgiving', 1, 6)",
            [$this->user]
        );
        $saved = $this->music->setSeason($this->user, ['spotify:track:abc'], 'christmas');
        $notes = $this->music->notes($this->user, ['spotify:track:abc']);
        self::assertSame(['christmas'], $notes['tracks'][0]['season_tags']);
        self::assertContains('christmas', $notes['seasons']);

        $open = $this->music->activity($this->user);
        $entry = $this->entry($open['entries'], 'Set season');
        self::assertTrue($entry['can_undo']);
        self::assertStringContainsString('spotify%3Atrack%3Aabc', (string) $entry['href']);

        $this->music->undo($this->user, $saved['log_id']);
        $cleared = $this->music->notes($this->user, ['spotify:track:abc']);
        self::assertSame([], $cleared['tracks'][0]['season_tags']);
        $closed = $this->entry($this->music->activity($this->user)['entries'], 'Set season');
        self::assertTrue($closed['undone']);
        self::assertFalse($closed['can_undo']);

        $this->db->execute(
            "INSERT INTO spotify_sync_log (user_id, kind, status, counts, finished_at)
             VALUES (?, 'pull', 'ok', ?::jsonb, (now() AT TIME ZONE 'UTC'))",
            [$this->user, '{"playlists":2}']
        );
        $watching = $this->music->activity($this->user);
        self::assertSame('ok', $watching['monitoring']['state']);
        self::assertSame('Monitoring', $watching['monitoring']['label']);
        $synced = $this->entry($watching['entries'], 'Playlist synced');
        self::assertFalse($synced['can_undo']);
        self::assertSame('?tab=playlists', $synced['href']);
        self::assertStringContainsString('2 playlists', (string) $synced['summary']);

        $this->db->execute(
            "INSERT INTO spotify_sync_log (user_id, kind, status, error, finished_at)
             VALUES (?, 'pull', 'error', ?, (now() AT TIME ZONE 'UTC'))",
            [$this->user, 'Spotify refused that request (403).']
        );
        $failed = $this->music->activity($this->user);
        self::assertSame('error', $failed['monitoring']['state']);
        self::assertSame('Sync failed', $failed['monitoring']['label']);
        $row = $this->entry($failed['entries'], 'Sync failed');
        self::assertSame('Spotify refused that request (403).', $row['summary']);
        self::assertFalse($row['can_undo']);
    }

    public function testReanalyzeKeepsLoudnessWhenAnalysisIsMissing(): void
    {
        $first = $this->music->applyAnalysis($this->user, [[
            'uri' => 'spotify:track:abc',
            'missing' => false,
            'genres' => ['indie pop'],
            'analysis' => 'ready',
            'loudness' => [-20, -5],
        ]]);
        self::assertStringContainsString('Loudness samples stored for 1 track', $first['summary']);
        $drawer = $this->music->drawer($this->user, 'spotify:track:abc');
        self::assertSame([0, 100], $drawer['track']['waveform']);
        self::assertSame('spotify', $drawer['track']['genre_tags'][0]['source']);

        $second = $this->music->applyAnalysis($this->user, [[
            'uri' => 'spotify:track:abc',
            'missing' => false,
            'genres' => ['rock'],
            'analysis' => 'unavailable',
        ]]);
        self::assertStringContainsString('Spotify did not return audio features for 1 track', $second['summary']);
        $kept = $this->music->drawer($this->user, 'spotify:track:abc');
        self::assertSame([0, 100], $kept['track']['waveform']);
        self::assertSame('rock', $kept['track']['genre_tags'][0]['name']);

        $this->music->undo($this->user, $second['log_id']);
        $restored = $this->music->drawer($this->user, 'spotify:track:abc');
        self::assertSame('indie pop', $restored['track']['genre_tags'][0]['name']);
        self::assertSame([0, 100], $restored['track']['waveform']);
    }

    public function testAddToPlaylistUndoDiscardsOnlyThatChange(): void
    {
        $playlist = $this->playlist('Night Drive');
        $saved = $this->music->addToPlaylist($this->user, ['spotify:track:abc'], $playlist, 'david', [[
            'uri' => 'spotify:track:abc',
            'name' => 'North Line',
            'artists' => ['Ada'],
        ]]);
        $changeId = (int) $this->db->fetchScalar(
            "SELECT id FROM spotify_playlist_changes WHERE user_id = ? AND playlist_id = ? AND op = 'add'",
            [$this->user, $playlist]
        );
        self::assertSame('pending', (string) $this->db->fetchScalar(
            'SELECT status FROM spotify_playlist_changes WHERE id = ?',
            [$changeId]
        ));
        $open = $this->entry($this->music->activity($this->user)['entries'], 'Added to playlist');
        self::assertTrue($open['can_undo']);
        self::assertSame('?tab=playlists&id=' . $playlist, $open['href']);

        $this->music->undo($this->user, $saved['log_id']);
        self::assertSame('discarded', (string) $this->db->fetchScalar(
            'SELECT status FROM spotify_playlist_changes WHERE id = ?',
            [$changeId]
        ));
        self::assertSame(0, (int) $this->db->fetchScalar(
            'SELECT local_dirty FROM spotify_playlists WHERE id = ?',
            [$playlist]
        ));
        $closed = $this->entry($this->music->activity($this->user)['entries'], 'Added to playlist');
        self::assertFalse($closed['can_undo']);
        self::assertTrue($closed['undone']);
    }

    private function track(string $spotifyId, string $name): int
    {
        return (int) $this->db->fetchScalar(
            'INSERT INTO spotify_tracks (user_id, spotify_id, name, artists, album, duration_ms)
             VALUES (?, ?, ?, ?::jsonb, \'Night\', 180000) RETURNING id',
            [$this->user, $spotifyId, $name, '["Ada"]']
        );
    }

    private function playlist(string $name): int
    {
        return (int) $this->db->fetchScalar(
            "INSERT INTO spotify_playlists (user_id, name, is_owned, origin) VALUES (?, ?, 1, 'created') RETURNING id",
            [$this->user, $name]
        );
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, mixed>
     */
    private function entry(array $entries, string $title): array
    {
        foreach ($entries as $entry) {
            if (($entry['title'] ?? '') === $title) {
                return $entry;
            }
        }
        self::fail('Missing activity entry ' . $title);
    }
}
