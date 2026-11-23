<?php

declare(strict_types=1);

namespace Dashboard\Handoff;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Per-user handoff board. The sender is the caller, never a field in the body,
 * so a key cannot sign a card as another bot.
 */
final class HandoffService
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{handoff: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function create(int $userId, array $input, string $actor): array
    {
        $title = HandoffInput::title($input['title'] ?? null);
        $body = HandoffInput::note($input['body'] ?? '', 20000, 'body', false);
        $to = HandoffInput::agent($input['to_agent'] ?? null);
        $priority = HandoffInput::priority($input['priority'] ?? null);
        $due = HandoffInput::due($this->clock, $input['due_at'] ?? null);
        $tags = HandoffInput::tags($input['tags'] ?? []);
        $pages = $this->pages($userId, HandoffInput::pageIds($input['linked_page_ids'] ?? []));
        $urls = HandoffInput::urls($input['linked_urls'] ?? []);
        $now = $this->stamp();

        $id = $this->db->transaction(function () use ($userId, $title, $body, $actor, $to, $priority, $due, $tags, $pages, $urls, $now): int {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO handoffs (
                    user_id, title, body, from_agent, to_agent, status, priority, due_at,
                    tags, linked_page_ids, linked_urls, claimed_by, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, \'open\', ?, ?, ?::text[], ?::bigint[], ?::text[], NULL, ?, ?)
                 RETURNING id',
                [
                    $userId, $title, $body, $actor, $to, $priority, $due,
                    PgArray::text($tags), PgArray::ints($pages), PgArray::text($urls), $now, $now,
                ]
            );
            $this->event($userId, $id, 'created', 'open', '', $actor, $now);

            return $id;
        });

        return $this->get($userId, $id);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{handoffs: list<array<string, mixed>>}
     */
    public function list(int $userId, array $filters): array
    {
        $to = HandoffInput::agent($filters['to_agent'] ?? null);
        $from = HandoffInput::agent($filters['from_agent'] ?? null);
        $status = '';
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $status = HandoffInput::status($filters['status']);
        }
        $tag = '';
        $tags = HandoffInput::tags($filters['tag'] ?? null);
        if ($tags !== []) {
            $tag = $tags[0];
        }
        $mine = !empty($filters['mine']);
        $actor = $mine ? HandoffInput::agent($filters['actor'] ?? null) : null;
        if ($mine && $actor === null) {
            throw ValidationException::withFields(['mine' => 'This key has no agent name.']);
        }
        $openOnly = !empty($filters['open_only']) ? 1 : 0;
        $since = HandoffInput::since($this->clock, $filters['updated_since'] ?? null);
        $sinceOn = $since === null ? 0 : 1;
        if ($since === null) {
            $since = '1970-01-01 00:00:00';
        }

        $rows = $this->db->fetchAll(
            'SELECT * FROM handoffs
             WHERE user_id = ?
               AND (? = \'\' OR lower(to_agent) = lower(?))
               AND (? = \'\' OR lower(from_agent) = lower(?))
               AND (? = \'\' OR status = ?)
               AND (? = \'\' OR ? = ANY(tags))
               AND (? = \'0\' OR lower(to_agent) = lower(?) OR lower(claimed_by) = lower(?))
               AND (? = \'0\' OR status IN (\'open\', \'claimed\', \'in_progress\', \'blocked\'))
               AND (? = \'0\' OR updated_at >= ?::timestamp)
             ORDER BY updated_at DESC, id DESC
             LIMIT 200',
            [
                $userId,
                $to ?? '', $to ?? '',
                $from ?? '', $from ?? '',
                $status, $status,
                $tag, $tag,
                $mine ? '1' : '0', $actor ?? '', $actor ?? '',
                $openOnly === 1 ? '1' : '0',
                $sinceOn === 1 ? '1' : '0', $since,
            ]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->card($row);
        }

        return ['handoffs' => $out];
    }

    /**
     * @return array{handoff: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function get(int $userId, int $id): array
    {
        $row = $this->row($userId, $id);
        $events = $this->db->fetchAll(
            'SELECT id, kind, status, body, sender, created_at
             FROM handoff_events
             WHERE user_id = ? AND handoff_id = ?
             ORDER BY id',
            [$userId, $id]
        );
        $thread = [];
        foreach ($events as $event) {
            $thread[] = [
                'id'         => (int) $event['id'],
                'kind'       => (string) $event['kind'],
                'status'     => $event['status'] !== null ? (string) $event['status'] : null,
                'body'       => (string) $event['body'],
                'sender'     => (string) $event['sender'],
                'created_at' => (string) $event['created_at'],
            ];
        }

        return ['handoff' => $this->card($row), 'events' => $thread];
    }

    /**
     * @return array{handoff: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function claim(int $userId, int $id, string $actor, string $note = ''): array
    {
        $note = HandoffInput::note($note, 4000, 'note', false);

        return $this->db->transaction(function () use ($userId, $id, $actor, $note): array {
            $row = $this->row($userId, $id);
            if (in_array((string) $row['status'], HandoffInput::CLOSE, true)) {
                throw ValidationException::withFields(['status' => 'That handoff is closed.']);
            }
            $same = $this->same($row['claimed_by'] ?? null, $actor);
            $status = (string) $row['status'] === 'open' ? 'claimed' : (string) $row['status'];
            if ($same && $status === (string) $row['status']) {
                return $this->get($userId, $id);
            }
            $now = $this->stamp();
            $this->db->execute(
                'UPDATE handoffs SET claimed_by = ?, status = ?, updated_at = ? WHERE id = ? AND user_id = ?',
                [$actor, $status, $now, $id, $userId]
            );
            $this->event($userId, $id, 'claim', $status, $note, $actor, $now);

            return $this->get($userId, $id);
        });
    }

    /**
     * @return array{handoff: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function updateStatus(int $userId, int $id, string $status, string $actor, string $note = ''): array
    {
        $status = HandoffInput::status($status);
        $note = HandoffInput::note($note, 4000, 'note', false);

        return $this->db->transaction(function () use ($userId, $id, $status, $actor, $note): array {
            $row = $this->row($userId, $id);
            $now = $this->stamp();
            if ((string) $row['status'] === $status) {
                if ($note === '') {
                    throw ValidationException::withFields(['status' => 'Status is already ' . $status . '.']);
                }
                $this->db->execute(
                    'UPDATE handoffs SET updated_at = ? WHERE id = ? AND user_id = ?',
                    [$now, $id, $userId]
                );
                $this->event($userId, $id, 'comment', $status, $note, $actor, $now);

                return $this->get($userId, $id);
            }
            $this->db->execute(
                'UPDATE handoffs SET status = ?, updated_at = ? WHERE id = ? AND user_id = ?',
                [$status, $now, $id, $userId]
            );
            $this->event($userId, $id, 'status', $status, $note, $actor, $now);

            return $this->get($userId, $id);
        });
    }

    /**
     * @return array{handoff: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function comment(int $userId, int $id, string $actor, string $body): array
    {
        $body = HandoffInput::note($body, 4000, 'body', true);

        return $this->db->transaction(function () use ($userId, $id, $actor, $body): array {
            $row = $this->row($userId, $id);
            $now = $this->stamp();
            $this->db->execute(
                'UPDATE handoffs SET updated_at = ? WHERE id = ? AND user_id = ?',
                [$now, $id, $userId]
            );
            $this->event($userId, $id, 'comment', (string) $row['status'], $body, $actor, $now);

            return $this->get($userId, $id);
        });
    }

    /**
     * @return array{handoff: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function close(int $userId, int $id, string $actor, string $status = 'done', string $note = ''): array
    {
        $status = HandoffInput::closeStatus($status);
        $note = HandoffInput::note($note, 4000, 'note', false);

        return $this->db->transaction(function () use ($userId, $id, $actor, $status, $note): array {
            $row = $this->row($userId, $id);
            if ((string) $row['status'] === $status) {
                return $this->get($userId, $id);
            }
            $now = $this->stamp();
            $this->db->execute(
                'UPDATE handoffs SET status = ?, updated_at = ? WHERE id = ? AND user_id = ?',
                [$status, $now, $id, $userId]
            );
            $this->event($userId, $id, 'close', $status, $note, $actor, $now);

            return $this->get($userId, $id);
        });
    }

    /**
     * @return array{handoff: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function reassign(int $userId, int $id, string $actor, mixed $toAgent, string $note = ''): array
    {
        $to = HandoffInput::agent($toAgent);
        $note = HandoffInput::note($note, 4000, 'note', false);

        return $this->db->transaction(function () use ($userId, $id, $actor, $to, $note): array {
            $row = $this->row($userId, $id);
            if ($this->same($row['to_agent'] ?? null, (string) $to)) {
                if ($note === '') {
                    return $this->get($userId, $id);
                }
                $now = $this->stamp();
                $this->db->execute(
                    'UPDATE handoffs SET updated_at = ? WHERE id = ? AND user_id = ?',
                    [$now, $id, $userId]
                );
                $this->event($userId, $id, 'comment', (string) $row['status'], $note, $actor, $now);

                return $this->get($userId, $id);
            }
            $status = (string) $row['status'];
            $claimed = $row['claimed_by'] !== null ? (string) $row['claimed_by'] : null;
            if ($claimed !== null && !$this->same($claimed, (string) $to)) {
                $claimed = null;
                if ($status === 'claimed') {
                    $status = 'open';
                }
            }
            $now = $this->stamp();
            $this->db->execute(
                'UPDATE handoffs SET to_agent = ?, claimed_by = ?, status = ?, updated_at = ? WHERE id = ? AND user_id = ?',
                [$to, $claimed, $status, $now, $id, $userId]
            );
            $line = $note !== '' ? $note : ('Reassigned to ' . ($to ?? 'anyone') . '.');
            $this->event($userId, $id, 'reassign', $status, $line, $actor, $now);

            return $this->get($userId, $id);
        });
    }

    /**
     * Open cards whose to_agent is this key. Anyone (null) is not included.
     *
     * @return list<array<string, mixed>>
     */
    public function openForAgent(int $userId, string $agent): array
    {
        $listed = $this->list($userId, [
            'to_agent'  => $agent,
            'open_only' => true,
        ]);
        $rows = $listed['handoffs'];
        $rank = ['high' => 0, 'normal' => 1, 'low' => 2];
        usort($rows, static function (array $a, array $b) use ($rank): int {
            $by = ($rank[$a['priority']] ?? 1) <=> ($rank[$b['priority']] ?? 1);
            if ($by !== 0) {
                return $by;
            }

            return strcmp((string) ($a['due_at'] ?? '9999'), (string) ($b['due_at'] ?? '9999'));
        });

        return $rows;
    }

    /**
     * @return array{metric_ids: list<int>, open: int, counts: list<array{agent: string, count: int}>, overdue: list<array<string, mixed>>}
     */
    public function widget(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, title, to_agent, due_at
             FROM handoffs
             WHERE user_id = ? AND status IN (\'open\', \'claimed\', \'in_progress\', \'blocked\')
             ORDER BY due_at NULLS LAST, id',
            [$userId]
        );
        $counts = [];
        $overdue = [];
        $now = $this->stamp();
        foreach ($rows as $row) {
            $agent = $row['to_agent'] !== null && (string) $row['to_agent'] !== '' ? (string) $row['to_agent'] : 'Anyone';
            $counts[$agent] = ($counts[$agent] ?? 0) + 1;
            if ($row['due_at'] !== null && (string) $row['due_at'] < $now) {
                $overdue[] = [
                    'id'       => (int) $row['id'],
                    'title'    => (string) $row['title'],
                    'to_agent' => $row['to_agent'] !== null ? (string) $row['to_agent'] : null,
                    'due_at'   => (string) $row['due_at'],
                ];
            }
        }
        $countRows = [];
        foreach ($counts as $agent => $count) {
            $countRows[] = ['agent' => $agent, 'count' => $count];
        }
        usort($countRows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['agent'], $b['agent']));

        return [
            'metric_ids' => [],
            'open'       => count($rows),
            'counts'     => $countRows,
            'overdue'    => array_slice($overdue, 0, 5),
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function pages(int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $found = $this->db->fetchAll(
            'SELECT id FROM pages WHERE user_id = ? AND id = ANY(?::bigint[])',
            [$userId, PgArray::ints($ids)]
        );
        if (count($found) !== count($ids)) {
            throw ValidationException::withFields(['linked_page_ids' => 'One of those pages was not found.']);
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM handoffs WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('That handoff was not found.');
        }

        return $row;
    }

    private function event(int $userId, int $id, string $kind, ?string $status, string $body, string $sender, string $now): void
    {
        $this->db->execute(
            'INSERT INTO handoff_events (user_id, handoff_id, kind, status, body, sender, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $id, $kind, $status, $body, $sender, $now]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function card(array $row): array
    {
        $due = $row['due_at'] !== null ? (string) $row['due_at'] : null;
        $open = in_array((string) $row['status'], HandoffInput::OPEN, true);

        return [
            'id'              => (int) $row['id'],
            'title'           => (string) $row['title'],
            'body'            => (string) $row['body'],
            'from_agent'      => (string) $row['from_agent'],
            'to_agent'        => $row['to_agent'] !== null ? (string) $row['to_agent'] : null,
            'status'          => (string) $row['status'],
            'priority'        => (string) $row['priority'],
            'due_at'          => $due,
            'tags'            => PgArray::readText($row['tags'] ?? '{}'),
            'linked_page_ids' => PgArray::readInts($row['linked_page_ids'] ?? '{}'),
            'linked_urls'     => PgArray::readText($row['linked_urls'] ?? '{}'),
            'claimed_by'      => $row['claimed_by'] !== null ? (string) $row['claimed_by'] : null,
            'created_at'      => (string) $row['created_at'],
            'updated_at'      => (string) $row['updated_at'],
            'overdue'         => $open && $due !== null && $due < $this->stamp(),
        ];
    }

    private function stamp(): string
    {
        return $this->clock->utcString($this->clock->now());
    }

    private function same(mixed $left, string $right): bool
    {
        if ($left === null || $left === '') {
            return $right === '';
        }

        return mb_strtolower((string) $left) === mb_strtolower($right);
    }
}
