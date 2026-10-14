<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Auth\ApiTokenService;
use Dashboard\Support\Clock;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

/**
 * L2–L6 services against PostgreSQL. Custom-widget payloads are the L7 check.
 */
final class PlatformTest extends PgsqlTestCase
{
    private Services $svc;
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['david', 'david@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->svc = new Services($this->db, new Clock('America/Chicago', new DateTimeImmutable('2026-10-06 15:00:00', new DateTimeZone('UTC'))));
    }

    public function testTokenRoundTripAndRevoke(): void
    {
        $created = $this->svc->tokens->create($this->user, 'Claude', ['read']);
        self::assertStringStartsWith('ld_', $created['token']);
        $auth = $this->svc->tokens->authenticate($created['token']);
        self::assertNotNull($auth);
        self::assertSame($this->user, $auth['user_id']);
        self::assertContains('pages:read', $auth['scopes']);
        self::assertContains('search:read', $auth['scopes']);
        $this->svc->tokens->revoke($this->user, $created['id']);
        self::assertNull($this->svc->tokens->authenticate($created['token']));
        self::assertSame(ApiTokenService::hash($created['token']), hash('sha256', $created['token']));
    }

    public function testVaultWikilinkRevisionAndGraph(): void
    {
        $home = $this->svc->pages->create($this->user, ['title' => 'Home', 'body' => 'Start', 'kind' => 'note']);
        $note = $this->svc->pages->create($this->user, ['title' => 'Trip', 'body' => 'See [[Home]]', 'kind' => 'project']);
        self::assertSame($home['id'], $note['links'][0]['id']);
        self::assertSame('Trip', $this->svc->pages->get($this->user, $home['id'])['backlinks'][0]['title']);

        $edited = $this->svc->pages->update($this->user, $note['id'], ['body' => 'See [[Home]]\n\nMore']);
        self::assertGreaterThanOrEqual(2, $edited['revisions'][0]['version']);
        $restored = $this->svc->pages->restore($this->user, $note['id'], 1);
        self::assertSame('See [[Home]]', $restored['body']);

        $graph = $this->svc->pages->graph($this->user);
        $hot = array_column(array_filter($graph['nodes'], static fn (array $n): bool => $n['hot']), 'id');
        self::assertContains($note['id'], $hot);
        self::assertNotEmpty($graph['edges']);
    }

    public function testLifeIngestAndMoneyAndInvesting(): void
    {
        $written = $this->svc->life->ingest($this->user, [
            'records' => [
                ['type' => 'Steps', 'count' => 9000, 'end' => '2026-10-06T14:00:00Z'],
            ],
        ], 'integration');
        self::assertCount(1, $written['written']);
        $today = $this->svc->life->today($this->user);
        $steps = null;
        foreach ($today as $row) {
            if ($row['metric']['key'] === 'steps') {
                $steps = $row['latest']['value'];
            }
        }
        self::assertSame(9000.0, $steps);

        $checking = $this->svc->money->createAccount($this->user, ['name' => 'Checking', 'kind' => 'checking', 'balance' => 1000]);
        $this->svc->money->addTransaction($this->user, [
            'account_id' => $checking['id'],
            'date'       => '2026-10-05',
            'amount'     => -40,
            'payee'      => 'Cafe',
            'category'   => 'Food',
        ]);
        $summary = $this->svc->money->summary($this->user, '2026-10-01', '2026-10-06');
        self::assertSame(40.0, $summary['spent']);
        self::assertSame('Food', $summary['categories'][0]['category']);
        self::assertSame(1000.0, $summary['net_worth']);
        $page = $this->svc->money->weeklyReview($this->user);
        self::assertSame('review', $page['kind']);
        self::assertStringContainsString('Food', $page['body']);

        $imported = $this->svc->invest->importRobinhood($this->user, <<<CSV
Activity Date,Instrument,Description,Trans Code,Quantity,Price,Amount
10/1/2026,AAPL,Apple,Buy,2,100.00,-200.00
CSV);
        self::assertSame(1, $imported['positions']);
        $card = $this->svc->invest->scorecard($this->user);
        self::assertSame('AAPL', $card['positions'][0]['symbol']);
        self::assertSame(200.0, $card['market_value']);
        self::assertSame(0.0, $card['unrealized']);
    }

    public function testCustomWidgetPayloadCarriesMetrics(): void
    {
        $metric = $this->svc->metrics->create($this->user, ['name' => 'Pushups', 'kind' => 'number']);
        $this->svc->points->record($this->user, $metric['id'], ['value' => 12, 'date' => '2026-10-06']);
        $widget = $this->svc->widgets->create($this->user, 'custom', 'Mine', [
            'markup'     => '<p id="n"></p><script>document.getElementById("n").textContent=window.__DATA__.metrics[0].last</script>',
            'metric_ids' => [$metric['id']],
        ]);
        $data = $this->svc->widgetData->forWidget($this->user, $widget['id']);
        self::assertSame([$metric['id']], $data['metric_ids']);
        self::assertSame(12.0, $data['metrics'][0]['last']);
        self::assertSame('2026-10-06', $data['today']);
    }

    public function testSearchReturnsEveryAppleAndHonorsDatasetFilters(): void
    {
        $this->svc->pages->create($this->user, ['title' => 'Fruit', 'body' => 'A red Apple on the desk', 'kind' => 'note']);
        $this->svc->pages->create($this->user, ['title' => 'Other', 'body' => 'Banana only', 'kind' => 'note']);
        $this->db->execute(
            'INSERT INTO bookmarks (user_id, title, url) VALUES (?, ?, ?)',
            [$this->user, 'Apple news', 'https://example.com/apple']
        );
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['other', 'other@x', 'h']
        );
        $other = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO bookmarks (user_id, title, url) VALUES (?, ?, ?)',
            [$other, 'Secret Apple', 'https://example.com/secret']
        );

        $all = $this->svc->search->search($this->user, 'Apple');
        $byDataset = [];
        foreach ($all['hits'] as $hit) {
            self::assertSame('keyword', $hit['match']);
            self::assertStringContainsStringIgnoringCase('apple', $hit['snippet']);
            $byDataset[$hit['dataset']] = true;
        }
        self::assertArrayHasKey('pages', $byDataset);
        self::assertArrayHasKey('bookmarks', $byDataset);
        self::assertNotContains('Secret Apple', array_column($all['hits'], 'title'));

        $pages = $this->svc->search->search($this->user, 'Apple', ['pages']);
        self::assertSame(['pages'], array_values(array_unique(array_column($pages['hits'], 'dataset'))));

        $excluded = $this->svc->search->search($this->user, 'Apple', null, ['bookmarks']);
        self::assertNotContains('bookmarks', array_column($excluded['hits'], 'dataset'));
        self::assertNotEmpty($excluded['hits']);

        $both = $this->svc->search->search($this->user, 'Apple desk');
        self::assertNotEmpty($both['hits']);
        self::assertNotContains('Apple news', array_column($both['hits'], 'title'));
    }
}
