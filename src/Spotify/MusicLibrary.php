<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\DB\Database;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/** Track-library genres, drawer facts, and the undoable activity log. */
final class MusicLibrary
{
    private const SAMPLE_LIMIT = 48;

    public function __construct(
        private readonly Database $db,
        private readonly SpotifyAccount $account,
        private readonly SpotifyHttp $http,
        private readonly SpotifyLibrary $library,
    ) {}

    /**
     * @param list<string> $uris
     * @return array{tracks: list<array<string, mixed>>, seasons: list<string>, playlists: list<array{id: int, name: string}>}
     */
    public function notes(int $userId, array $uris): array
    {
        $ids = $this->ids($uris, true);
        $byId = $this->metaById($userId, $ids);
        $tracks = [];
        foreach ($ids as $id) {
            $tracks[] = $byId[$id] ?? $this->blank($id);
        }
        return [
            'tracks' => $tracks,
            'seasons' => $this->seasonChoices($userId),
            'playlists' => $this->ownedPlaylists($userId),
        ];
    }

    /**
     * @return array{track: array<string, mixed>, appears_in: list<array{id: int, name: string, href: string}>}
     */
    public function drawer(int $userId, string $uri): array
    {
        $id = Mixtape::trackId($uri);
        $row = $this->metaById($userId, [$id])[$id] ?? $this->blank($id);
        $row['waveform'] = self::waveform($row['loudness'] ?? null);
        return [
            'track' => $row,
            'appears_in' => $this->appearsIn($userId, $id),
        ];
    }

    /**
     * @return array{summary: string, log_id: int}
     */
    public function addGenre(int $userId, string $uri, mixed $genre): array
    {
        $id = Mixtape::trackId($uri);
        $name = self::genreName($genre, true);
        if ($name === null) {
            throw ValidationException::withFields(['genre' => 'Enter a genre.']);
        }
        $current = $this->metaById($userId, [$id])[$id] ?? null;
        $previous = $current['genre_tags'] ?? [];
        $next = self::uniqueGenres(array_merge($previous, [['name' => $name, 'source' => 'manual']]));
        if (count($next) > 12) {
            throw ValidationException::withFields(['genre' => 'At most 12 genres.']);
        }
        if (self::sameJson($previous, $next)) {
            throw ValidationException::withFields(['genre' => 'That genre is already on the track.']);
        }
        $label = $this->trackLabel($userId, $id);
        $summary = 'Added genre ' . $name . ' on ' . $label . '.';
        $logId = $this->db->transaction(function () use ($userId, $id, $current, $next, $previous, $summary): int {
            $this->saveState(
                $userId,
                $id,
                $next,
                $current['season_tags'] ?? [],
                $current['loudness'] ?? null,
                'manual'
            );
            return $this->log($userId, 'genre', 'Track tagged', $summary, self::libraryHref($id), [
                'tracks' => [['uri' => 'spotify:track:' . $id, 'genre_tags' => $previous]],
            ]);
        });
        return ['summary' => $summary, 'log_id' => $logId];
    }

    /**
     * @param list<string> $uris
     * @return array{summary: string, log_id: int}
     */
    public function setSeason(int $userId, array $uris, mixed $season): array
    {
        $ids = $this->ids($uris, false);
        $tags = Mixtape::tags($season, 'season');
        if (count($tags) !== 1) {
            throw ValidationException::withFields(['season' => 'Name one season, such as christmas.']);
        }
        $name = $tags[0];
        $known = $this->db->fetchOne(
            'SELECT season FROM spotify_season_windows WHERE user_id = ? AND season = ?',
            [$userId, $name]
        );
        if ($known === null) {
            throw ValidationException::withFields(['season' => 'Save that season window before assigning it.']);
        }
        $byId = $this->metaById($userId, $ids);
        $logId = $this->db->transaction(function () use ($userId, $ids, $byId, $name): int {
            $undo = [];
            $changed = 0;
            foreach ($ids as $id) {
                $current = $byId[$id] ?? null;
                $previous = $current['season_tags'] ?? [];
                $next = array_values(array_unique(array_merge($previous, [$name])));
                if (self::sameJson($previous, $next)) {
                    continue;
                }
                $this->saveState(
                    $userId,
                    $id,
                    $current['genre_tags'] ?? [],
                    $next,
                    $current['loudness'] ?? null,
                    'manual'
                );
                $undo[] = ['uri' => 'spotify:track:' . $id, 'season_tags' => $previous];
                $changed++;
            }
            $summary = $changed === 0
                ? 'Those tracks already had that season.'
                : 'Assigned ' . $name . ' on ' . $changed . ' ' . ($changed === 1 ? 'track' : 'tracks') . '.';
            return $this->log(
                $userId,
                'set_season',
                'Set season',
                $summary,
                self::libraryHref($ids[0]),
                $undo === [] ? null : ['tracks' => $undo]
            );
        });
        $summary = (string) $this->db->fetchScalar(
            'SELECT summary FROM spotify_library_log WHERE user_id = ? AND id = ?',
            [$userId, $logId]
        );
        return ['summary' => $summary, 'log_id' => $logId];
    }

    /**
     * @param list<string> $uris
     * @param list<array<string, mixed>> $tracks
     * @return array{summary: string, log_id: int}
     */
    public function addToPlaylist(int $userId, array $uris, int $playlistId, string $by, array $tracks): array
    {
        if ($playlistId < 1) {
            throw ValidationException::withFields(['playlist_id' => 'Choose a playlist.']);
        }
        $ids = $this->ids($uris, false);
        $list = array_map(static fn (string $id): string => 'spotify:track:' . $id, $ids);
        $playlist = $this->db->fetchOne(
            'SELECT id, name FROM spotify_playlists WHERE user_id = ? AND id = ?',
            [$userId, $playlistId]
        );
        if ($playlist === null) {
            throw new NotFoundException('Playlist not found.');
        }
        $meta = $this->trackMeta($list, $tracks);
        $this->library->addTracks($userId, $playlistId, $list, null, $by, $meta);
        $changeId = (int) $this->db->fetchScalar(
            "SELECT id FROM spotify_playlist_changes
             WHERE user_id = ? AND playlist_id = ? AND op = 'add' AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            [$userId, $playlistId]
        );
        $count = count($list);
        $summary = 'Staged ' . $count . ' ' . ($count === 1 ? 'track' : 'tracks') . ' on ' . (string) $playlist['name'] . '.';
        $logId = $this->log($userId, 'add_playlist', 'Added to playlist', $summary, '?tab=playlists&id=' . $playlistId, [
            'playlist_id' => $playlistId,
            'change_id' => $changeId,
        ]);
        return ['summary' => $summary, 'log_id' => $logId];
    }

    /**
     * @param list<string> $uris
     * @return array{summary: string, log_id: int}
     */
    public function reanalyze(int $userId, array $uris): array
    {
        $ids = $this->ids($uris, false);
        // Each track asks Spotify for its own analysis document.
        if (count($ids) > 20) {
            throw ValidationException::withFields(['uris' => 'Re-analyze at most 20 tracks at once.']);
        }
        $raw = $this->account->call($userId, fn (string $token): array => $this->fetchRaw($token, $ids));
        return $this->applyAnalysis($userId, self::buildReports($ids, is_array($raw) ? $raw : []));
    }

    /**
     * @param list<array<string, mixed>> $reports
     * @return array{summary: string, log_id: int}
     */
    public function applyAnalysis(int $userId, array $reports): array
    {
        if ($reports === []) {
            throw ValidationException::withFields(['uris' => 'Select at least one track.']);
        }
        $ids = [];
        foreach ($reports as $report) {
            if (!is_array($report) || !empty($report['missing'])) {
                continue;
            }
            $ids[] = Mixtape::trackId((string) ($report['uri'] ?? ''));
        }
        $byId = $this->metaById($userId, $ids);
        $logged = $this->db->transaction(function () use ($userId, $reports, $byId, $ids): array {
            $undo = [];
            $genreChanged = 0;
            $loudnessChanged = 0;
            $unavailable = 0;
            $missing = 0;
            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }
                if (!empty($report['missing'])) {
                    $missing++;
                    continue;
                }
                $id = Mixtape::trackId((string) ($report['uri'] ?? ''));
                $current = $byId[$id] ?? null;
                $previousGenres = $current['genre_tags'] ?? [];
                $previousLoudness = $current['loudness'] ?? null;
                $nextGenres = $this->mergeSpotifyGenres($previousGenres, $report['genres'] ?? []);
                $touchLoudness = ($report['analysis'] ?? '') === 'ready';
                if (!$touchLoudness) {
                    $unavailable++;
                }
                $nextLoudness = $touchLoudness ? self::storeLoudness($report['loudness'] ?? []) : $previousLoudness;
                $piece = ['uri' => 'spotify:track:' . $id];
                if (!self::sameJson($previousGenres, $nextGenres)) {
                    $piece['genre_tags'] = $previousGenres;
                    $genreChanged++;
                }
                if ($touchLoudness && !self::sameJson($previousLoudness, $nextLoudness)) {
                    $piece['loudness'] = $previousLoudness;
                    $loudnessChanged++;
                }
                if (count($piece) === 1) {
                    continue;
                }
                $this->saveState(
                    $userId,
                    $id,
                    $nextGenres,
                    $current['season_tags'] ?? [],
                    $nextLoudness,
                    'estimated'
                );
                $undo[] = $piece;
            }
            $summary = self::analysisSummary($genreChanged, $loudnessChanged, $unavailable, $missing);
            $title = ($genreChanged + $loudnessChanged) > 0 ? 'Refreshed track metadata' : 'Metadata refresh returned nothing new';
            $href = $ids === [] ? '?tab=library' : self::libraryHref($ids[0]);
            return [
                'summary' => $summary,
                'log_id' => $this->log($userId, 'reanalyze', $title, $summary, $href, $undo === [] ? null : ['tracks' => $undo]),
            ];
        });
        return $logged;
    }

    /**
     * @return array{summary: string}
     */
    public function undo(int $userId, int $logId): array
    {
        if ($logId < 1) {
            throw ValidationException::withFields(['id' => 'Expected an activity id.']);
        }
        $row = $this->db->fetchOne(
            'SELECT id, action, undo_payload, undone_at FROM spotify_library_log WHERE user_id = ? AND id = ?',
            [$userId, $logId]
        );
        if ($row === null) {
            throw new NotFoundException('Activity not found.');
        }
        if ($row['undone_at'] !== null) {
            throw ValidationException::withFields(['id' => 'That activity is already undone.']);
        }
        $payload = self::decodeJson($row['undo_payload']);
        if ($payload === null) {
            throw ValidationException::withFields(['id' => 'That activity cannot be undone.']);
        }
        $this->db->transaction(function () use ($userId, $logId, $row, $payload): void {
            if ((string) $row['action'] === 'add_playlist') {
                $this->undoPlaylistAdd($userId, $payload);
            } else {
                $this->undoTracks($userId, $payload);
            }
            $updated = $this->db->execute(
                'UPDATE spotify_library_log SET undone_at = (now() AT TIME ZONE \'UTC\')
                 WHERE user_id = ? AND id = ? AND undone_at IS NULL',
                [$userId, $logId]
            );
            if ($updated < 1) {
                throw ValidationException::withFields(['id' => 'That activity is already undone.']);
            }
        });
        return ['summary' => 'Undone.'];
    }

    /**
     * @return array{monitoring: array{state: string, label: string}, entries: list<array<string, mixed>>}
     */
    public function activity(int $userId): array
    {
        $entries = [];
        $logs = $this->db->fetchAll(
            'SELECT id, action, title, summary, href, undo_payload, undone_at, created_at
             FROM spotify_library_log WHERE user_id = ? ORDER BY id DESC LIMIT 40',
            [$userId]
        );
        $changeIds = [];
        foreach ($logs as $log) {
            $payload = self::decodeJson($log['undo_payload']);
            if ((string) $log['action'] === 'add_playlist' && is_array($payload)) {
                $changeIds[] = (int) ($payload['change_id'] ?? 0);
            }
        }
        $openChanges = $this->openChangeIds($userId, $changeIds);
        foreach ($logs as $log) {
            $payload = self::decodeJson($log['undo_payload']);
            $undone = $log['undone_at'] !== null;
            $entries[] = [
                'id' => (int) $log['id'],
                'at' => (string) $log['created_at'],
                'title' => (string) $log['title'],
                'summary' => (string) $log['summary'],
                'href' => $log['href'] !== null ? (string) $log['href'] : '',
                'can_undo' => !$undone && $this->canUndo((string) $log['action'], $payload, $openChanges),
                'undone' => $undone,
                'icon' => self::icon((string) $log['action']),
            ];
        }
        $syncs = $this->db->fetchAll(
            'SELECT kind, status, counts, error, started_at, finished_at
             FROM spotify_sync_log WHERE user_id = ? ORDER BY id DESC LIMIT 20',
            [$userId]
        );
        foreach ($syncs as $sync) {
            $entries[] = [
                'at' => (string) ($sync['finished_at'] ?? $sync['started_at']),
                'title' => self::syncTitle($sync),
                'summary' => self::syncSummary($sync),
                'href' => '?tab=playlists',
                'can_undo' => false,
                'undone' => false,
                'icon' => 'history',
            ];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));
        return [
            'monitoring' => $this->monitoring($userId),
            'entries' => array_slice($entries, 0, 40),
        ];
    }

    /**
     * Display heights from stored loudness samples. No samples means no bars.
     *
     * @return list<int>
     */
    public static function waveform(mixed $loudness): array
    {
        $values = self::storeLoudness($loudness);
        if ($values === null) {
            return [];
        }
        $min = min($values);
        $max = max($values);
        $span = $max - $min;
        $bars = [];
        foreach ($values as $value) {
            // A flat sample has no range, so every bar stays level.
            $bars[] = $span < 0.05 ? 40 : (int) round((($value - $min) / $span) * 100);
        }
        return $bars;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return list<float>
     */
    public static function loudnessFromAnalysis(array $analysis): array
    {
        $segments = $analysis['segments'] ?? null;
        if (!is_array($segments)) {
            return [];
        }
        $samples = [];
        foreach ($segments as $segment) {
            if (!is_array($segment) || !isset($segment['loudness_max']) || !is_numeric($segment['loudness_max'])) {
                continue;
            }
            $samples[] = round((float) $segment['loudness_max'], 2);
        }
        return self::pickSamples($samples, self::SAMPLE_LIMIT);
    }

    /**
     * @param list<mixed> $artists
     * @return list<string>
     */
    public static function genreNames(array $artists): array
    {
        $out = [];
        foreach ($artists as $artist) {
            if (!is_array($artist) || !is_array($artist['genres'] ?? null)) {
                continue;
            }
            foreach ($artist['genres'] as $genre) {
                $name = self::genreName($genre, false);
                if ($name !== null) {
                    $out[] = $name;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $raw
     * @return list<array<string, mixed>>
     */
    public static function buildReports(array $ids, array $raw): array
    {
        $tracks = [];
        foreach ($raw['tracks'] ?? [] as $track) {
            if (is_array($track) && isset($track['id'])) {
                $tracks[(string) $track['id']] = $track;
            }
        }
        $genresByArtist = [];
        foreach ($raw['artists'] ?? [] as $artist) {
            if (!is_array($artist) || !isset($artist['id'])) {
                continue;
            }
            $genresByArtist[(string) $artist['id']] = self::genreNames([$artist]);
        }
        $reports = [];
        foreach ($ids as $id) {
            $track = $tracks[$id] ?? null;
            if (!is_array($track)) {
                $reports[] = ['uri' => 'spotify:track:' . $id, 'missing' => true];
                continue;
            }
            $names = [];
            foreach ($track['artists'] ?? [] as $artist) {
                $artistId = is_array($artist) ? (string) ($artist['id'] ?? '') : '';
                foreach ($genresByArtist[$artistId] ?? [] as $name) {
                    $names[] = $name;
                }
            }
            $analysis = $raw['analysis'][$id] ?? ['analysis' => 'unavailable', 'body' => []];
            $state = (string) ($analysis['analysis'] ?? 'unavailable');
            $report = [
                'uri' => 'spotify:track:' . $id,
                'missing' => false,
                'genres' => array_values(array_unique($names)),
                'analysis' => $state,
            ];
            if ($state === 'ready') {
                $body = is_array($analysis['body'] ?? null) ? $analysis['body'] : [];
                $report['loudness'] = self::loudnessFromAnalysis($body);
            }
            $reports[] = $report;
        }
        return $reports;
    }

    /**
     * The drawer stores one bar per sample, so a long analysis is thinned to real points.
     *
     * @param list<float> $samples
     * @return list<float>
     */
    public static function pickSamples(array $samples, int $limit): array
    {
        $count = count($samples);
        if ($count <= $limit || $limit < 2) {
            return $samples;
        }
        $picked = [];
        for ($i = 0; $i < $limit; $i++) {
            $picked[] = $samples[(int) floor($i * ($count - 1) / ($limit - 1))];
        }
        return $picked;
    }

    /**
     * @param list<string> $ids
     * @return array{tracks: list<mixed>, artists: list<mixed>, analysis: array<string, array{analysis: string, body: array<string, mixed>}>}
     */
    private function fetchRaw(string $token, array $ids): array
    {
        $tracks = $this->http->api('GET', '/tracks', $token, null, ['ids' => implode(',', $ids)]);
        $artistIds = [];
        foreach ($tracks['tracks'] ?? [] as $track) {
            if (!is_array($track)) {
                continue;
            }
            foreach ($track['artists'] ?? [] as $artist) {
                if (is_array($artist) && isset($artist['id']) && is_string($artist['id']) && $artist['id'] !== '') {
                    $artistIds[$artist['id']] = true;
                }
            }
        }
        $artists = [];
        $idList = array_keys($artistIds);
        $offset = 0;
        while ($offset < count($idList)) {
            $slice = array_slice($idList, $offset, 50);
            $offset += 50;
            $batch = $this->http->api('GET', '/artists', $token, null, ['ids' => implode(',', $slice)]);
            foreach ($batch['artists'] ?? [] as $artist) {
                $artists[] = $artist;
            }
        }
        $analysis = [];
        foreach ($ids as $id) {
            $analysis[$id] = $this->analysisFor($token, $id);
        }
        return [
            'tracks' => is_array($tracks['tracks'] ?? null) ? $tracks['tracks'] : [],
            'artists' => $artists,
            'analysis' => $analysis,
        ];
    }

    /**
     * A refused analysis leaves loudness untouched. Spotify does not offer that document to this app.
     *
     * @return array{analysis: string, body: array<string, mixed>}
     */
    private function analysisFor(string $token, string $id): array
    {
        try {
            $body = $this->http->api('GET', '/audio-analysis/' . rawurlencode($id), $token);
            return ['analysis' => 'ready', 'body' => $body];
        } catch (SpotifyException $e) {
            if (!str_contains($e->getMessage(), 'audio features')) {
                throw $e;
            }
            return ['analysis' => 'unavailable', 'body' => []];
        }
    }

    /**
     * @param list<string> $uris
     * @return list<string>
     */
    private function ids(array $uris, bool $allowEmpty): array
    {
        if ($uris === []) {
            if ($allowEmpty) {
                return [];
            }
            throw ValidationException::withFields(['uris' => 'Select at least one track.']);
        }
        $ids = [];
        foreach ($uris as $uri) {
            $ids[] = Mixtape::trackId((string) $uri);
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 50) {
            throw ValidationException::withFields(['uris' => 'Select at most 50 tracks.']);
        }
        return $ids;
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    private function metaById(int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            "SELECT spotify_track_id, bpm, musical_key, camelot, energy, mood_tags, season_tags,
                    genre_tags, loudness, source, updated_at
             FROM spotify_track_meta WHERE user_id = ? AND spotify_track_id IN ({$placeholders})",
            array_merge([$userId], $ids)
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['spotify_track_id']] = $this->metaRow($row);
        }
        return $byId;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function metaRow(array $row): array
    {
        $id = (string) $row['spotify_track_id'];
        $loudness = self::decodeJson($row['loudness'] ?? null);
        return [
            'spotify_track_id' => $id,
            'uri' => 'spotify:track:' . $id,
            'bpm' => $row['bpm'] !== null ? (float) $row['bpm'] : null,
            'musical_key' => $row['musical_key'] !== null ? (string) $row['musical_key'] : null,
            'camelot' => $row['camelot'] !== null ? (string) $row['camelot'] : null,
            'energy' => $row['energy'] !== null ? (int) $row['energy'] : null,
            'mood_tags' => self::stringList($row['mood_tags'] ?? []),
            'season_tags' => self::stringList($row['season_tags'] ?? []),
            'genre_tags' => self::genreList($row['genre_tags'] ?? []),
            'loudness' => is_array($loudness) ? self::storeLoudness($loudness) : null,
            'source' => (string) $row['source'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(string $id): array
    {
        return [
            'spotify_track_id' => $id,
            'uri' => 'spotify:track:' . $id,
            'bpm' => null,
            'musical_key' => null,
            'camelot' => null,
            'energy' => null,
            'mood_tags' => [],
            'season_tags' => [],
            'genre_tags' => [],
            'loudness' => null,
            'source' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @param list<array{name: string, source: string}> $genres
     * @param list<string> $seasons
     * @param list<float>|null $loudness
     */
    private function saveState(int $userId, string $id, array $genres, array $seasons, ?array $loudness, string $source): void
    {
        $flag = $loudness === null ? 0 : 1;
        $encoded = $loudness === null ? '[]' : json_encode($loudness, JSON_THROW_ON_ERROR);
        $this->db->execute(
            'INSERT INTO spotify_track_meta
                (user_id, spotify_track_id, genre_tags, season_tags, loudness, source)
             VALUES (?, ?, ?::jsonb, ?::jsonb, CASE WHEN ?::int = 1 THEN ?::jsonb ELSE NULL END, ?)
             ON CONFLICT (user_id, spotify_track_id) DO UPDATE SET
                genre_tags = EXCLUDED.genre_tags,
                season_tags = EXCLUDED.season_tags,
                loudness = EXCLUDED.loudness,
                updated_at = (now() AT TIME ZONE \'UTC\')',
            [
                $userId,
                $id,
                json_encode(array_values($genres), JSON_THROW_ON_ERROR),
                json_encode(array_values($seasons), JSON_THROW_ON_ERROR),
                $flag,
                $encoded,
                $source,
            ]
        );
    }

    /**
     * @param list<array{name: string, source: string}> $current
     * @return list<array{name: string, source: string}>
     */
    private function mergeSpotifyGenres(array $current, mixed $names): array
    {
        $manual = array_values(array_filter(
            $current,
            static fn (array $tag): bool => $tag['source'] === 'manual'
        ));
        $spotify = [];
        foreach (self::genreNames([['genres' => is_array($names) ? $names : []]]) as $name) {
            $spotify[] = ['name' => $name, 'source' => 'spotify'];
        }
        $merged = self::uniqueGenres(array_merge($manual, $spotify));
        if (count($merged) > 12) {
            $merged = array_slice($merged, 0, 12);
        }
        return $merged;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function undoPlaylistAdd(int $userId, ?array $payload): void
    {
        $playlistId = (int) ($payload['playlist_id'] ?? 0);
        $changeId = (int) ($payload['change_id'] ?? 0);
        if ($playlistId < 1 || $changeId < 1) {
            throw ValidationException::withFields(['id' => 'That activity cannot be undone.']);
        }
        $updated = $this->db->execute(
            "UPDATE spotify_playlist_changes
             SET status = 'discarded', updated_at = (now() AT TIME ZONE 'UTC')
             WHERE id = ? AND user_id = ? AND playlist_id = ? AND status IN ('pending', 'failed')",
            [$changeId, $userId, $playlistId]
        );
        if ($updated < 1) {
            throw ValidationException::withFields(['id' => 'That playlist change is no longer pending.']);
        }
        // Other staged edits on the playlist stay pending.
        $this->db->execute(
            'UPDATE spotify_playlists SET local_dirty = 0, updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE user_id = ? AND id = ? AND NOT EXISTS (
                SELECT 1 FROM spotify_playlist_changes c
                WHERE c.playlist_id = spotify_playlists.id AND c.status IN (\'pending\', \'failed\')
             )',
            [$userId, $playlistId]
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function undoTracks(int $userId, ?array $payload): void
    {
        $tracks = is_array($payload['tracks'] ?? null) ? $payload['tracks'] : [];
        if ($tracks === []) {
            throw ValidationException::withFields(['id' => 'That activity cannot be undone.']);
        }
        $ids = [];
        foreach ($tracks as $track) {
            if (is_array($track)) {
                $ids[] = Mixtape::trackId((string) ($track['uri'] ?? ''));
            }
        }
        $byId = $this->metaById($userId, $ids);
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                continue;
            }
            $id = Mixtape::trackId((string) ($track['uri'] ?? ''));
            $current = $byId[$id] ?? null;
            $genres = array_key_exists('genre_tags', $track)
                ? self::genreList($track['genre_tags'])
                : ($current['genre_tags'] ?? []);
            $seasons = array_key_exists('season_tags', $track)
                ? self::stringList($track['season_tags'])
                : ($current['season_tags'] ?? []);
            $loudness = array_key_exists('loudness', $track)
                ? self::storeLoudness($track['loudness'])
                : ($current['loudness'] ?? null);
            $this->saveState($userId, $id, $genres, $seasons, $loudness, 'manual');
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param array<int, true> $openChanges
     */
    private function canUndo(string $action, ?array $payload, array $openChanges): bool
    {
        if ($payload === null) {
            return false;
        }
        if ($action === 'add_playlist') {
            return isset($openChanges[(int) ($payload['change_id'] ?? 0)]);
        }
        return is_array($payload['tracks'] ?? null) && $payload['tracks'] !== [];
    }

    /**
     * @param list<int> $changeIds
     * @return array<int, true>
     */
    private function openChangeIds(int $userId, array $changeIds): array
    {
        $changeIds = array_values(array_filter($changeIds, static fn (int $id): bool => $id > 0));
        if ($changeIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($changeIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id FROM spotify_playlist_changes
             WHERE user_id = ? AND status IN ('pending', 'failed') AND id IN ({$placeholders})",
            array_merge([$userId], $changeIds)
        );
        $open = [];
        foreach ($rows as $row) {
            $open[(int) $row['id']] = true;
        }
        return $open;
    }

    /**
     * @return array{state: string, label: string}
     */
    private function monitoring(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT status FROM spotify_sync_log WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );
        if ($row === null) {
            return ['state' => 'idle', 'label' => 'No sync recorded'];
        }
        return match ((string) $row['status']) {
            'ok' => ['state' => 'ok', 'label' => 'Monitoring'],
            'running' => ['state' => 'running', 'label' => 'Sync running'],
            default => ['state' => 'error', 'label' => 'Sync failed'],
        };
    }

    /**
     * @return list<string>
     */
    private function seasonChoices(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT season FROM spotify_season_windows WHERE user_id = ? ORDER BY season',
            [$userId]
        );
        return array_map(static fn (array $row): string => (string) $row['season'], $rows);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function ownedPlaylists(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, name FROM spotify_playlists WHERE user_id = ? AND is_owned = 1 ORDER BY name, id',
            [$userId]
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    /**
     * @return list<array{id: int, name: string, href: string}>
     */
    private function appearsIn(int $userId, string $spotifyId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT p.id, p.name
             FROM spotify_playlist_tracks pt
             JOIN spotify_tracks t ON t.id = pt.track_id
             JOIN spotify_playlists p ON p.id = pt.playlist_id
             WHERE pt.user_id = ? AND t.user_id = ? AND t.spotify_id = ?
             ORDER BY p.name, p.id',
            [$userId, $userId, $spotifyId]
        );
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'href' => '?tab=playlists&id=' . (int) $row['id'],
        ], $rows);
    }

    private function trackLabel(int $userId, string $id): string
    {
        $name = $this->db->fetchScalar(
            'SELECT name FROM spotify_tracks WHERE user_id = ? AND spotify_id = ?',
            [$userId, $id]
        );
        $label = trim((string) $name);
        return $label !== '' ? $label : $id;
    }

    /**
     * @param list<string> $uris
     * @param list<array<string, mixed>> $tracks
     * @return list<array<string, mixed>>
     */
    private function trackMeta(array $uris, array $tracks): array
    {
        $byUri = [];
        foreach ($tracks as $track) {
            if (!is_array($track) || !isset($track['uri'])) {
                continue;
            }
            $byUri[(string) $track['uri']] = $track;
        }
        $out = [];
        foreach ($uris as $uri) {
            $track = $byUri[$uri] ?? [];
            $artists = $track['artists'] ?? [];
            $out[] = [
                'uri' => $uri,
                'name' => (string) ($track['name'] ?? ''),
                'artists' => is_array($artists) ? $artists : [],
                'album' => isset($track['album']) ? (string) $track['album'] : null,
                'duration_ms' => (int) ($track['duration_ms'] ?? 0),
                'explicit' => !empty($track['explicit']),
                'image_url' => isset($track['image_url']) ? (string) $track['image_url'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function log(int $userId, string $action, string $title, string $summary, ?string $href, ?array $payload): int
    {
        if ($payload === null) {
            return (int) $this->db->fetchScalar(
                'INSERT INTO spotify_library_log (user_id, action, title, summary, href)
                 VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$userId, $action, $title, $summary, $href]
            );
        }
        return (int) $this->db->fetchScalar(
            'INSERT INTO spotify_library_log (user_id, action, title, summary, href, undo_payload)
             VALUES (?, ?, ?, ?, ?, ?::jsonb) RETURNING id',
            [$userId, $action, $title, $summary, $href, json_encode($payload, JSON_THROW_ON_ERROR)]
        );
    }

    /**
     * @param list<array{name: string, source: string}> $tags
     * @return list<array{name: string, source: string}>
     */
    private static function uniqueGenres(array $tags): array
    {
        $byName = [];
        foreach ($tags as $tag) {
            $name = $tag['name'];
            if (!isset($byName[$name]) || $tag['source'] === 'manual') {
                $byName[$name] = ['name' => $name, 'source' => $tag['source'] === 'spotify' ? 'spotify' : 'manual'];
            }
        }
        return array_values($byName);
    }

    public static function genreName(mixed $value, bool $throw): ?string
    {
        $name = mb_strtolower(trim((string) $value));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        if ($name === '') {
            if ($throw) {
                throw ValidationException::withFields(['genre' => 'Enter a genre.']);
            }
            return null;
        }
        if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .&+\'’\-]{0,39}$/u', $name) !== 1) {
            if ($throw) {
                throw ValidationException::withFields(['genre' => 'Use a short genre name.']);
            }
            return null;
        }
        return $name;
    }

    /**
     * @return list<array{name: string, source: string}>
     */
    private static function genreList(mixed $value): array
    {
        $decoded = self::decodeJson($value);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $tag) {
            if (is_string($tag)) {
                $name = self::genreName($tag, false);
                if ($name !== null) {
                    $out[] = ['name' => $name, 'source' => 'manual'];
                }
                continue;
            }
            if (!is_array($tag)) {
                continue;
            }
            $name = self::genreName($tag['name'] ?? '', false);
            if ($name === null) {
                continue;
            }
            $out[] = ['name' => $name, 'source' => ($tag['source'] ?? '') === 'spotify' ? 'spotify' : 'manual'];
        }
        return self::uniqueGenres($out);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        $decoded = self::decodeJson($value);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $tag) {
            $tag = strtolower(trim((string) $tag));
            if ($tag !== '') {
                $out[] = $tag;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @return list<float>|null
     */
    private static function storeLoudness(mixed $samples): ?array
    {
        if (!is_array($samples)) {
            return null;
        }
        $out = [];
        foreach ($samples as $sample) {
            if (is_numeric($sample)) {
                $out[] = round((float) $sample, 2);
            }
        }
        return $out === [] ? null : $out;
    }

    private static function decodeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return $decoded;
    }

    private static function sameJson(mixed $left, mixed $right): bool
    {
        return json_encode($left) === json_encode($right);
    }

    private static function libraryHref(string $id): string
    {
        return '?tab=library&track=' . rawurlencode('spotify:track:' . $id);
    }

    private static function icon(string $action): string
    {
        return match ($action) {
            'reanalyze' => 'refresh-cw',
            'set_season' => 'cloud-sun',
            'add_playlist' => 'list-music',
            'genre' => 'tag',
            default => 'music-2',
        };
    }

    private static function analysisSummary(int $genres, int $loudness, int $unavailable, int $missing): string
    {
        $parts = [];
        if ($genres > 0) {
            $parts[] = 'Artist genres updated for ' . $genres . ' ' . ($genres === 1 ? 'track' : 'tracks');
        }
        if ($loudness > 0) {
            $parts[] = 'Loudness samples stored for ' . $loudness . ' ' . ($loudness === 1 ? 'track' : 'tracks');
        }
        if ($unavailable > 0) {
            $parts[] = 'Spotify did not return audio features for ' . $unavailable . ' ' . ($unavailable === 1 ? 'track' : 'tracks');
        }
        if ($missing > 0) {
            $parts[] = $missing . ' ' . ($missing === 1 ? 'track was' : 'tracks were') . ' missing on Spotify';
        }
        if ($parts === []) {
            $parts[] = 'Spotify returned no new genres or loudness';
        }
        return implode('. ', $parts) . '.';
    }

    /**
     * @param array<string, mixed> $sync
     */
    private static function syncTitle(array $sync): string
    {
        if ((string) ($sync['status'] ?? '') === 'error') {
            return 'Sync failed';
        }
        return (string) ($sync['kind'] ?? '') === 'push' ? 'Playlist push' : 'Playlist synced';
    }

    /**
     * @param array<string, mixed> $sync
     */
    private static function syncSummary(array $sync): string
    {
        if ((string) ($sync['status'] ?? '') === 'error') {
            $error = trim((string) ($sync['error'] ?? ''));
            return $error !== '' ? $error : 'Sync failed.';
        }
        if ((string) ($sync['status'] ?? '') === 'running') {
            return 'Sync is still running.';
        }
        $counts = self::decodeJson($sync['counts'] ?? null);
        if (!is_array($counts)) {
            return 'Finished.';
        }
        $parts = [];
        foreach (['playlists', 'refreshed', 'removed', 'saved', 'history', 'top', 'pushed', 'failed', 'item_errors'] as $key) {
            if (isset($counts[$key]) && is_numeric($counts[$key])) {
                $parts[] = (int) $counts[$key] . ' ' . str_replace('_', ' ', $key);
            }
        }
        return $parts === [] ? 'Finished.' : implode(', ', $parts) . '.';
    }
}
