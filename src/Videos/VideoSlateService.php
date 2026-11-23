<?php

declare(strict_types=1);

namespace Dashboard\Videos;

use Dashboard\Board\Fields;
use Dashboard\Calls\CallGuard;
use Dashboard\DB\Database;
use Dashboard\Handoff\HandoffInput;
use Dashboard\Handoff\PgArray;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;

/** YouTube slate. A row is an idea until it is published. Nothing is pulled from YouTube. */
final class VideoSlateService
{
    public const STATUSES = ['idea', 'scripted', 'voiced', 'edited', 'scheduled', 'published'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{video: array<string, mixed>}
     */
    public function add(int $userId, array $input): array
    {
        $fields = $this->fields($input, true);
        $now = $this->clock->utcString($this->clock->now());
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO video_slate (
                user_id, channel, title, status, target_date, publish_url, notes, tags, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?::text[], ?, ?) RETURNING id',
            [
                $userId, $fields['channel'], $fields['title'], $fields['status'], $fields['target_date'],
                $fields['publish_url'], $fields['notes'], PgArray::text($fields['tags']), $now, $now,
            ]
        );

        return ['video' => $this->card($this->raw($userId, $id))];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{video: array<string, mixed>}
     */
    public function update(int $userId, int $id, array $input): array
    {
        $current = $this->raw($userId, $id);
        $fields = $this->fields($input, false, $current);
        $this->db->execute(
            'UPDATE video_slate
             SET channel = ?, title = ?, status = ?, target_date = ?, publish_url = ?, notes = ?, tags = ?::text[], updated_at = ?
             WHERE id = ? AND user_id = ?',
            [
                $fields['channel'], $fields['title'], $fields['status'], $fields['target_date'],
                $fields['publish_url'], $fields['notes'], PgArray::text($fields['tags']),
                $this->clock->utcString($this->clock->now()), $id, $userId,
            ]
        );

        return ['video' => $this->card($this->raw($userId, $id))];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{videos: list<array<string, mixed>>}
     */
    public function list(int $userId, array $filters): array
    {
        $channel = Fields::text($filters['channel'] ?? null, 'channel', 120, false) ?? '';
        $status = '';
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $status = Fields::status($filters['status'], self::STATUSES);
        }
        $tags = Fields::tags($filters['tag'] ?? null);
        $tag = $tags[0] ?? '';
        $rows = $this->db->fetchAll(
            "SELECT * FROM video_slate
             WHERE user_id = ?
               AND (? = '' OR lower(channel) = lower(?))
               AND (? = '' OR status = ?)
               AND (? = '' OR ? = ANY (tags))
             ORDER BY target_date NULLS LAST, id DESC
             LIMIT 200",
            [$userId, $channel, $channel, $status, $status, $tag, $tag]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->card($row);
        }

        return ['videos' => $out];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array{channel: string, title: string, status: string, target_date: ?string, publish_url: ?string, notes: string, tags: list<string>}
     */
    private function fields(array $input, bool $creating, ?array $current = null): array
    {
        $channel = array_key_exists('channel', $input) || $creating
            ? (string) Fields::text($input['channel'] ?? null, 'channel', 120, true)
            : (string) $current['channel'];
        $title = array_key_exists('title', $input) || $creating
            ? (string) Fields::text($input['title'] ?? null, 'title', 200, true)
            : (string) $current['title'];
        $status = array_key_exists('status', $input) || $creating
            ? Fields::status($input['status'] ?? null, self::STATUSES, 'idea')
            : (string) $current['status'];
        $date = array_key_exists('target_date', $input)
            ? CallGuard::date($input['target_date'], 'target_date')
            : ($current !== null && $current['target_date'] !== null ? (string) $current['target_date'] : null);
        $url = array_key_exists('publish_url', $input)
            ? CallGuard::url($input['publish_url'], 'publish_url')
            : ($current !== null && $current['publish_url'] !== null ? (string) $current['publish_url'] : null);
        $notes = array_key_exists('notes', $input) || $creating
            ? HandoffInput::note($input['notes'] ?? '', 8000, 'notes', false)
            : (string) $current['notes'];
        $tags = array_key_exists('tags', $input) || $creating
            ? Fields::tags($input['tags'] ?? [])
            : PgArray::readText($current['tags'] ?? '{}');

        return [
            'channel'     => $channel,
            'title'       => $title,
            'status'      => $status,
            'target_date' => $date,
            'publish_url' => $url,
            'notes'       => $notes,
            'tags'        => $tags,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function card(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'channel'     => (string) $row['channel'],
            'title'       => (string) $row['title'],
            'status'      => (string) $row['status'],
            'target_date' => $row['target_date'] !== null ? (string) $row['target_date'] : null,
            'publish_url' => $row['publish_url'] !== null ? (string) $row['publish_url'] : null,
            'notes'       => (string) $row['notes'],
            'tags'        => PgArray::readText($row['tags'] ?? '{}'),
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM video_slate WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('That video was not found.');
        }

        return $row;
    }
}
