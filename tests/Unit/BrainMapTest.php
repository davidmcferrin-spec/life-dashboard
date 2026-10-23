<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Audit\TokenStamp;
use Dashboard\Knowledge\BrainActivity;
use Dashboard\Knowledge\BrainLayout;
use PHPUnit\Framework\TestCase;

final class BrainMapTest extends TestCase
{
    public function testLinkedPagesStayPutWhenANewPageArrives(): void
    {
        $pages = [
            ['id' => 1, 'title' => 'Trip', 'kind' => 'project'],
            ['id' => 2, 'title' => 'Flights', 'kind' => 'note'],
            ['id' => 3, 'title' => 'Alone', 'kind' => 'reference'],
        ];
        $edges = [['from' => 1, 'to' => 2]];
        $first = BrainLayout::place($pages, $edges);
        $saved = $this->saved($first);

        $pages[] = ['id' => 4, 'title' => 'Gear', 'kind' => 'note'];
        $second = BrainLayout::place($pages, $edges, $saved);

        $this->assertSameCluster($first, $second, 1, 2, 'link:1');
        $this->assertSameSpot($first, $second, 1);
        $this->assertSameSpot($first, $second, 2);
        $dirty = array_column($second['dirty'], 'id');
        self::assertNotContains(1, $dirty);
        self::assertNotContains(2, $dirty);
        self::assertContains(4, $dirty);
    }

    public function testSimilarUnlinkedPagesFormATopicCluster(): void
    {
        $pages = [
            ['id' => 1, 'title' => 'A', 'kind' => 'note'],
            ['id' => 2, 'title' => 'B', 'kind' => 'note'],
            ['id' => 3, 'title' => 'C', 'kind' => 'note'],
        ];
        $placed = BrainLayout::place($pages, [], [], [
            1 => [1.0, 0.0],
            2 => [0.96, 0.05],
            3 => [0.0, 1.0],
        ]);
        $clusters = $this->clustersById($placed);
        self::assertSame($clusters[1], $clusters[2]);
        self::assertStringStartsWith('topic:', $clusters[1]);
        self::assertNotSame($clusters[1], $clusters[3]);
    }

    public function testALinkBeatsASharedKind(): void
    {
        $pages = [
            ['id' => 4, 'title' => 'Project', 'kind' => 'project'],
            ['id' => 9, 'title' => 'Note', 'kind' => 'note'],
        ];
        $placed = BrainLayout::place($pages, [['from' => 9, 'to' => 4]]);
        $clusters = $this->clustersById($placed);
        self::assertSame('link:4', $clusters[4]);
        self::assertSame('link:4', $clusters[9]);
    }

    public function testUnlinkedPagesOfOneKindShareACluster(): void
    {
        $pages = [
            ['id' => 1, 'title' => 'A', 'kind' => 'daily'],
            ['id' => 2, 'title' => 'B', 'kind' => 'daily'],
        ];
        $placed = BrainLayout::place($pages, []);
        $clusters = $this->clustersById($placed);
        self::assertSame('kind:daily', $clusters[1]);
        self::assertSame('kind:daily', $clusters[2]);
        self::assertSame('Daily', $placed['clusters'][0]['label']);
    }

    public function testTokenStampKeepsTheSecretOut(): void
    {
        $detail = TokenStamp::detail(7, 'Grok', 'read', ['filter' => 'from=2026-01-01']);
        self::assertSame(7, $detail['token_id']);
        self::assertSame('Grok', $detail['token_name']);
        self::assertSame('read', $detail['verb']);
        self::assertSame('from=2026-01-01', $detail['filter']);
        self::assertArrayNotHasKey('token', $detail);
    }

    public function testActivityPrefersTheTokenRowForTheSamePage(): void
    {
        $presented = BrainActivity::present([
            [
                'id' => 10, 'actor' => 'ai', 'tool' => 'update_page', 'action' => 'POST',
                'resource' => '/api/v1/pages/5/update', 'created_at' => '2026-10-15 03:10:03',
                'detail' => ['verb' => 'write', 'token_id' => 3, 'token_name' => 'Grok'],
            ],
            [
                'id' => 9, 'actor' => 'ai', 'tool' => null, 'action' => 'page.update',
                'resource' => 'page:5', 'created_at' => '2026-10-15 03:10:02',
                'detail' => ['title' => 'Flight notes'],
            ],
            [
                'id' => 8, 'actor' => 'ai', 'tool' => 'list_bookmarks', 'action' => 'GET',
                'resource' => '/api/bookmarks', 'created_at' => '2026-10-15 03:09:00',
                'detail' => ['verb' => 'read', 'token_id' => 4, 'token_name' => 'Claude'],
            ],
            [
                'id' => 7, 'actor' => 'system', 'tool' => null, 'action' => 'POST',
                'resource' => '/api/v1/life/ingest', 'created_at' => '2026-10-15 03:00:00',
                'detail' => [],
            ],
        ]);

        self::assertCount(2, $presented['activity']);
        self::assertSame(3, $presented['activity'][0]['token_id']);
        self::assertSame(5, $presented['activity'][0]['page_id']);
        self::assertSame('write', $presented['activity'][0]['verb']);
        self::assertSame('Grok', $presented['activity'][0]['token']);
        self::assertNull($presented['activity'][1]['page_id']);
        self::assertSame('read', $presented['activity'][1]['verb']);
        self::assertSame('Claude', $presented['activity'][1]['token']);
        self::assertSame([
            ['id' => 3, 'name' => 'Grok'],
            ['id' => 4, 'name' => 'Claude'],
        ], $presented['tokens']);
    }

    public function testSignalsKeepPageVerbAndDropTitles(): void
    {
        $signals = BrainActivity::signals([
            [
                'id' => 9, 'actor' => 'user', 'action' => 'GET', 'resource' => 'page:5',
                'created_at' => '2026-10-15 03:10:02',
                'detail' => ['title' => 'Secret title', 'verb' => 'read'],
            ],
            [
                'id' => 8, 'actor' => 'ai', 'action' => 'POST', 'resource' => '/api/v1/pages/3',
                'created_at' => '2026-10-15 03:09:00',
                'detail' => ['token_id' => 2, 'token_name' => 'Grok', 'title' => 'Nope'],
            ],
            [
                'id' => 7, 'actor' => 'system', 'action' => 'GET', 'resource' => 'page:1',
                'detail' => [],
            ],
            [
                'id' => 6, 'actor' => 'ai', 'action' => 'GET', 'resource' => '/api/v1/money/transactions',
                'detail' => ['title' => 'Hidden'],
            ],
        ]);

        self::assertCount(2, $signals);
        self::assertSame(5, $signals[0]['page_id']);
        self::assertSame('read', $signals[0]['verb']);
        self::assertSame('user', $signals[0]['actor']);
        self::assertSame('You', $signals[0]['source']);
        self::assertSame(3, $signals[1]['page_id']);
        self::assertSame('write', $signals[1]['verb']);
        self::assertSame('Grok', $signals[1]['source']);
        $json = json_encode($signals, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Secret', $json);
        self::assertStringNotContainsString('Nope', $json);
        self::assertStringNotContainsString('Hidden', $json);
        self::assertArrayNotHasKey('resource', $signals[0]);
    }

    public function testGraphAndAuditReadsAreNotTraced(): void
    {
        self::assertTrue(ApiRunner::isMetaRead('/api/v1/pages/graph'));
        self::assertTrue(ApiRunner::isMetaRead('/api/v1/pages/graph/access'));
        self::assertTrue(ApiRunner::isMetaRead('/api/v1/audit'));
        self::assertTrue(ApiRunner::isMetaRead('/api/v1/realtime/token'));
        self::assertFalse(ApiRunner::isMetaRead('/api/v1/pages/5'));
        self::assertFalse(ApiRunner::isMetaRead('/api/v1/money/transactions'));
    }

    /**
     * @param array{nodes: list<array<string, mixed>>, clusters: list<array<string, mixed>>, dirty: list<array<string, mixed>>} $placed
     * @return array<int, array{x: float, y: float, cluster_key: string}>
     */
    private function saved(array $placed): array
    {
        $saved = [];
        foreach ($placed['nodes'] as $node) {
            $saved[$node['id']] = [
                'x'           => $node['x'],
                'y'           => $node['y'],
                'cluster_key' => $node['cluster'],
            ];
        }
        return $saved;
    }

    /**
     * @param array{nodes: list<array<string, mixed>>} $placed
     * @return array<int, string>
     */
    private function clustersById(array $placed): array
    {
        $out = [];
        foreach ($placed['nodes'] as $node) {
            $out[$node['id']] = $node['cluster'];
        }
        return $out;
    }

    /**
     * @param array{nodes: list<array<string, mixed>>} $first
     * @param array{nodes: list<array<string, mixed>>} $second
     */
    private function assertSameCluster(array $first, array $second, int $a, int $b, string $key): void
    {
        self::assertSame($key, $this->clustersById($first)[$a]);
        self::assertSame($key, $this->clustersById($second)[$a]);
        self::assertSame($key, $this->clustersById($second)[$b]);
    }

    /**
     * @param array{nodes: list<array<string, mixed>>} $first
     * @param array{nodes: list<array<string, mixed>>} $second
     */
    private function assertSameSpot(array $first, array $second, int $id): void
    {
        $before = $this->node($first, $id);
        $after = $this->node($second, $id);
        self::assertEqualsWithDelta($before['x'], $after['x'], 0.00001);
        self::assertEqualsWithDelta($before['y'], $after['y'], 0.00001);
    }

    /**
     * @param array{nodes: list<array<string, mixed>>} $placed
     * @return array<string, mixed>
     */
    private function node(array $placed, int $id): array
    {
        foreach ($placed['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }
        self::fail("missing node {$id}");
    }
}
