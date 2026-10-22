<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/** Local Spotify library, staged edits, prompts, and taste. Push happens in Python. */
final class SpotifyLibrary
{
    public function __construct(private readonly Database $db) {}

    public static function actorLabel(string $kind, ?string $tool): string
    {
        if ($kind !== 'ai') {
            return 'david';
        }
        return ($tool !== null && $tool !== '') ? 'ai' : 'mcp';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function playlists(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.id, p.spotify_id, p.name, p.description, p.is_public, p.collaborative, p.owner_id, p.owner_name,
                    p.snapshot_id, p.track_count, p.image_url, p.last_synced_at, p.local_dirty, p.managed_by_ai,
                    p.source_prompt_id, p.origin, p.is_owned, p.sync_error,
                    (SELECT COUNT(*) FROM spotify_playlist_changes c
                      WHERE c.playlist_id = p.id AND c.status IN (\'pending\', \'failed\')) AS open_changes
             FROM spotify_playlists p
             WHERE p.user_id = ?
             ORDER BY p.name, p.id',
            [$userId]
        );
        return array_map($this->formatPlaylist(...), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function playlist(int $userId, int $id): array
    {
        $row = $this->playlistRow($userId, $id);
        $tracks = $this->tracks($userId, $id);
        $changes = $this->changes($userId, $id);
        return [
            'playlist' => $this->formatPlaylist($row),
            'tracks' => $tracks,
            'changes' => $changes,
            'preview' => self::preview($tracks, $changes),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tracks(int $userId, int $playlistId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.spotify_id, t.name, t.artists, t.album, t.duration_ms, t.explicit, t.image_url, pt.position, pt.added_at, pt.added_by
             FROM spotify_playlist_tracks pt
             JOIN spotify_tracks t ON t.id = pt.track_id
             WHERE pt.user_id = ? AND pt.playlist_id = ?
             ORDER BY pt.position, pt.id',
            [$userId, $playlistId]
        );
        return array_map(fn (array $row): array => $this->track($row, (int) $row['position']), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function changes(int $userId, int $playlistId, bool $openOnly = true): array
    {
        $sql = 'SELECT id, op, payload, created_by, status, error, created_at
                FROM spotify_playlist_changes
                WHERE user_id = ? AND playlist_id = ?';
        if ($openOnly) {
            $sql .= " AND status IN ('pending', 'failed')";
        }
        $sql .= ' ORDER BY id';
        $rows = $this->db->fetchAll($sql, [$userId, $playlistId]);
        return array_map(function (array $row): array {
            $payload = json_decode((string) $row['payload'], true);
            return [
                'id' => (int) $row['id'],
                'op' => (string) $row['op'],
                'payload' => is_array($payload) ? $payload : [],
                'created_by' => (string) $row['created_by'],
                'status' => (string) $row['status'],
                'error' => $row['error'] !== null ? (string) $row['error'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createPlaylist(int $userId, array $body, string $by): array
    {
        $name = self::name($body['name'] ?? null);
        $description = self::description($body['description'] ?? '');
        $public = self::flag($body['public'] ?? false);
        $promptId = self::optionalId($body['source_prompt_id'] ?? null);
        $promptText = trim((string) ($body['prompt'] ?? ''));
        if ($promptText !== '') {
            $promptText = self::promptText($promptText);
        }
        $schedule = array_key_exists('refresh_schedule', $body) ? self::schedule($body['refresh_schedule']) : null;
        if ($promptId !== null) {
            $this->prompt($userId, $promptId);
        }
        $ai = ($promptId !== null || $promptText !== '' || self::flag($body['ai'] ?? false) === 1) ? 1 : 0;
        $origin = $ai === 1 ? 'ai_created' : 'created';
        $id = (int) $this->db->transaction(function (Database $db) use ($userId, $name, $description, $public, $promptId, $promptText, $schedule, $ai, $origin, $by): int {
            if ($promptText !== '' && $promptId === null) {
                $promptId = (int) $db->fetchScalar(
                    'INSERT INTO spotify_prompts (user_id, name, prompt, refresh_schedule, active)
                     VALUES (?, ?, ?, ?, 1) RETURNING id',
                    [$userId, mb_substr($name, 0, 120), $promptText, $schedule]
                );
            }
            $playlistId = (int) $db->fetchScalar(
                'INSERT INTO spotify_playlists
                    (user_id, name, description, is_public, local_dirty, managed_by_ai, source_prompt_id, origin, is_owned)
                 VALUES (?, ?, ?, ?, 1, ?, ?, ?, 1) RETURNING id',
                [$userId, $name, $description, $public, $ai, $promptId, $origin]
            );
            if ($promptId !== null) {
                $db->execute(
                    'UPDATE spotify_prompts SET target_playlist_id = ? WHERE user_id = ? AND id = ? AND target_playlist_id IS NULL',
                    [$playlistId, $userId, $promptId]
                );
            }
            $this->stage($db, $userId, $playlistId, 'create', [
                'name' => $name,
                'description' => $description,
                'public' => $public === 1,
            ], $by);
            return $playlistId;
        });
        return $this->playlist($userId, $id);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updatePlaylist(int $userId, int $id, array $body, string $by): array
    {
        $this->requireEditable($userId, $id);
        $payloadName = array_key_exists('name', $body) ? self::name($body['name']) : null;
        $payloadDesc = array_key_exists('description', $body) ? self::description($body['description']) : null;
        $payloadPublic = array_key_exists('public', $body) ? self::flag($body['public']) === 1 : null;
        if ($payloadName === null && $payloadDesc === null && $payloadPublic === null) {
            throw ValidationException::withFields(['name' => 'Send a name, description, or public flag.']);
        }
        $this->db->transaction(function (Database $db) use ($userId, $id, $payloadName, $payloadDesc, $payloadPublic, $by): void {
            if ($payloadName !== null || $payloadPublic !== null) {
                $payload = [];
                if ($payloadName !== null) {
                    $payload['name'] = $payloadName;
                }
                if ($payloadPublic !== null) {
                    $payload['public'] = $payloadPublic;
                }
                $this->stage($db, $userId, $id, 'rename', $payload, $by);
            }
            if ($payloadDesc !== null) {
                $this->stage($db, $userId, $id, 'describe', ['description' => $payloadDesc], $by);
            }
            $db->execute(
                'UPDATE spotify_playlists SET local_dirty = 1, updated_at = (now() AT TIME ZONE \'UTC\') WHERE id = ? AND user_id = ?',
                [$id, $userId]
            );
        });
        return $this->playlist($userId, $id);
    }

    /**
     * @param list<string>|mixed $uris
     * @return array<string, mixed>
     */
    public function addTracks(int $userId, int $id, mixed $uris, mixed $position, string $by, array $meta = []): array
    {
        $this->requireEditable($userId, $id);
        $list = self::uris($uris);
        $payload = ['uris' => $list];
        if ($position !== null && $position !== '') {
            $payload['position'] = self::nonNegative($position, 'position');
        }
        if ($meta !== []) {
            $payload['tracks'] = $meta;
        }
        $this->stageAndDirty($userId, $id, 'add', $payload, $by);
        return $this->playlist($userId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeTracks(int $userId, int $id, mixed $uris, string $by): array
    {
        $this->requireEditable($userId, $id);
        $this->stageAndDirty($userId, $id, 'remove', ['uris' => self::uris($uris)], $by);
        return $this->playlist($userId, $id);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function reorderTracks(int $userId, int $id, array $body, string $by): array
    {
        $this->requireEditable($userId, $id);
        $detail = $this->playlist($userId, $id);
        $base = array_map(static fn (array $track): string => (string) $track['uri'], $detail['preview']);
        $start = self::nonNegative($body['range_start'] ?? null, 'range_start');
        $before = self::nonNegative($body['insert_before'] ?? null, 'insert_before');
        $length = self::nonNegative($body['length'] ?? 1, 'length');
        if ($length < 1) {
            throw ValidationException::withFields(['length' => 'length must be at least 1.']);
        }
        if ($start + $length > count($base) || $before > count($base)) {
            throw ValidationException::withFields(['range_start' => 'That reorder is outside the playlist.']);
        }
        $this->stageAndDirty($userId, $id, 'reorder', [
            'range_start' => $start,
            'insert_before' => $before,
            'length' => $length,
            'base_uris' => $base,
        ], $by);
        return $this->playlist($userId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function replaceTracks(int $userId, int $id, mixed $uris, string $by): array
    {
        $this->requireEditable($userId, $id);
        $list = self::uris($uris, true);
        $this->stageAndDirty($userId, $id, 'replace', ['uris' => $list], $by);
        return $this->playlist($userId, $id);
    }

    public function discard(int $userId, ?int $playlistId): int
    {
        if ($playlistId !== null) {
            $this->playlistRow($userId, $playlistId);
        }
        $sql = "UPDATE spotify_playlist_changes SET status = 'discarded', updated_at = (now() AT TIME ZONE 'UTC')
                WHERE user_id = ? AND status IN ('pending', 'failed')";
        $params = [$userId];
        if ($playlistId !== null) {
            $sql .= ' AND playlist_id = ?';
            $params[] = $playlistId;
        }
        $count = $this->db->execute($sql, $params);
        $dirty = 'UPDATE spotify_playlists SET local_dirty = 0, updated_at = (now() AT TIME ZONE \'UTC\')
                  WHERE user_id = ? AND NOT EXISTS (
                    SELECT 1 FROM spotify_playlist_changes c
                    WHERE c.playlist_id = spotify_playlists.id AND c.status IN (\'pending\', \'failed\')
                  )';
        $dirtyParams = [$userId];
        if ($playlistId !== null) {
            $dirty .= ' AND id = ?';
            $dirtyParams[] = $playlistId;
        }
        $this->db->execute($dirty, $dirtyParams);
        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $userId, ?string $from, ?string $to, int $limit, Clock $clock): array
    {
        $limit = max(1, min(200, $limit));
        $params = [$userId];
        $sql = 'SELECT h.played_at, h.context_uri, t.spotify_id, t.name, t.artists, t.album, t.duration_ms, t.explicit, t.image_url
                FROM spotify_play_history h
                JOIN spotify_tracks t ON t.id = h.track_id
                WHERE h.user_id = ?';
        if ($from !== null) {
            $sql .= ' AND h.played_at >= ?::timestamp';
            $params[] = $clock->utcString(new \DateTimeImmutable(Clock::normalizeDate($from) . ' 00:00:00', $clock->timezone()));
        }
        if ($to !== null) {
            $sql .= ' AND h.played_at < ?::timestamp';
            $end = new \DateTimeImmutable(Clock::normalizeDate($to) . ' 00:00:00', $clock->timezone());
            $params[] = $clock->utcString($end->modify('+1 day'));
        }
        $sql .= ' ORDER BY h.played_at DESC LIMIT ?::integer';
        $params[] = $limit;
        $rows = $this->db->fetchAll($sql, $params);
        return array_map(function (array $row): array {
            return [
                'played_at' => (string) $row['played_at'],
                'context_uri' => $row['context_uri'] !== null ? (string) $row['context_uri'] : null,
                'track' => $this->track($row, null),
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function saved(int $userId, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->db->fetchAll(
            'SELECT s.added_at, t.spotify_id, t.name, t.artists, t.album, t.duration_ms, t.explicit, t.image_url
             FROM spotify_saved_tracks s
             JOIN spotify_tracks t ON t.id = s.track_id
             WHERE s.user_id = ?
             ORDER BY s.added_at DESC
             LIMIT ?::integer',
            [$userId, $limit]
        );
        return array_map(function (array $row): array {
            return [
                'added_at' => (string) $row['added_at'],
                'track' => $this->track($row, null),
            ];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function top(int $userId, ?string $kind, ?string $timeRange): array
    {
        $params = [$userId];
        $sql = 'SELECT kind, time_range, rank, spotify_id, name, payload, refreshed_at
                FROM spotify_top_items WHERE user_id = ?';
        if ($kind !== null) {
            if (!in_array($kind, ['artist', 'track'], true)) {
                throw ValidationException::withFields(['kind' => 'kind must be artist or track.']);
            }
            $sql .= ' AND kind = ?';
            $params[] = $kind;
        }
        if ($timeRange !== null) {
            if (!in_array($timeRange, ['short_term', 'medium_term', 'long_term'], true)) {
                throw ValidationException::withFields(['time_range' => 'time_range must be short_term, medium_term, or long_term.']);
            }
            $sql .= ' AND time_range = ?';
            $params[] = $timeRange;
        }
        $sql .= ' ORDER BY kind, time_range, rank';
        $rows = $this->db->fetchAll($sql, $params);
        return array_map(function (array $row): array {
            $payload = json_decode((string) $row['payload'], true);
            return [
                'kind' => (string) $row['kind'],
                'time_range' => (string) $row['time_range'],
                'rank' => (int) $row['rank'],
                'spotify_id' => (string) $row['spotify_id'],
                'name' => (string) $row['name'],
                'payload' => is_array($payload) ? $payload : [],
                'refreshed_at' => (string) $row['refreshed_at'],
            ];
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function taste(int $userId): array
    {
        $raw = $this->db->fetchScalar('SELECT body FROM spotify_taste_profile WHERE user_id = ?', [$userId]);
        $body = is_string($raw) ? json_decode($raw, true) : [];
        return ['profile' => is_array($body) ? $body : []];
    }

    /**
     * @param mixed $profile
     * @return array<string, mixed>
     */
    public function saveTaste(int $userId, mixed $profile): array
    {
        if (is_string($profile)) {
            $profile = ['text' => $profile];
        }
        if (!is_array($profile)) {
            throw ValidationException::withFields(['profile' => 'profile must be an object or text.']);
        }
        $json = json_encode($profile, JSON_THROW_ON_ERROR);
        if (strlen($json) > 20000) {
            throw ValidationException::withFields(['profile' => 'Taste profile is too long.']);
        }
        $this->db->execute(
            'INSERT INTO spotify_taste_profile (user_id, body) VALUES (?, ?::jsonb)
             ON CONFLICT (user_id) DO UPDATE SET body = EXCLUDED.body, updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $json]
        );
        return $this->taste($userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function prompts(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, name, prompt, target_playlist_id, refresh_schedule, last_run_at, notes, active
             FROM spotify_prompts WHERE user_id = ? ORDER BY name, id',
            [$userId]
        );
        return array_map($this->promptRow(...), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function prompt(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, name, prompt, target_playlist_id, refresh_schedule, last_run_at, notes, active
             FROM spotify_prompts WHERE user_id = ? AND id = ?',
            [$userId, $id]
        );
        if ($row === null) {
            throw new NotFoundException('Prompt not found.');
        }
        return $this->promptRow($row);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function savePrompt(int $userId, ?int $id, array $body): array
    {
        $existing = $id === null ? null : $this->prompt($userId, $id);
        $name = array_key_exists('name', $body) ? self::promptName($body['name']) : (string) ($existing['name'] ?? '');
        $text = array_key_exists('prompt', $body) ? self::promptText($body['prompt']) : (string) ($existing['prompt'] ?? '');
        if ($name === '' || $text === '') {
            throw ValidationException::withFields(['prompt' => 'A prompt needs a name and text.']);
        }
        $target = array_key_exists('target_playlist_id', $body)
            ? self::optionalId($body['target_playlist_id'])
            : ($existing['target_playlist_id'] ?? null);
        if ($target !== null) {
            self::assertPromptTarget($this->playlistRow($userId, (int) $target));
        }
        $schedule = array_key_exists('refresh_schedule', $body)
            ? self::schedule($body['refresh_schedule'])
            : ($existing['refresh_schedule'] ?? null);
        $notes = array_key_exists('notes', $body) ? self::notes($body['notes']) : ($existing['notes'] ?? null);
        $active = array_key_exists('active', $body) ? self::flag($body['active']) : (int) ($existing['active'] ?? 1);
        if ($id === null) {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO spotify_prompts (user_id, name, prompt, target_playlist_id, refresh_schedule, notes, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$userId, $name, $text, $target, $schedule, $notes, $active]
            );
        } else {
            $this->db->execute(
                'UPDATE spotify_prompts
                 SET name = ?, prompt = ?, target_playlist_id = ?, refresh_schedule = ?, notes = ?, active = ?,
                     updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE user_id = ? AND id = ?',
                [$name, $text, $target, $schedule, $notes, $active, $userId, $id]
            );
        }
        return $this->prompt($userId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function removePrompt(int $userId, int $id): array
    {
        $this->prompt($userId, $id);
        $this->db->execute('UPDATE spotify_playlists SET source_prompt_id = NULL WHERE user_id = ? AND source_prompt_id = ?', [$userId, $id]);
        $this->db->execute('DELETE FROM spotify_prompts WHERE user_id = ? AND id = ?', [$userId, $id]);
        return ['deleted' => true, 'id' => $id];
    }

    /**
     * @return array<string, mixed>
     */
    public function markPromptRun(int $userId, int $id): array
    {
        $this->prompt($userId, $id);
        $this->db->execute(
            'UPDATE spotify_prompts SET last_run_at = (now() AT TIME ZONE \'UTC\'), updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE user_id = ? AND id = ?',
            [$userId, $id]
        );
        return $this->prompt($userId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function card(int $userId, Clock $clock, ?array $now): array
    {
        $status = $this->db->fetchOne(
            'SELECT status, display_name FROM spotify_account WHERE user_id = ?',
            [$userId]
        );
        $connected = $status !== null && (string) $status['status'] === 'connected';
        $today = new \DateTimeImmutable($clock->today() . ' 00:00:00', $clock->timezone());
        $week = $today->modify('-6 days');
        $tomorrow = $today->modify('+1 day');
        return [
            'metric_ids' => [],
            'connected' => $connected,
            'display_name' => $status['display_name'] ?? null,
            'now' => $now,
            'minutes_today' => $this->minutes($userId, $clock->utcString($today), $clock->utcString($tomorrow)),
            'minutes_week' => $this->minutes($userId, $clock->utcString($week), $clock->utcString($tomorrow)),
            'top_artists' => $this->topArtists($userId, $clock->utcString($week), $clock->utcString($tomorrow)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $tracks
     * @param list<array<string, mixed>> $changes
     * @return list<array<string, mixed>>
     */
    public static function preview(array $tracks, array $changes): array
    {
        $items = $tracks;
        foreach ($changes as $change) {
            if (($change['status'] ?? '') === 'discarded' || ($change['status'] ?? '') === 'pushed') {
                continue;
            }
            $payload = is_array($change['payload'] ?? null) ? $change['payload'] : [];
            $op = (string) ($change['op'] ?? '');
            if ($op === 'add') {
                $known = [];
                foreach ($payload['tracks'] ?? [] as $meta) {
                    if (is_array($meta) && isset($meta['uri'])) {
                        $known[(string) $meta['uri']] = $meta;
                    }
                }
                $insert = [];
                foreach ($payload['uris'] ?? [] as $uri) {
                    $meta = $known[(string) $uri] ?? ['uri' => $uri, 'name' => $uri];
                    $insert[] = self::previewTrack($meta);
                }
                $at = isset($payload['position']) ? (int) $payload['position'] : count($items);
                $at = max(0, min($at, count($items)));
                array_splice($items, $at, 0, $insert);
            } elseif ($op === 'remove') {
                $drop = array_fill_keys(array_map('strval', $payload['uris'] ?? []), true);
                $items = array_values(array_filter($items, static fn (array $track): bool => !isset($drop[(string) ($track['uri'] ?? '')])));
            } elseif ($op === 'replace') {
                $items = [];
                foreach ($payload['uris'] ?? [] as $uri) {
                    $items[] = self::previewTrack(['uri' => $uri, 'name' => $uri]);
                }
            } elseif ($op === 'reorder') {
                $start = (int) ($payload['range_start'] ?? 0);
                $length = (int) ($payload['length'] ?? 1);
                $before = (int) ($payload['insert_before'] ?? 0);
                if ($start < 0 || $length < 1 || $start + $length > count($items)) {
                    continue;
                }
                $moved = array_splice($items, $start, $length);
                if ($before > $start) {
                    $before -= $length;
                }
                $before = max(0, min($before, count($items)));
                array_splice($items, $before, 0, $moved);
            }
        }
        foreach ($items as $i => $track) {
            $items[$i]['position'] = $i;
        }
        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPlaylist(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'spotify_id' => $row['spotify_id'] !== null ? (string) $row['spotify_id'] : null,
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'public' => (int) ($row['is_public'] ?? 0) === 1,
            'collaborative' => (int) ($row['collaborative'] ?? 0) === 1,
            'owner_id' => $row['owner_id'] !== null ? (string) $row['owner_id'] : null,
            'owner_name' => $row['owner_name'] !== null ? (string) $row['owner_name'] : null,
            'snapshot_id' => $row['snapshot_id'] !== null ? (string) $row['snapshot_id'] : null,
            'track_count' => (int) ($row['track_count'] ?? 0),
            'image_url' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
            'last_synced_at' => $row['last_synced_at'] !== null ? (string) $row['last_synced_at'] : null,
            'local_dirty' => (int) ($row['local_dirty'] ?? 0) === 1,
            'managed_by_ai' => (int) ($row['managed_by_ai'] ?? 0) === 1,
            'source_prompt_id' => $row['source_prompt_id'] !== null ? (int) $row['source_prompt_id'] : null,
            'origin' => (string) ($row['origin'] ?? 'synced'),
            'is_owned' => self::canEdit($row),
            'sync_error' => isset($row['sync_error']) && $row['sync_error'] !== null && $row['sync_error'] !== ''
                ? (string) $row['sync_error']
                : null,
            'open_changes' => (int) ($row['open_changes'] ?? 0),
        ];
    }

    public function assertEditable(int $userId, int $id): void
    {
        $this->requireEditable($userId, $id);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function canEdit(array $row): bool
    {
        $remote = $row['spotify_id'] ?? null;
        if ($remote === null || $remote === '') {
            return true;
        }
        return (int) ($row['is_owned'] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function readOnlyMessage(array $row): string
    {
        $owner = trim((string) ($row['owner_name'] ?? ''));
        if ($owner !== '') {
            return 'This playlist is read-only (owned by ' . $owner . '). Only playlists you own can be edited or pushed.';
        }
        return 'This playlist is read-only. Only playlists you own can be edited or pushed.';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function assertPromptTarget(array $row): void
    {
        if ((string) ($row['origin'] ?? '') !== 'ai_created' || !self::canEdit($row)) {
            throw ValidationException::withFields([
                'target_playlist_id' => 'Prompts attach only to AI playlists created here.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireEditable(int $userId, int $id): array
    {
        $row = $this->playlistRow($userId, $id);
        if (!self::canEdit($row)) {
            throw ValidationException::withFields(['playlist' => self::readOnlyMessage($row)]);
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function playlistRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT p.id, p.spotify_id, p.name, p.description, p.is_public, p.collaborative, p.owner_id, p.owner_name,
                    p.snapshot_id, p.track_count, p.image_url, p.last_synced_at, p.local_dirty, p.managed_by_ai, p.source_prompt_id,
                    p.origin, p.is_owned, p.sync_error,
                    (SELECT COUNT(*) FROM spotify_playlist_changes c
                      WHERE c.playlist_id = p.id AND c.status IN (\'pending\', \'failed\')) AS open_changes
             FROM spotify_playlists p WHERE p.user_id = ? AND p.id = ?',
            [$userId, $id]
        );
        if ($row === null) {
            throw new NotFoundException('Playlist not found.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function track(array $row, ?int $position): array
    {
        $artists = json_decode((string) ($row['artists'] ?? '[]'), true);
        $names = [];
        if (is_array($artists)) {
            foreach ($artists as $artist) {
                if (is_array($artist) && isset($artist['name'])) {
                    $names[] = (string) $artist['name'];
                } elseif (is_string($artist)) {
                    $names[] = $artist;
                }
            }
        }
        $spotifyId = (string) ($row['spotify_id'] ?? '');
        return [
            'id' => $spotifyId,
            'uri' => 'spotify:track:' . $spotifyId,
            'name' => (string) ($row['name'] ?? ''),
            'artists' => $names,
            'album' => $row['album'] !== null ? (string) $row['album'] : null,
            'duration_ms' => (int) ($row['duration_ms'] ?? 0),
            'explicit' => (int) ($row['explicit'] ?? 0) === 1,
            'image_url' => isset($row['image_url']) && $row['image_url'] !== null ? (string) $row['image_url'] : null,
            'position' => $position,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function previewTrack(array $meta): array
    {
        $uri = (string) ($meta['uri'] ?? '');
        $artists = $meta['artists'] ?? [];
        if (!is_array($artists)) {
            $artists = [];
        }
        return [
            'id' => str_starts_with($uri, 'spotify:track:') ? substr($uri, 14) : '',
            'uri' => $uri,
            'name' => (string) ($meta['name'] ?? $uri),
            'artists' => array_values(array_map('strval', $artists)),
            'album' => isset($meta['album']) ? (string) $meta['album'] : null,
            'duration_ms' => (int) ($meta['duration_ms'] ?? 0),
            'explicit' => !empty($meta['explicit']),
            'image_url' => isset($meta['image_url']) ? (string) $meta['image_url'] : null,
            'position' => null,
            'pending' => true,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function promptRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'prompt' => (string) $row['prompt'],
            'target_playlist_id' => $row['target_playlist_id'] !== null ? (int) $row['target_playlist_id'] : null,
            'refresh_schedule' => $row['refresh_schedule'] !== null ? (string) $row['refresh_schedule'] : null,
            'last_run_at' => $row['last_run_at'] !== null ? (string) $row['last_run_at'] : null,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'active' => (int) $row['active'] === 1,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stageAndDirty(int $userId, int $playlistId, string $op, array $payload, string $by): void
    {
        $this->db->transaction(function (Database $db) use ($userId, $playlistId, $op, $payload, $by): void {
            $this->stage($db, $userId, $playlistId, $op, $payload, $by);
            $db->execute(
                'UPDATE spotify_playlists SET local_dirty = 1, updated_at = (now() AT TIME ZONE \'UTC\') WHERE id = ? AND user_id = ?',
                [$playlistId, $userId]
            );
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stage(Database $db, int $userId, int $playlistId, string $op, array $payload, string $by): void
    {
        $db->execute(
            'INSERT INTO spotify_playlist_changes (user_id, playlist_id, op, payload, created_by)
             VALUES (?, ?, ?, ?::jsonb, ?)',
            [$userId, $playlistId, $op, json_encode($payload, JSON_THROW_ON_ERROR), $by]
        );
    }

    private function minutes(int $userId, string $from, string $to): int
    {
        $ms = (int) $this->db->fetchScalar(
            'SELECT COALESCE(SUM(t.duration_ms), 0)
             FROM spotify_play_history h
             JOIN spotify_tracks t ON t.id = h.track_id
             WHERE h.user_id = ? AND h.played_at >= ?::timestamp AND h.played_at < ?::timestamp',
            [$userId, $from, $to]
        );
        return (int) floor($ms / 60000);
    }

    /**
     * @return list<array{name: string, plays: int}>
     */
    private function topArtists(int $userId, string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.artists
             FROM spotify_play_history h
             JOIN spotify_tracks t ON t.id = h.track_id
             WHERE h.user_id = ? AND h.played_at >= ?::timestamp AND h.played_at < ?::timestamp',
            [$userId, $from, $to]
        );
        $counts = [];
        foreach ($rows as $row) {
            $artists = json_decode((string) $row['artists'], true);
            if (!is_array($artists)) {
                continue;
            }
            $seen = [];
            foreach ($artists as $artist) {
                $name = is_array($artist) ? (string) ($artist['name'] ?? '') : (string) $artist;
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, 5, true) as $name => $plays) {
            $out[] = ['name' => (string) $name, 'plays' => $plays];
        }
        return $out;
    }

    private static function name(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '' || strlen($name) > 100) {
            throw ValidationException::withFields(['name' => 'Playlist name must be 1 to 100 characters.']);
        }
        return $name;
    }

    private static function description(mixed $value): string
    {
        $text = trim((string) $value);
        if (strlen($text) > 300) {
            throw ValidationException::withFields(['description' => 'Description must be 300 characters or fewer.']);
        }
        return $text;
    }

    private static function flag(mixed $value): int
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return 1;
        }
        return 0;
    }

    private static function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 1) {
            throw ValidationException::withFields(['id' => 'Expected an id.']);
        }
        return (int) $value;
    }

    /**
     * @return list<string>
     */
    public static function uris(mixed $value, bool $allowEmpty = false): array
    {
        if (!is_array($value)) {
            throw ValidationException::withFields(['uris' => 'uris must be a list of spotify:track URIs.']);
        }
        $out = [];
        foreach ($value as $uri) {
            $uri = trim((string) $uri);
            if (preg_match('/^spotify:track:[A-Za-z0-9]+$/', $uri) !== 1) {
                throw ValidationException::withFields(['uris' => 'Each track must be a spotify:track URI.']);
            }
            $out[] = $uri;
        }
        if (!$allowEmpty && $out === []) {
            throw ValidationException::withFields(['uris' => 'Add at least one track.']);
        }
        if (count($out) > 500) {
            throw ValidationException::withFields(['uris' => 'At most 500 tracks in one change.']);
        }
        return $out;
    }

    private static function nonNegative(mixed $value, string $field): int
    {
        if (!is_numeric($value) || (int) $value < 0 || (string) (int) $value !== (string) (int) $value) {
            if (is_int($value) && $value >= 0) {
                return $value;
            }
            if (is_numeric($value) && (int) $value >= 0) {
                return (int) $value;
            }
            throw ValidationException::withFields([$field => $field . ' must be zero or greater.']);
        }
        return (int) $value;
    }

    private static function promptName(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '' || strlen($name) > 120) {
            throw ValidationException::withFields(['name' => 'Prompt name must be 1 to 120 characters.']);
        }
        return $name;
    }

    private static function promptText(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > 8000) {
            throw ValidationException::withFields(['prompt' => 'Prompt text must be 1 to 8000 characters.']);
        }
        return $text;
    }

    private static function schedule(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if (strlen($text) > 32) {
            throw ValidationException::withFields(['refresh_schedule' => 'Schedule must be 32 characters or fewer.']);
        }
        return $text;
    }

    private static function notes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if (strlen($text) > 2000) {
            throw ValidationException::withFields(['notes' => 'Notes must be 2000 characters or fewer.']);
        }
        return $text === '' ? null : $text;
    }
}
