<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Invest\InvestService;
use Dashboard\Invest\ValueHistory;
use Dashboard\Support\ValidationException;

final class InvestValueHistoryTest extends PgsqlTestCase
{
    private int $user;

    private InvestService $invest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ada', 'ada@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->invest = new InvestService($this->db);
    }

    public function testManualHoldingsAndStoredHistoryDriveTheDisplayStats(): void
    {
        $saved = $this->invest->saveManualPosition($this->user, [
            'symbol'   => 'aapl',
            'name'     => 'Apple Inc.',
            'quantity' => 2,
            'avg_cost' => 100,
            'mark'     => 150,
        ]);
        self::assertSame('manual', $saved['venue']);
        self::assertSame('AAPL', $saved['symbol']);
        self::assertSame('Apple Inc.', $saved['name']);
        self::assertEqualsWithDelta(300.0, (float) $saved['market_value'], 0.0001);
        self::assertEqualsWithDelta(0.5, (float) $saved['return_ratio'], 0.0001);

        $today = gmdate('Y-m-d');
        $yesterday = ValueHistory::shift($today, '-1 day');
        $yearAgo = ValueHistory::shift($today, '-1 year');
        self::assertNotNull($yesterday);
        self::assertNotNull($yearAgo);
        $this->db->execute(
            'INSERT INTO invest_value_history (user_id, as_of, market_value, cash) VALUES (?, ?, ?, ?)',
            [$this->user, $yesterday, 250, 99]
        );
        $this->db->execute(
            'INSERT INTO invest_value_history (user_id, as_of, market_value) VALUES (?, ?, ?)',
            [$this->user, $yearAgo, 200]
        );

        $card = $this->invest->scorecard($this->user);
        self::assertEqualsWithDelta(300.0, (float) $card['portfolio_value'], 0.0001);
        self::assertEqualsWithDelta(300.0, (float) $card['market_value'], 0.0001);
        self::assertEqualsWithDelta(50.0, (float) $card['day_change'], 0.0001);
        self::assertEqualsWithDelta(0.2, (float) $card['day_change_ratio'], 0.0001);
        self::assertNull($card['buying_power']);
        self::assertEqualsWithDelta(0.5, (float) $card['annual_return'], 0.0001);
        self::assertSame([$yesterday, $today], array_column($card['series'], 'date'));
        self::assertSame($today, $card['as_of']);

        $cash = $this->invest->saveCash($this->user, 25);
        self::assertSame(25.0, $cash['cash']);
        $withCash = $this->invest->scorecard($this->user);
        self::assertEqualsWithDelta(25.0, (float) $withCash['buying_power'], 0.0001);

        try {
            $this->invest->saveManualPosition($this->user, [
                'symbol'   => 'BAD',
                'name'     => 'acct 12345678',
                'quantity' => 1,
                'mark'     => 1,
            ]);
            self::fail('a full account number in the name must be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->fields());
        }

        try {
            $this->invest->saveCash($this->user, -5);
            self::fail('negative buying power must be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('cash', $e->fields());
        }

        $unpriced = $this->invest->saveManualPosition($this->user, [
            'symbol'   => 'MSFT',
            'quantity' => 1,
            'name'     => 'Microsoft',
        ]);
        self::assertNull($unpriced['mark']);
        self::assertNull($unpriced['market_value']);
        self::assertNull($unpriced['return_ratio']);

        $unknown = $this->invest->scorecard($this->user);
        self::assertNull($unknown['portfolio_value']);
        self::assertNull($unknown['day_change']);
        self::assertNull($unknown['annual_return']);
        self::assertEqualsWithDelta(300.0, (float) $unknown['market_value'], 0.0001);
        $stored = $this->db->fetchOne(
            'SELECT market_value, cash FROM invest_value_history WHERE user_id = ? AND as_of = ?',
            [$this->user, $today]
        );
        self::assertNotNull($stored);
        self::assertNull($stored['market_value']);
        self::assertEqualsWithDelta(25.0, (float) $stored['cash'], 0.0001);

        $this->invest->deleteManualPosition($this->user, (int) $unpriced['id']);
        $pricedAgain = $this->invest->scorecard($this->user);
        self::assertEqualsWithDelta(300.0, (float) $pricedAgain['portfolio_value'], 0.0001);
        self::assertEqualsWithDelta(25.0, (float) $pricedAgain['buying_power'], 0.0001);

        $this->invest->deleteManualPosition($this->user, (int) $saved['id']);
        $empty = $this->invest->scorecard($this->user);
        self::assertSame(0.0, $empty['portfolio_value']);
        self::assertSame(0, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM invest_activity WHERE user_id = ?',
            [$this->user]
        ));

        $this->invest->saveManualPosition($this->user, [
            'symbol'   => 'AAPL',
            'quantity' => 2,
            'avg_cost' => 100,
            'mark'     => 150,
        ]);
        $csv = "Activity Date,Instrument,Trans Code,Quantity,Price,Amount\n10/01/2026,VOO,Buy,2,100,-200\n";
        $imported = $this->invest->importRobinhood($this->user, $csv);
        self::assertSame(1, $imported['positions']);
        $venues = $this->db->fetchAll(
            'SELECT venue, symbol FROM invest_positions WHERE user_id = ? ORDER BY venue, symbol',
            [$this->user]
        );
        self::assertSame([
            ['venue' => 'manual', 'symbol' => 'AAPL'],
            ['venue' => 'robinhood', 'symbol' => 'VOO'],
        ], $venues);

        $method = new \ReflectionMethod(InvestService::class, 'saveManualPosition');
        $file = file($method->getFileName() ?: '');
        self::assertIsArray($file);
        $source = implode('', array_slice($file, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringNotContainsString('HttpClient', $source);
        self::assertStringNotContainsString('invest_activity', $source);
    }
}
