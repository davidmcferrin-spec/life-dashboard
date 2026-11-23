<?php

declare(strict_types=1);

namespace Dashboard\Findings;

use Dashboard\Board\Fields;
use Dashboard\Calls\CallGuard;
use Dashboard\DB\Database;
use Dashboard\Handoff\HandoffInput;
use Dashboard\Handoff\PgArray;
use Dashboard\Knowledge\PageService;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;

/**
 * A finding is one dated note from a research bot. When a vault page is named,
 * the same line is appended with the existing page patch.
 */
final class FindingService
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly PageService $pages,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{finding: array<string, mixed>}
     */
    public function log(int $userId, array $input, string $actor, bool $ai): array
    {
        $topic = (string) Fields::text($input['topic'] ?? null, 'topic', 120, true);
        $source = Fields::text($input['source_bot'] ?? null, 'source_bot', 80, false) ?? Fields::text($actor, 'source_bot', 80, true);
        $title = (string) Fields::text($input['title'] ?? null, 'title', 200, true);
        $summary = HandoffInput::note($input['summary'] ?? '', 4000, 'summary', false);
        $url = CallGuard::url($input['url'] ?? null, 'url');
        $foundAt = $this->foundAt($input['found_at'] ?? null);
        $tags = Fields::tags($input['tags'] ?? []);
        $pageId = $this->page($userId, $input, $actor, $ai, $foundAt, $title, $summary, $url);
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO research_findings (
                user_id, topic, source_bot, title, summary, url, found_at, tags, page_id, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?::text[], ?, ?) RETURNING id',
            [
                $userId, $topic, $source, $title, $summary, $url, $foundAt,
                PgArray::text($tags), $pageId, $this->clock->utcString($this->clock->now()),
            ]
        );

        return ['finding' => $this->card($this->raw($userId, $id))];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{findings: list<array<string, mixed>>}
     */
    public function list(int $userId, array $filters): array
    {
        $topic = Fields::text($filters['topic'] ?? null, 'topic', 120, false) ?? '';
        $source = Fields::text($filters['source_bot'] ?? null, 'source_bot', 80, false) ?? '';
        $tags = Fields::tags($filters['tag'] ?? null);
        $tag = $tags[0] ?? '';
        $since = '';
        if (isset($filters['since']) && $filters['since'] !== '' && $filters['since'] !== null) {
            $since = CallGuard::date($filters['since'], 'since') ?? '';
        }
        $rows = $this->db->fetchAll(
            "SELECT * FROM research_findings
             WHERE user_id = ?
               AND (? = '' OR lower(topic) = lower(?))
               AND (? = '' OR lower(source_bot) = lower(?))
               AND (? = '' OR ? = ANY (tags))
               AND (? = 0 OR found_at >= ?::timestamp)
             ORDER BY found_at DESC, id DESC
             LIMIT 100",
            [
                $userId, $topic, $topic, $source, $source, $tag, $tag,
                $since === '' ? 0 : 1,
                $since === '' ? '1970-01-01 00:00:00' : $since . ' 00:00:00',
            ]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->card($row);
        }

        return ['findings' => $out];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function page(int $userId, array $input, string $actor, bool $ai, string $foundAt, string $title, string $summary, ?string $url): ?int
    {
        $pageId = Fields::id($input['page_id'] ?? null, 'page_id');
        $pageTitle = Fields::text($input['page_title'] ?? ($input['page'] ?? null), 'page_title', 200, false);
        $append = !empty($input['append']);
        if (!$append && $pageId === null) {
            return null;
        }
        if ($append && $pageId === null && $pageTitle === null) {
            throw ValidationException::withFields(['page' => 'Name the vault page to append to.']);
        }
        if (!$append && $pageId !== null) {
            $row = $this->db->fetchOne('SELECT id FROM pages WHERE id = ? AND user_id = ?', [$pageId, $userId]);
            if ($row === null) {
                throw ValidationException::withFields(['page_id' => 'That page was not found.']);
            }

            return $pageId;
        }
        $when = $this->clock->localDateOf($this->clock->parse($foundAt));
        $line = '- ' . $when . ' — ' . $title;
        if ($summary !== '') {
            $line .= '. ' . trim($summary);
        }
        if ($url !== null) {
            $line .= ' ' . $url;
        }
        $patch = ['mode' => 'append', 'text' => $line];
        if ($pageId !== null) {
            $patch['page_id'] = $pageId;
        } else {
            $patch['title'] = $pageTitle;
        }
        $page = $this->pages->patch($userId, $patch, $ai ? 'ai' : 'user', 'log_finding', $actor);

        return (int) $page['id'];
    }

    private function foundAt(mixed $value): string
    {
        if ($value === null || $value === '') {
            return $this->clock->utcString($this->clock->now());
        }

        return CallGuard::timestamp($value, 'found_at', $this->clock);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function card(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'topic'      => (string) $row['topic'],
            'source_bot' => (string) $row['source_bot'],
            'title'      => (string) $row['title'],
            'summary'    => (string) $row['summary'],
            'url'        => $row['url'] !== null ? (string) $row['url'] : null,
            'found_at'   => (string) $row['found_at'],
            'tags'       => PgArray::readText($row['tags'] ?? '{}'),
            'page_id'    => $row['page_id'] !== null ? (int) $row['page_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM research_findings WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw ValidationException::withFields(['finding' => 'That finding was not found.']);
        }

        return $row;
    }
}
