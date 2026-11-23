<?php

declare(strict_types=1);

namespace Dashboard\Briefs;

use Dashboard\Board\Fields;
use Dashboard\Calls\CallGuard;
use Dashboard\DB\Database;
use Dashboard\Knowledge\PageUrl;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;

/**
 * Dated briefs from any news bot. The same source, date, and title updates
 * the row, so a retry does not file a second copy.
 */
final class BriefDigestService
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{digest: array<string, mixed>}
     */
    public function publish(int $userId, array $input): array
    {
        $source = Fields::text($input['source'] ?? null, 'source', 80, true);
        $title = Fields::text($input['title'] ?? null, 'title', 200, true);
        $date = CallGuard::date($input['date'] ?? null, 'date');
        if ($date === null) {
            throw ValidationException::withFields(['date' => 'Date is required.']);
        }
        $items = Fields::headlines($input['items'] ?? null);
        $pageId = $this->page($userId, Fields::id($input['page_id'] ?? null, 'page_id'));
        $now = $this->clock->utcString($this->clock->now());
        $json = json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $id = $this->db->transaction(function () use ($userId, $source, $title, $date, $json, $pageId, $now): int {
            $existing = $this->db->fetchOne(
                'SELECT id FROM digests
                 WHERE user_id = ? AND lower(source) = lower(?) AND digest_date = ? AND lower(title) = lower(?)',
                [$userId, $source, $date, $title]
            );
            if ($existing === null) {
                return (int) $this->db->fetchScalar(
                    'INSERT INTO digests (user_id, source, title, digest_date, items, page_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?) RETURNING id',
                    [$userId, $source, $title, $date, $json, $pageId, $now, $now]
                );
            }
            $id = (int) $existing['id'];
            $this->db->execute(
                'UPDATE digests
                 SET source = ?, title = ?, items = ?::jsonb, page_id = COALESCE(?, page_id), updated_at = ?
                 WHERE id = ? AND user_id = ?',
                [$source, $title, $json, $pageId, $now, $id, $userId]
            );

            return $id;
        });

        return ['digest' => $this->card($this->row($userId, $id))];
    }

    /**
     * @return array<string, mixed>
     */
    public function widget(int $userId, string $source): array
    {
        $source = trim($source);
        $row = $this->db->fetchOne(
            "SELECT * FROM digests
             WHERE user_id = ? AND (? = '' OR lower(source) = lower(?))
             ORDER BY digest_date DESC, id DESC
             LIMIT 1",
            [$userId, $source, $source]
        );

        return [
            'metric_ids' => [],
            'source'     => $source,
            'brief'      => $row === null ? null : $this->card($row, 4),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function card(array $row, int $limit = 30): array
    {
        $pageId = $row['page_id'] !== null ? (int) $row['page_id'] : null;
        $items = $this->items($row['items'] ?? '[]');

        return [
            'id'      => (int) $row['id'],
            'source'  => (string) $row['source'],
            'title'   => (string) $row['title'],
            'date'    => (string) $row['digest_date'],
            'items'   => array_slice($items, 0, $limit),
            'page_id' => $pageId,
            'url'     => $pageId === null ? null : PageUrl::forId($pageId),
        ];
    }

    /**
     * @return list<string>
     */
    private function items(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function page(int $userId, ?int $pageId): ?int
    {
        if ($pageId === null) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT id FROM pages WHERE id = ? AND user_id = ?', [$pageId, $userId]);
        if ($row === null) {
            throw ValidationException::withFields(['page_id' => 'That page was not found.']);
        }

        return $pageId;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM digests WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw ValidationException::withFields(['digest' => 'That brief was not found.']);
        }

        return $row;
    }
}
