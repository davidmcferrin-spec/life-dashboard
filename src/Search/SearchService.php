<?php

declare(strict_types=1);

namespace Dashboard\Search;

use Dashboard\DB\Database;
use Dashboard\Support\HttpClient;

/**
 * Literal search across every stored dataset, plus an optional similar pass.
 * A keyword hit is returned only when the query letters are in the text.
 */
final class SearchService
{
    private readonly SearchIndex $index;

    public function __construct(
        private readonly Database $db,
        ?HttpClient $http = null,
    ) {
        $this->index = new SearchIndex($db, $http);
    }

    /**
     * @param list<string>|null $include
     * @param list<string>|null $exclude
     * @return array{q: string, datasets: list<string>, available: list<string>, truncated: bool, hits: list<array<string, mixed>>}
     */
    public function search(int $userId, string $q, ?array $include = null, ?array $exclude = null): array
    {
        $words = SearchQuery::words($q);
        $datasets = SearchQuery::datasets($include, $exclude);
        $this->index->sync($userId);

        $params = [$userId, implode(',', $datasets)];
        foreach (array_pad($words, SearchQuery::MAX_WORDS, '') as $word) {
            $params[] = $word;
            $params[] = $word;
        }
        $rows = $this->db->fetchAll(
            "SELECT dataset, source_id, title, body, url
             FROM search_chunks
             WHERE user_id = ?
               AND dataset = ANY (string_to_array(?, ','))
               AND (? = '' OR strpos(lower(title || ' ' || body), ?) > 0)
               AND (? = '' OR strpos(lower(title || ' ' || body), ?) > 0)
               AND (? = '' OR strpos(lower(title || ' ' || body), ?) > 0)
               AND (? = '' OR strpos(lower(title || ' ' || body), ?) > 0)
               AND (? = '' OR strpos(lower(title || ' ' || body), ?) > 0)
               AND (? = '' OR strpos(lower(title || ' ' || body), ?) > 0)
               AND (? = '' OR strpos(lower(title || ' ' || body), ?) > 0)
               AND (? = '' OR strpos(lower(title || ' ' || body), ?) > 0)
             ORDER BY updated_at DESC
             LIMIT 201",
            $params
        );
        $truncated = count($rows) > 200;
        if ($truncated) {
            $rows = array_slice($rows, 0, 200);
        }

        $hits = [];
        $seen = [];
        foreach ($rows as $row) {
            $seen[$row['dataset'] . '|' . $row['source_id']] = true;
            $hits[] = $this->hit($row, $words, 'keyword', 1.0);
        }

        foreach ($this->index->similar($userId, $q, $datasets) as $row) {
            $key = $row['dataset'] . '|' . $row['source_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $hits[] = $this->hit($row, $words, 'similar', (float) $row['score']);
        }

        return [
            'q'         => $q,
            'datasets'  => $datasets,
            'available' => SearchQuery::DATASETS,
            'truncated' => $truncated,
            'hits'      => $hits,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $words
     * @return array<string, mixed>
     */
    private function hit(array $row, array $words, string $match, float $score): array
    {
        return [
            'dataset' => (string) $row['dataset'],
            'id'      => (string) $row['source_id'],
            'title'   => (string) $row['title'],
            'snippet' => SearchQuery::snippet((string) $row['title'], (string) $row['body'], $words),
            'url'     => $row['url'] !== null ? (string) $row['url'] : null,
            'match'   => $match,
            'score'   => round($score, 4),
        ];
    }
}
