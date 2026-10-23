<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;

/** Stored track notes, season windows, arc order, and mixtape lock rows. */
final class MixtapeLibrary
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param list<array<string, mixed>> $tracks
     * @return array{tracks: list<array<string, mixed>>}
     */
    public function saveMany(int $userId, array $tracks): array
    {
        if ($tracks === []) {
            throw ValidationException::withFields(['tracks' => 'Send at least one track.']);
        }
        if (count($tracks) > 100) {
            throw ValidationException::withFields(['tracks' => 'At most 100 tracks at once.']);
        }
        $saved = [];
        foreach ($tracks as $i => $track) {
            if (!is_array($track)) {
                throw ValidationException::withFields(["tracks.{$i}" => 'Each track must be an object.']);
            }
            $saved[] = $this->saveOne($userId, $track);
        }
        return ['tracks' => $saved];
    }

    /**
     * @param list<string> $uris
     * @return array{tracks: list<array<string, mixed>>}
     */
    public function get(int $userId, array $uris): array
    {
        $ids = [];
        foreach ($uris as $uri) {
            $ids[] = Mixtape::trackId((string) $uri);
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return ['tracks' => []];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            "SELECT spotify_track_id, bpm, musical_key, camelot, energy, mood_tags, season_tags, source, updated_at
             FROM spotify_track_meta WHERE user_id = ? AND spotify_track_id IN ({$placeholders})",
            array_merge([$userId], $ids)
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['spotify_track_id']] = $this->metaRow($row);
        }
        $out = [];
        foreach ($ids as $id) {
            $out[] = $byId[$id] ?? [
                'spotify_track_id' => $id,
                'uri' => 'spotify:track:' . $id,
                'bpm' => null,
                'musical_key' => null,
                'camelot' => null,
                'energy' => null,
                'mood_tags' => [],
                'season_tags' => [],
                'source' => null,
                'updated_at' => null,
            ];
        }
        return ['tracks' => $out];
    }

    /**
     * @return array{seasons: list<array<string, mixed>>}
     */
    public function seasons(int $userId): array
    {
        $this->ensureDefaults($userId);
        $rows = $this->db->fetchAll(
            'SELECT season, start_anchor, start_month, start_day, end_month, end_day
             FROM spotify_season_windows WHERE user_id = ? ORDER BY season',
            [$userId]
        );
        return ['seasons' => array_map($this->seasonRow(...), $rows)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function saveSeason(int $userId, array $body): array
    {
        $this->ensureDefaults($userId);
        $tags = Mixtape::tags($body['season'] ?? '', 'season');
        if (count($tags) !== 1) {
            throw ValidationException::withFields(['season' => 'Name one season, such as christmas.']);
        }
        $season = $tags[0];
        $anchor = strtolower(trim((string) ($body['start_anchor'] ?? '')));
        if (!in_array($anchor, ['', 'thanksgiving'], true)) {
            throw ValidationException::withFields(['start_anchor' => 'Start is a date or Thanksgiving.']);
        }
        $startMonth = $anchor === 'thanksgiving' ? null : self::monthDay($body['start_month'] ?? null, 'start_month', true);
        $startDay = $anchor === 'thanksgiving' ? null : self::monthDay($body['start_day'] ?? null, 'start_day', false);
        $endMonth = self::monthDay($body['end_month'] ?? null, 'end_month', true);
        $endDay = self::monthDay($body['end_day'] ?? null, 'end_day', false);
        if ($anchor !== 'thanksgiving' && ($startMonth === null || $startDay === null)) {
            throw ValidationException::withFields(['start_month' => 'Set a start month and day, or start on Thanksgiving.']);
        }
        if ($endMonth === null || $endDay === null) {
            throw ValidationException::withFields(['end_month' => 'Set an end month and day.']);
        }
        $this->db->execute(
            'INSERT INTO spotify_season_windows
                (user_id, season, start_anchor, start_month, start_day, end_month, end_day)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id, season) DO UPDATE SET
                start_anchor = EXCLUDED.start_anchor,
                start_month = EXCLUDED.start_month,
                start_day = EXCLUDED.start_day,
                end_month = EXCLUDED.end_month,
                end_day = EXCLUDED.end_day,
                updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $season, $anchor, $startMonth, $startDay, $endMonth, $endDay]
        );
        return $this->seasonRow([
            'season' => $season,
            'start_anchor' => $anchor,
            'start_month' => $startMonth,
            'start_day' => $startDay,
            'end_month' => $endMonth,
            'end_day' => $endDay,
        ]);
    }

    /**
     * @return array{seasons: list<array<string, mixed>>, on: string}
     */
    public function active(int $userId, Clock $clock): array
    {
        $today = new \DateTimeImmutable($clock->today() . ' 00:00:00');
        $active = [];
        foreach ($this->seasons($userId)['seasons'] as $season) {
            if (Mixtape::inWindow($season, $today)) {
                $active[] = $season;
            }
        }
        return ['seasons' => $active, 'on' => $clock->today()];
    }

    /**
     * @param list<string> $uris
     */
    public function assertAllowed(int $userId, int $playlistId, array $uris, Clock $clock): void
    {
        if ($uris === []) {
            return;
        }
        $allowed = $this->promptAllows($userId, $playlistId);
        $today = new \DateTimeImmutable($clock->today() . ' 00:00:00');
        $windows = [];
        foreach ($this->seasons($userId)['seasons'] as $season) {
            $windows[$season['season']] = $season;
        }
        $meta = $this->get($userId, $uris)['tracks'];
        $blocked = [];
        foreach ($meta as $track) {
            foreach ($track['season_tags'] as $tag) {
                if (!isset($windows[$tag]) || in_array($tag, $allowed, true)) {
                    continue;
                }
                if (!Mixtape::inWindow($windows[$tag], $today)) {
                    $blocked[] = $track['uri'] . ' is tagged ' . $tag . ', which is outside its window.';
                }
            }
        }
        if ($blocked !== []) {
            throw ValidationException::withFields([
                'uris' => implode(' ', $blocked) . ' The prompt can allow that season.',
            ]);
        }
    }

    public function assertPending(int $userId, ?int $playlistId, Clock $clock): void
    {
        $sql = "SELECT playlist_id, payload FROM spotify_playlist_changes
                WHERE user_id = ? AND status = 'pending' AND op IN ('add', 'replace')";
        $params = [$userId];
        if ($playlistId !== null) {
            $sql .= ' AND playlist_id = ?';
            $params[] = $playlistId;
        }
        $rows = $this->db->fetchAll($sql, $params);
        $byPlaylist = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) {
                continue;
            }
            foreach ($payload['uris'] ?? [] as $uri) {
                if (is_string($uri)) {
                    $byPlaylist[(int) $row['playlist_id']][] = $uri;
                }
            }
        }
        foreach ($byPlaylist as $id => $uris) {
            $this->assertAllowed($userId, $id, $uris, $clock);
        }
    }

    /**
     * @param list<string> $uris
     * @param array<string, mixed>|null $arc
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function plan(int $userId, array $uris, ?int $promptId, ?array $arc, array $options = []): array
    {
        $chosen = Mixtape::ARC;
        if ($promptId !== null) {
            $row = $this->db->fetchOne(
                'SELECT arc FROM spotify_prompts WHERE user_id = ? AND id = ?',
                [$userId, $promptId]
            );
            if ($row === null) {
                throw ValidationException::withFields(['prompt_id' => 'Prompt not found.']);
            }
            $stored = json_decode((string) $row['arc'], true);
            if (is_array($stored)) {
                $chosen = Mixtape::arc($stored);
            }
        }
        if ($arc !== null) {
            $chosen = Mixtape::arc($arc);
        }
        $meta = $this->get($userId, $uris)['tracks'];
        $byId = [];
        foreach ($meta as $track) {
            $byId[$track['spotify_track_id']] = $track;
        }
        $tracks = [];
        foreach ($uris as $uri) {
            $id = Mixtape::trackId((string) $uri);
            $row = $byId[$id] ?? ['uri' => 'spotify:track:' . $id, 'bpm' => null, 'camelot' => null, 'energy' => null];
            $tracks[] = $row;
        }
        $durations = is_array($options['durations'] ?? null) ? $options['durations'] : [];
        foreach ($tracks as $i => $row) {
            $uri = (string) ($row['uri'] ?? '');
            if (isset($durations[$uri]) && is_numeric($durations[$uri])) {
                $tracks[$i]['duration_ms'] = max(0, (int) $durations[$uri]);
            }
        }
        $plan = Mixtape::plan($tracks, $chosen, [
            'locks' => is_array($options['locks'] ?? null) ? $options['locks'] : [],
            'key' => !array_key_exists('key', $options) || $options['key'] !== false,
            'target_ms' => isset($options['target_ms']) && is_numeric($options['target_ms']) ? (int) $options['target_ms'] : null,
            'avoid' => is_array($options['avoid'] ?? null) ? $options['avoid'] : [],
            'slots' => isset($options['slots']) && is_numeric($options['slots']) ? (int) $options['slots'] : 0,
        ]);
        $plan['prompt_id'] = $promptId;
        return $plan;
    }

    /**
     * @return array{locks: list<array<string, mixed>>}
     */
    public function locks(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT spotify_track_id, position FROM spotify_mixtape_locks WHERE user_id = ? ORDER BY position, id',
            [$userId]
        );
        $locks = [];
        foreach ($rows as $row) {
            $id = (string) $row['spotify_track_id'];
            $locks[] = [
                'spotify_track_id' => $id,
                'uri' => 'spotify:track:' . $id,
                'position' => (int) $row['position'],
            ];
        }
        return ['locks' => $locks];
    }

    /**
     * @param list<mixed> $locks
     * @return array{saved: bool, locks: list<array<string, mixed>>}
     */
    public function saveLocks(int $userId, array $locks): array
    {
        $rows = [];
        $seenUri = [];
        $seenPos = [];
        foreach ($locks as $lock) {
            if (!is_array($lock)) {
                throw ValidationException::withFields(['locks' => 'Each lock must be an object.']);
            }
            $uri = trim((string) ($lock['uri'] ?? ''));
            if ($uri === '') {
                continue;
            }
            $id = Mixtape::trackId($uri);
            if (!is_numeric($lock['position'] ?? null)) {
                throw ValidationException::withFields(['locks' => 'Each lock needs a position.']);
            }
            $pos = (int) $lock['position'];
            if ($pos < 0 || $pos > 499) {
                throw ValidationException::withFields(['locks' => 'Lock position must be 0 to 499.']);
            }
            if (isset($seenUri[$id]) || isset($seenPos[$pos])) {
                throw ValidationException::withFields(['locks' => 'Each track and position can be locked once.']);
            }
            $seenUri[$id] = true;
            $seenPos[$pos] = true;
            $rows[] = [$id, $pos];
        }
        $this->db->transaction(function (Database $db) use ($userId, $rows): void {
            $db->execute('DELETE FROM spotify_mixtape_locks WHERE user_id = ?', [$userId]);
            foreach ($rows as [$id, $pos]) {
                $db->execute(
                    'INSERT INTO spotify_mixtape_locks (user_id, spotify_track_id, position) VALUES (?, ?, ?)',
                    [$userId, $id, $pos]
                );
            }
        });
        return ['saved' => true] + $this->locks($userId);
    }

    /**
     * @param list<array<string, mixed>> $preview
     * @return list<array<string, mixed>>
     */
    public function annotate(int $userId, array $preview): array
    {
        $uris = [];
        foreach ($preview as $track) {
            if (isset($track['uri']) && is_string($track['uri']) && $track['uri'] !== '') {
                $uris[] = $track['uri'];
            }
        }
        if ($uris === []) {
            return $preview;
        }
        $byUri = [];
        foreach ($this->get($userId, $uris)['tracks'] as $meta) {
            $byUri[$meta['uri']] = $meta;
        }
        foreach ($preview as $i => $track) {
            $meta = $byUri[$track['uri'] ?? ''] ?? null;
            $preview[$i]['meta'] = $meta;
        }
        return $preview;
    }

    /**
     * @param array<string, mixed> $track
     * @return array<string, mixed>
     */
    private function saveOne(int $userId, array $track): array
    {
        $raw = (string) ($track['uri'] ?? $track['spotify_track_id'] ?? $track['id'] ?? '');
        $id = Mixtape::trackId($raw);
        $bpm = self::bpm($track['bpm'] ?? null);
        $musical = self::blank($track['musical_key'] ?? $track['key'] ?? null, 'musical_key', 32);
        $camelot = Mixtape::camelot(
            isset($track['camelot']) ? (string) $track['camelot'] : null,
            $musical
        );
        if ($camelot === null && trim((string) ($track['camelot'] ?? '')) !== '') {
            throw ValidationException::withFields(['camelot' => 'Camelot codes look like 8A or 11B.']);
        }
        $energy = self::energy($track['energy'] ?? null);
        $mood = Mixtape::tags($track['mood_tags'] ?? [], 'mood_tags');
        $seasons = Mixtape::tags($track['season_tags'] ?? [], 'season_tags');
        $source = strtolower(trim((string) ($track['source'] ?? 'estimated')));
        if (!in_array($source, ['manual', 'estimated'], true)) {
            throw ValidationException::withFields(['source' => 'Source is manual or estimated.']);
        }
        $this->db->execute(
            'INSERT INTO spotify_track_meta
                (user_id, spotify_track_id, bpm, musical_key, camelot, energy, mood_tags, season_tags, source)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?)
             ON CONFLICT (user_id, spotify_track_id) DO UPDATE SET
                bpm = EXCLUDED.bpm,
                musical_key = EXCLUDED.musical_key,
                camelot = EXCLUDED.camelot,
                energy = EXCLUDED.energy,
                mood_tags = EXCLUDED.mood_tags,
                season_tags = EXCLUDED.season_tags,
                source = EXCLUDED.source,
                updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $id, $bpm, $musical, $camelot, $energy, json_encode($mood), json_encode($seasons), $source]
        );
        return $this->get($userId, ['spotify:track:' . $id])['tracks'][0];
    }

    /**
     * @return list<string>
     */
    private function promptAllows(int $userId, int $playlistId): array
    {
        $row = $this->db->fetchOne(
            'SELECT pr.season_tags, pr.allow_seasons
             FROM spotify_playlists p
             JOIN spotify_prompts pr ON pr.id = p.source_prompt_id AND pr.user_id = p.user_id
             WHERE p.user_id = ? AND p.id = ?',
            [$userId, $playlistId]
        );
        if ($row === null) {
            return [];
        }
        $tags = json_decode((string) $row['season_tags'], true);
        $allow = json_decode((string) $row['allow_seasons'], true);
        return array_values(array_unique(array_merge(
            is_array($tags) ? Mixtape::tags($tags, 'season_tags') : [],
            is_array($allow) ? Mixtape::tags($allow, 'allow_seasons') : [],
        )));
    }

    private function ensureDefaults(int $userId): void
    {
        $count = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM spotify_season_windows WHERE user_id = ?',
            [$userId]
        );
        if ($count > 0) {
            return;
        }
        foreach (['christmas', 'holiday'] as $season) {
            $this->db->execute(
                'INSERT INTO spotify_season_windows (user_id, season, start_anchor, end_month, end_day)
                 VALUES (?, ?, \'thanksgiving\', 1, 6)',
                [$userId, $season]
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function metaRow(array $row): array
    {
        $id = (string) $row['spotify_track_id'];
        return [
            'spotify_track_id' => $id,
            'uri' => 'spotify:track:' . $id,
            'bpm' => $row['bpm'] !== null ? (float) $row['bpm'] : null,
            'musical_key' => $row['musical_key'] !== null ? (string) $row['musical_key'] : null,
            'camelot' => $row['camelot'] !== null ? (string) $row['camelot'] : null,
            'energy' => $row['energy'] !== null ? (int) $row['energy'] : null,
            'mood_tags' => self::jsonList($row['mood_tags'] ?? '[]'),
            'season_tags' => self::jsonList($row['season_tags'] ?? '[]'),
            'source' => (string) $row['source'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function seasonRow(array $row): array
    {
        return [
            'season' => (string) $row['season'],
            'start_anchor' => (string) ($row['start_anchor'] ?? ''),
            'start_month' => $row['start_month'] !== null && $row['start_month'] !== '' ? (int) $row['start_month'] : null,
            'start_day' => $row['start_day'] !== null && $row['start_day'] !== '' ? (int) $row['start_day'] : null,
            'end_month' => (int) $row['end_month'],
            'end_day' => (int) $row['end_day'],
        ];
    }

    /**
     * @return list<string>
     */
    private static function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    private static function bpm(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (float) $value <= 0 || (float) $value > 400) {
            throw ValidationException::withFields(['bpm' => 'BPM must be between 1 and 400.']);
        }
        return round((float) $value, 2);
    }

    private static function energy(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 1 || (int) $value > 10) {
            throw ValidationException::withFields(['energy' => 'Energy must be 1 to 10.']);
        }
        return (int) $value;
    }

    private static function blank(mixed $value, string $field, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withFields([$field => $field . ' is too long.']);
        }
        return $text;
    }

    private static function monthDay(mixed $value, string $field, bool $month): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $max = $month ? 12 : 31;
        if (!is_numeric($value) || (int) $value < 1 || (int) $value > $max) {
            throw ValidationException::withFields([$field => $field . ' is out of range.']);
        }
        return (int) $value;
    }
}
