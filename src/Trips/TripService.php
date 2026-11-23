<?php

declare(strict_types=1);

namespace Dashboard\Trips;

use Dashboard\Board\Fields;
use Dashboard\Calls\CallGuard;
use Dashboard\DB\Database;
use Dashboard\Handoff\HandoffInput;
use Dashboard\Handoff\PgArray;
use Dashboard\Knowledge\PageService;
use Dashboard\Knowledge\PageUrl;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Trips for the bots that plan them. A vault page, a shopping wave, or a
 * handoff links by trip_id, or by a tag that matches order_wave.
 */
final class TripService
{
    public const STATUSES = ['idea', 'planning', 'booked', 'done'];

    public const FOLDER = '04 Travel';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly PageService $pages,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{trip: array<string, mixed>}
     */
    public function create(int $userId, array $input, string $actor, bool $ai): array
    {
        $fields = $this->fields($input, true, $actor);
        $now = $this->stamp();
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO trips (
                user_id, name, destination, start_date, end_date, status, owner_bot, tags, notes, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?::text[], ?, ?, ?) RETURNING id',
            [
                $userId, $fields['name'], $fields['destination'], $fields['start_date'], $fields['end_date'],
                $fields['status'], $fields['owner_bot'], PgArray::text($fields['tags']), $fields['notes'], $now, $now,
            ]
        );
        if (!empty($input['template'])) {
            $this->template($userId, $id, $actor, $ai);
        }
        $this->link($userId, $id, $input);

        return ['trip' => $this->present($userId, $id, true)];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{trip: array<string, mixed>}
     */
    public function update(int $userId, int $id, array $input, string $actor, bool $ai): array
    {
        $current = $this->raw($userId, $id);
        $fields = $this->fields($input, false, $actor, $current);
        $this->db->execute(
            'UPDATE trips
             SET name = ?, destination = ?, start_date = ?, end_date = ?, status = ?, owner_bot = ?,
                 tags = ?::text[], notes = ?, updated_at = ?
             WHERE id = ? AND user_id = ?',
            [
                $fields['name'], $fields['destination'], $fields['start_date'], $fields['end_date'],
                $fields['status'], $fields['owner_bot'], PgArray::text($fields['tags']), $fields['notes'],
                $this->stamp(), $id, $userId,
            ]
        );
        if (!empty($input['template'])) {
            $this->template($userId, $id, $actor, $ai);
        }
        $this->link($userId, $id, $input);

        return ['trip' => $this->present($userId, $id, true)];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{trips: list<array<string, mixed>>}
     */
    public function list(int $userId, array $filters): array
    {
        $status = '';
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $status = Fields::status($filters['status'], self::STATUSES);
        }
        $owner = Fields::text($filters['owner_bot'] ?? null, 'owner_bot', 80, false) ?? '';
        $tags = Fields::tags($filters['tag'] ?? null);
        $tag = $tags[0] ?? '';
        $upcoming = !empty($filters['upcoming']);
        $today = $this->clock->today();
        $rows = $this->db->fetchAll(
            "SELECT * FROM trips
             WHERE user_id = ?
               AND (? = '' OR status = ?)
               AND (? = '' OR lower(owner_bot) = lower(?))
               AND (? = '' OR ? = ANY (tags))
               AND (
                    ? = 0
                    OR (
                        status IN ('idea', 'planning', 'booked')
                        AND (end_date IS NULL OR end_date >= ?::date)
                    )
               )
             ORDER BY start_date NULLS LAST, id DESC
             LIMIT 100",
            [$userId, $status, $status, $owner, $owner, $tag, $tag, $upcoming ? 1 : 0, $today]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->card($row);
        }

        return ['trips' => $out];
    }

    /**
     * @return array{trip: array<string, mixed>}
     */
    public function get(int $userId, int $id): array
    {
        return ['trip' => $this->present($userId, $id, true)];
    }

    /**
     * @return array<string, mixed>
     */
    public function widget(int $userId): array
    {
        $listed = $this->list($userId, ['upcoming' => true]);
        $trips = array_slice($listed['trips'], 0, 5);

        return [
            'metric_ids' => [],
            'trips'      => $trips,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $current
     * @return array{name: string, destination: string, start_date: ?string, end_date: ?string, status: string, owner_bot: ?string, tags: list<string>, notes: string}
     */
    private function fields(array $input, bool $creating, string $actor, ?array $current = null): array
    {
        $name = array_key_exists('name', $input) || $creating
            ? Fields::text($input['name'] ?? null, 'name', 200, true)
            : (string) $current['name'];
        $destination = array_key_exists('destination', $input)
            ? (Fields::text($input['destination'], 'destination', 200, false) ?? '')
            : ($current === null ? '' : (string) $current['destination']);
        $start = array_key_exists('start_date', $input)
            ? CallGuard::date($input['start_date'], 'start_date')
            : ($current !== null && $current['start_date'] !== null ? (string) $current['start_date'] : null);
        $end = array_key_exists('end_date', $input)
            ? CallGuard::date($input['end_date'], 'end_date')
            : ($current !== null && $current['end_date'] !== null ? (string) $current['end_date'] : null);
        if ($start !== null && $end !== null && $end < $start) {
            throw ValidationException::withFields(['end_date' => 'The end date is before the start date.']);
        }
        $status = array_key_exists('status', $input) || $creating
            ? Fields::status($input['status'] ?? null, self::STATUSES, 'idea')
            : (string) $current['status'];
        if (array_key_exists('owner_bot', $input)) {
            $owner = Fields::text($input['owner_bot'], 'owner_bot', 80, false);
        } elseif ($creating) {
            $owner = Fields::text($actor, 'owner_bot', 80, false);
        } else {
            $owner = $current['owner_bot'] !== null ? (string) $current['owner_bot'] : null;
        }
        $tags = array_key_exists('tags', $input) || $creating
            ? Fields::tags($input['tags'] ?? [])
            : PgArray::readText($current['tags'] ?? '{}');
        $notes = array_key_exists('notes', $input) || $creating
            ? HandoffInput::note($input['notes'] ?? '', 8000, 'notes', false)
            : (string) $current['notes'];

        return [
            'name'        => (string) $name,
            'destination' => $destination,
            'start_date'  => $start,
            'end_date'    => $end,
            'status'      => $status,
            'owner_bot'   => $owner,
            'tags'        => $tags,
            'notes'       => $notes,
        ];
    }

    private function template(int $userId, int $id, string $actor, bool $ai): void
    {
        $row = $this->raw($userId, $id);
        if ($row['page_id'] !== null) {
            return;
        }
        $folderId = $this->ensureFolder($userId, $actor, $ai);
        $title = $this->pageTitle($row);
        $body = $this->templateBody($row);
        $kind = $ai ? 'ai' : 'user';
        try {
            $page = $this->pages->create($userId, [
                'title'     => $title,
                'body'      => $body,
                'folder_id' => $folderId,
            ], $kind, 'create_trip', $actor);
        } catch (ValidationException $e) {
            if (!isset($e->fields()['title'])) {
                throw $e;
            }
            $page = $this->pages->create($userId, [
                'title'     => $title . ' #' . $id,
                'body'      => $body,
                'folder_id' => $folderId,
            ], $kind, 'create_trip', $actor);
        }
        $pageId = (int) $page['id'];
        $this->db->execute(
            'UPDATE pages SET trip_id = ? WHERE id = ? AND user_id = ?',
            [$id, $pageId, $userId]
        );
        $this->db->execute(
            'UPDATE trips SET folder_id = ?, page_id = ?, updated_at = ? WHERE id = ? AND user_id = ?',
            [$folderId, $pageId, $this->stamp(), $id, $userId]
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function pageTitle(array $row): string
    {
        $title = (string) $row['name'];
        if ((string) $row['destination'] !== '') {
            $title .= ' — ' . $row['destination'];
        }
        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        }

        return $title;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function templateBody(array $row): string
    {
        $when = 'Dates are not set yet.';
        if ($row['start_date'] !== null || $row['end_date'] !== null) {
            $when = trim((string) ($row['start_date'] ?? '')) . ' to ' . trim((string) ($row['end_date'] ?? ''));
        }
        $where = (string) $row['destination'] !== '' ? (string) $row['destination'] : 'Destination not set.';

        return "# Overview\n\n{$row['name']}. {$where}\n\n# Dates\n\n{$when}\n\n# Flights\n\n# Lodging\n\n# Budget\n\n# Packing\n\n# To-dos\n";
    }

    private function ensureFolder(int $userId, string $actor, bool $ai): int
    {
        try {
            return $this->pages->folderIdByPath($userId, self::FOLDER);
        } catch (NotFoundException) {
            $created = $this->pages->createFolder($userId, self::FOLDER, null, $ai ? 'ai' : 'user', 'create_trip');

            return (int) $created['id'];
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function link(int $userId, int $id, array $input): void
    {
        $pages = Fields::ids($input['page_ids'] ?? null, 'page_ids', 20);
        $items = Fields::ids($input['shopping_item_ids'] ?? null, 'shopping_item_ids', 40);
        $handoffs = Fields::ids($input['handoff_ids'] ?? null, 'handoff_ids', 20);
        if ($pages !== []) {
            $this->assign($userId, $pages, $id, 'page_ids', 'pages');
        }
        if ($items !== []) {
            $this->assign($userId, $items, $id, 'shopping_item_ids', 'shopping_items');
        }
        if ($handoffs !== []) {
            $this->assign($userId, $handoffs, $id, 'handoff_ids', 'handoffs');
        }
    }

    /**
     * @param list<int> $ids
     */
    private function assign(int $userId, array $ids, int $tripId, string $field, string $table): void
    {
        $literal = PgArray::ints($ids);
        if ($table === 'pages') {
            $found = $this->db->fetchAll(
                'SELECT id FROM pages WHERE user_id = ? AND id = ANY(?::bigint[])',
                [$userId, $literal]
            );
        } elseif ($table === 'shopping_items') {
            $found = $this->db->fetchAll(
                'SELECT id FROM shopping_items WHERE user_id = ? AND id = ANY(?::bigint[])',
                [$userId, $literal]
            );
        } else {
            $found = $this->db->fetchAll(
                'SELECT id FROM handoffs WHERE user_id = ? AND id = ANY(?::bigint[])',
                [$userId, $literal]
            );
        }
        if (count($found) !== count($ids)) {
            throw ValidationException::withFields([$field => 'One of those rows was not found.']);
        }
        if ($table === 'pages') {
            $this->db->execute(
                'UPDATE pages SET trip_id = ? WHERE user_id = ? AND id = ANY(?::bigint[])',
                [$tripId, $userId, $literal]
            );
        } elseif ($table === 'shopping_items') {
            $this->db->execute(
                'UPDATE shopping_items SET trip_id = ? WHERE user_id = ? AND id = ANY(?::bigint[])',
                [$tripId, $userId, $literal]
            );
        } else {
            $this->db->execute(
                'UPDATE handoffs SET trip_id = ? WHERE user_id = ? AND id = ANY(?::bigint[])',
                [$tripId, $userId, $literal]
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(int $userId, int $id, bool $withLinks): array
    {
        $card = $this->card($this->raw($userId, $id));
        if (!$withLinks) {
            return $card;
        }
        $tags = $card['tags'];
        $name = mb_strtolower($card['name']);
        $card['pages'] = $this->linkedPages($userId, $id);
        $card['shopping_items'] = $this->linkedItems($userId, $id, $name, $tags);
        $card['handoffs'] = $this->linkedHandoffs($userId, $id, $tags);
        if ($card['page_id'] !== null) {
            $card['url'] = PageUrl::forId((int) $card['page_id']);
        }

        return $card;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function card(array $row): array
    {
        $pageId = $row['page_id'] !== null ? (int) $row['page_id'] : null;

        return [
            'id'          => (int) $row['id'],
            'name'        => (string) $row['name'],
            'destination' => (string) $row['destination'],
            'start_date'  => $row['start_date'] !== null ? (string) $row['start_date'] : null,
            'end_date'    => $row['end_date'] !== null ? (string) $row['end_date'] : null,
            'status'      => (string) $row['status'],
            'owner_bot'   => $row['owner_bot'] !== null ? (string) $row['owner_bot'] : null,
            'tags'        => PgArray::readText($row['tags'] ?? '{}'),
            'folder_id'   => $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
            'page_id'     => $pageId,
            'url'         => $pageId === null ? null : PageUrl::forId($pageId),
            'notes'       => (string) $row['notes'],
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linkedPages(int $userId, int $id): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, title FROM pages
             WHERE user_id = ? AND trip_id = ? AND archived_at IS NULL
             ORDER BY updated_at DESC, id DESC
             LIMIT 40',
            [$userId, $id]
        );
        $out = [];
        foreach ($rows as $row) {
            $pageId = (int) $row['id'];
            $out[] = [
                'id'    => $pageId,
                'title' => (string) $row['title'],
                'url'   => PageUrl::forId($pageId),
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $tags
     * @return list<array<string, mixed>>
     */
    private function linkedItems(int $userId, int $id, string $name, array $tags): array
    {
        $labels = $tags;
        if ($name !== '') {
            $labels[] = $name;
        }
        $rows = $this->db->fetchAll(
            "SELECT id, name, order_wave, trip_id, list_id
             FROM shopping_items
             WHERE user_id = ?
               AND (
                    trip_id = ?
                    OR (? <> '' AND lower(btrim(COALESCE(order_wave, ''))) = ANY (?::text[]))
               )
             ORDER BY id DESC
             LIMIT 40",
            [$userId, $id, $labels === [] ? '' : '1', PgArray::text($labels)]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'list_id'    => (int) $row['list_id'],
                'name'       => (string) $row['name'],
                'order_wave' => $row['order_wave'] !== null ? (string) $row['order_wave'] : null,
                'trip_id'    => $row['trip_id'] !== null ? (int) $row['trip_id'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $tags
     * @return list<array<string, mixed>>
     */
    private function linkedHandoffs(int $userId, int $id, array $tags): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, title, status, to_agent, trip_id
             FROM handoffs
             WHERE user_id = ?
               AND (trip_id = ? OR tags && ?::text[])
             ORDER BY updated_at DESC, id DESC
             LIMIT 40',
            [$userId, $id, PgArray::text($tags)]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'       => (int) $row['id'],
                'title'    => (string) $row['title'],
                'status'   => (string) $row['status'],
                'to_agent' => $row['to_agent'] !== null ? (string) $row['to_agent'] : null,
                'trip_id'  => $row['trip_id'] !== null ? (int) $row['trip_id'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM trips WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('That trip was not found.');
        }

        return $row;
    }

    private function stamp(): string
    {
        return $this->clock->utcString($this->clock->now());
    }
}
