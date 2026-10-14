<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Invest\RobinhoodCsv;
use Dashboard\Knowledge\Vectors;
use Dashboard\Knowledge\Wikilinks;
use Dashboard\Life\HealthConnect;
use Dashboard\Search\SearchQuery;
use Dashboard\Support\Csv;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class PlatformParseTest extends TestCase
{
    public function testScopeShorthandAndWriteImpliesRead(): void
    {
        $read = Scope::normalize(['read']);
        self::assertContains('metrics:read', $read);
        self::assertContains('search:read', $read);
        self::assertNotContains('metrics:write', $read);
        self::assertTrue(Scope::allows(['money:write'], 'money:read'));
        self::assertFalse(Scope::allows(['money:read'], 'money:write'));
        self::assertTrue(Scope::allows(['*'], 'invest:write'));
        self::assertSame('pages:write', Scope::forPath('/api/v1/pages/4/update', true));
        self::assertSame('search:read', Scope::forPath('/api/v1/search', false));
        self::assertSame('metrics:read', Scope::forPath('/api/v1/habits', false));
        self::assertTrue(Scope::sessionOnly('/api/v1/tokens'));
    }

    public function testUnknownScopeIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        Scope::normalize(['trade:write']);
    }

    public function testWikilinksIgnoreAliasesAndDeduplicate(): void
    {
        $titles = Wikilinks::titles("See [[Weekly review]] and [[weekly review|that]] plus [[Home#top]].");
        self::assertSame(['Weekly review', 'Home'], $titles);
    }

    public function testCosineOfIdenticalVectorsIsOne(): void
    {
        self::assertEqualsWithDelta(1.0, Vectors::cosine([1, 0, 0], [2, 0, 0]), 0.0001);
        self::assertEqualsWithDelta(0.0, Vectors::cosine([1, 0], [0, 1]), 0.0001);
    }

    public function testHealthConnectMapsTheSeededMetrics(): void
    {
        $entries = HealthConnect::entries([
            ['type' => 'Steps', 'count' => 4200, 'end' => '2026-10-06T22:00:00Z'],
            ['type' => 'SleepSession', 'start' => '2026-10-05T23:00:00Z', 'end' => '2026-10-06T06:30:00Z'],
            ['type' => 'Weight', 'kilograms' => 80, 'time' => '2026-10-06T12:00:00Z'],
            ['type' => 'ExerciseSession', 'start' => '2026-10-06T13:00:00Z', 'end' => '2026-10-06T13:30:00Z', 'title' => 'Walk'],
            ['type' => 'HeartRate', 'bpm' => 70],
        ]);
        $byKey = [];
        foreach ($entries as $entry) {
            $byKey[$entry['key']] = $entry;
        }
        self::assertSame(4200.0, $byKey['steps']['value']);
        self::assertSame(7.5, $byKey['sleep_hours']['value']);
        self::assertEqualsWithDelta(176.37, $byKey['weight']['value'], 0.02);
        self::assertSame(1800.0, $byKey['exercise_min']['value']);
        self::assertArrayNotHasKey('heart', $byKey);
    }

    public function testMonarchAndRobinhoodCsv(): void
    {
        $rows = Csv::assocRows("Date,Merchant,Category,Account,Amount\n2026-10-01,Cafe,Food,Checking,-12.50\n");
        self::assertSame('Cafe', $rows[0]['merchant']);
        self::assertSame('-12.50', $rows[0]['amount']);

        $activity = RobinhoodCsv::rows(<<<CSV
Account activity

Activity Date,Instrument,Description,Trans Code,Quantity,Price,Amount
10/1/2026,AAPL,Apple,Buy,2,100.00,-200.00
10/2/2026,AAPL,Apple,Sell,1,110.00,110.00
CSV);
        self::assertCount(2, $activity);
        self::assertSame('AAPL', $activity[0]['symbol']);
        self::assertTrue(RobinhoodCsv::isBuy($activity[0]['kind']));
        self::assertSame('2026-10-02', $activity[1]['occurred_on']);
    }

    public function testSearchWordsAndDatasetFilters(): void
    {
        self::assertSame(['apple', 'pie'], SearchQuery::words("  Apple   pie "));
        self::assertSame(['pages'], SearchQuery::datasets(['pages'], ['bookmarks']));
        self::assertNotContains('money', SearchQuery::datasets(null, ['money']));
        self::assertStringContainsStringIgnoringCase('apple', SearchQuery::snippet('Fruit', 'A red Apple on the desk', ['apple']));
    }

    public function testSearchRejectsUnknownDataset(): void
    {
        $this->expectException(ValidationException::class);
        SearchQuery::datasets(['voice'], null);
    }

    public function testLaterPhaseClassesExist(): void
    {
        foreach ([
            \Dashboard\Audit\AuditLog::class,
            \Dashboard\Auth\ApiTokenService::class,
            \Dashboard\Api\V1\ApiRunner::class,
            \Dashboard\Api\V1\ExtendedApi::class,
            \Dashboard\Knowledge\PageService::class,
            \Dashboard\Life\LifeService::class,
            \Dashboard\Money\MoneyService::class,
            \Dashboard\Invest\InvestService::class,
            \Dashboard\Mcp\AgentPrompt::class,
            \Dashboard\Search\SearchService::class,
        ] as $class) {
            self::assertTrue(class_exists($class), $class);
        }
    }
}
