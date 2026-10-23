<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Spotify\SpotifyLibrary;
use Dashboard\Spotify\SpotifyPlayback;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class SpotifyPlaylistFiltersTest extends PgsqlTestCase
{
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h')");
        $this->user = (int) $this->db->lastInsertId();
    }

    public function testAllowOutsideColumnSkipsTheClosedWindowAndCountsContent(): void
    {
        $july = $this->library('2026-07-04 18:00:00');
        $uri = 'spotify:track:carol01';
        $july->mixtape()->saveMany($this->user, [[
            'uri' => $uri,
            'energy' => 4,
            'mood_tags' => 'warm',
            'season_tags' => 'christmas',
            'source' => 'manual',
        ]]);
        $created = $july->createPlaylist($this->user, ['name' => 'Holiday'], 'david');
        $playlistId = (int) $created['playlist']['id'];
        $this->place($playlistId, 'carol01', 'Carol');

        $before = $july->mixtape()->seasons($this->user)['seasons'];
        $christmas = $this->season($before, 'christmas');
        self::assertFalse($christmas['allow_outside']);
        self::assertSame(1, $christmas['track_count']);
        self::assertSame(1, $christmas['playlist_count']);
        self::assertSame(0, $this->season($before, 'holiday')['track_count']);
        $this->expectWindow($july, $playlistId, [$uri]);

        $opened = $july->mixtape()->saveSeason($this->user, [
            'season' => 'christmas',
            'start_anchor' => 'thanksgiving',
            'end_month' => 1,
            'end_day' => 6,
            'allow_outside' => true,
        ]);
        self::assertTrue($opened['allow_outside']);
        $staged = $july->addTracks($this->user, $playlistId, [$uri], null, 'david');
        self::assertContains($uri, array_column($staged['preview'], 'uri'));

        $kept = $july->mixtape()->saveSeason($this->user, [
            'season' => 'christmas',
            'start_anchor' => 'thanksgiving',
            'end_month' => 1,
            'end_day' => 6,
        ]);
        self::assertTrue($kept['allow_outside']);

        $july->mixtape()->saveSeason($this->user, [
            'season' => 'christmas',
            'start_anchor' => 'thanksgiving',
            'end_month' => 1,
            'end_day' => 6,
            'allow_outside' => false,
        ]);
        $closed = $this->season($july->mixtape()->seasons($this->user)['seasons'], 'christmas');
        self::assertFalse($closed['allow_outside']);
        $this->expectWindow($july, $playlistId, [$uri]);
    }

    public function testPlaylistFacetsAggregateSeasonMoodAndEnergy(): void
    {
        $lib = $this->library('2026-07-04 18:00:00');
        $summer = $lib->createPlaylist($this->user, ['name' => 'Summer'], 'david');
        $empty = $lib->createPlaylist($this->user, ['name' => 'Empty'], 'david');
        $summerId = (int) $summer['playlist']['id'];
        $lib->mixtape()->saveMany($this->user, [
            ['uri' => 'spotify:track:sun00001', 'energy' => 2, 'mood_tags' => ['warm'], 'season_tags' => ['summer'], 'source' => 'manual'],
            ['uri' => 'spotify:track:sun00002', 'energy' => 9, 'mood_tags' => ['warm', 'late'], 'season_tags' => ['summer', 'holiday'], 'source' => 'manual'],
        ]);
        $this->place($summerId, 'sun00001', 'Sun');
        $this->place($summerId, 'sun00002', 'Later');

        $facets = $lib->playlistFacets($this->user)['facets'];
        $row = $facets[(string) $summerId];
        self::assertSame('summer', $row['season']);
        self::assertEqualsCanonicalizing(['summer', 'holiday'], $row['seasons']);
        self::assertEqualsCanonicalizing(['warm', 'late'], $row['moods']);
        self::assertSame(2, $row['energy_min']);
        self::assertSame(9, $row['energy_max']);
        self::assertNotSame('', $row['created_at']);

        $bare = $facets[(string) $empty['playlist']['id']];
        self::assertNull($bare['season']);
        self::assertSame([], $bare['seasons']);
        self::assertSame([], $bare['moods']);
        self::assertNull($bare['energy_min']);
        self::assertNotSame('', $bare['created_at']);
    }

    public function testCatalogRefreshUpdatesStoredTracksAndLeavesNotes(): void
    {
        $lib = new SpotifyLibrary($this->db);
        $this->db->execute(
            "INSERT INTO spotify_tracks (user_id, spotify_id, name, duration_ms, updated_at)
             VALUES (?, 'oldtrack1', 'Old Name', 1000, (now() AT TIME ZONE 'UTC') - interval '2 days')",
            [$this->user]
        );
        $this->db->execute(
            "INSERT INTO spotify_tracks (user_id, spotify_id, name, duration_ms)
             VALUES (?, 'freshtrk1', 'Fresh', 2000)",
            [$this->user]
        );
        $lib->mixtape()->saveMany($this->user, [[
            'uri' => 'spotify:track:oldtrack1',
            'energy' => 4,
            'source' => 'manual',
        ]]);

        $queue = $lib->catalogStale($this->user);
        self::assertSame(1, $queue['stale']);
        self::assertSame(['oldtrack1'], $queue['ids']);

        $rows = SpotifyPlayback::catalogRows([[
            'id' => 'oldtrack1',
            'name' => 'New Name',
            'artists' => [['name' => 'M83']],
            'album' => ['name' => 'Hurry Up', 'images' => [['url' => 'https://i.scdn.co/art.jpg']]],
            'duration_ms' => 240000,
            'explicit' => true,
            'popularity' => 64,
            'energy' => 0.9,
        ], [
            'id' => 'missing99',
            'name' => 'Not stored',
        ]]);
        $applied = $lib->applyCatalog($this->user, $rows);
        self::assertSame(1, $applied['updated']);
        self::assertSame(1, $applied['missing']);
        self::assertSame(2, $applied['checked']);

        $stored = $this->db->fetchOne(
            'SELECT name, artists, album, duration_ms, explicit, popularity, image_url FROM spotify_tracks WHERE user_id = ? AND spotify_id = ?',
            [$this->user, 'oldtrack1']
        );
        self::assertSame('New Name', $stored['name']);
        self::assertSame(['M83'], json_decode((string) $stored['artists'], true));
        self::assertSame('Hurry Up', $stored['album']);
        self::assertSame(240000, (int) $stored['duration_ms']);
        self::assertSame(1, (int) $stored['explicit']);
        self::assertSame(64, (int) $stored['popularity']);
        self::assertSame('https://i.scdn.co/art.jpg', $stored['image_url']);
        $meta = $lib->mixtape()->get($this->user, ['spotify:track:oldtrack1'])['tracks'][0];
        self::assertSame(4, $meta['energy']);
        self::assertSame(0, $lib->catalogStale($this->user)['stale']);
        self::assertSame(2, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM spotify_tracks WHERE user_id = ?', [$this->user]));
    }

    /**
     * @param list<array<string, mixed>> $seasons
     * @return array<string, mixed>
     */
    private function season(array $seasons, string $name): array
    {
        foreach ($seasons as $season) {
            if ($season['season'] === $name) {
                return $season;
            }
        }
        self::fail('Missing season ' . $name);
    }

    private function place(int $playlistId, string $spotifyId, string $name): void
    {
        $trackId = (int) $this->db->fetchScalar(
            'INSERT INTO spotify_tracks (user_id, spotify_id, name, duration_ms)
             VALUES (?, ?, ?, 180000)
             ON CONFLICT (user_id, spotify_id) DO UPDATE SET name = EXCLUDED.name
             RETURNING id',
            [$this->user, $spotifyId, $name]
        );
        $this->db->execute(
            'INSERT INTO spotify_playlist_tracks (user_id, playlist_id, track_id, position)
             VALUES (?, ?, ?, 0)',
            [$this->user, $playlistId, $trackId]
        );
    }

    /**
     * @param list<string> $uris
     */
    private function expectWindow(SpotifyLibrary $lib, int $playlistId, array $uris): void
    {
        try {
            $lib->addTracks($this->user, $playlistId, $uris, null, 'david');
            self::fail('A closed season should be refused.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('outside its window', $e->getMessage());
        }
    }

    private function library(string $utc): SpotifyLibrary
    {
        return new SpotifyLibrary(
            $this->db,
            new Clock('America/Chicago', new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        );
    }
}
