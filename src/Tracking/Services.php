<?php

declare(strict_types=1);

namespace Dashboard\Tracking;

use Dashboard\Audit\AuditLog;
use Dashboard\Briefs\BriefDigestService;
use Dashboard\Calls\CallService;
use Dashboard\Brief\MorningBriefService;
use Dashboard\Findings\FindingService;
use Dashboard\Digest\DigestService;
use Dashboard\Graph\GraphService;
use Dashboard\Handoff\HandoffService;
use Dashboard\Health\HealthLogService;
use Dashboard\Health\HealthService;
use Dashboard\Auth\ApiTokenService;
use Dashboard\DB\Database;
use Dashboard\Invest\InvestService;
use Dashboard\Knowledge\PageService;
use Dashboard\Life\LifeService;
use Dashboard\Media\MediaService;
use Dashboard\Money\BillsService;
use Dashboard\Money\MoneyLedgerService;
use Dashboard\Money\MoneyPlanService;
use Dashboard\Money\MoneyService;
use Dashboard\Money\PlaidSync;
use Dashboard\Pantry\MealBuilder;
use Dashboard\Pantry\PantryService;
use Dashboard\Reddit\RedditService;
use Dashboard\Search\SearchService;
use Dashboard\Shopping\ShoppingService;
use Dashboard\Stock\StockSheetSync;
use Dashboard\Support\Clock;
use Dashboard\Todo\TodoService;
use Dashboard\Trips\TripService;
use Dashboard\Videos\VideoSlateService;
use Dashboard\Widgets\WidgetDataService;
use Dashboard\Widgets\WidgetService;

/**
 * Wires the L1 services together (tiny manual container — no framework).
 */
final class Services
{
    public readonly MetricService $metrics;
    public readonly DatapointService $points;
    public readonly HabitService $habits;
    public readonly WidgetService $widgets;
    public readonly WidgetDataService $widgetData;
    public readonly AuditLog $audit;
    public readonly ApiTokenService $tokens;
    public readonly PageService $pages;
    public readonly LifeService $life;
    public readonly MoneyService $money;
    public readonly MoneyPlanService $plan;
    public readonly BillsService $bills;
    public readonly MoneyLedgerService $ledger;
    public readonly PlaidSync $plaid;
    public readonly InvestService $invest;
    public readonly SearchService $search;
    public readonly RedditService $reddit;
    public readonly TodoService $todos;
    public readonly ShoppingService $shopping;
    public readonly CallService $calls;
    public readonly PantryService $pantry;
    public readonly MealBuilder $meals;
    public readonly DigestService $digest;
    public readonly HealthService $health;
    public readonly HealthLogService $healthLog;
    public readonly GraphService $graph;
    public readonly MorningBriefService $briefs;
    public readonly HandoffService $handoffs;
    public readonly BriefDigestService $briefsDigest;
    public readonly TripService $trips;
    public readonly VideoSlateService $videos;
    public readonly FindingService $findings;
    public readonly StockSheetSync $sheets;

    public function __construct(public readonly Database $db, public readonly Clock $clock)
    {
        $this->metrics = new MetricService($db);
        $this->points  = new DatapointService($db, $this->metrics, $clock);
        $this->habits  = new HabitService($this->metrics, $this->points, $clock);
        $this->points->afterRecord(function (int $userId, int $metricId, string $localDate, string $source): void {
            $this->habits->completeFromMetric($userId, $metricId, $localDate, $source);
        });
        $this->widgets = new WidgetService($db, $this->metrics);
        $this->audit   = new AuditLog($db);
        $this->tokens  = new ApiTokenService($db);
        $this->pages   = new PageService($db, $this->audit);
        $this->life    = new LifeService($db, $this->metrics, $this->points, $clock, $this->audit);
        $this->money   = new MoneyService($db, $clock, $this->pages);
        $this->plan    = new MoneyPlanService($db, $clock, $this->money);
        $this->bills   = new BillsService($db, $clock, $this->money);
        $this->plaid   = PlaidSync::make($db, $clock, $this->money, $this->plan);
        $this->ledger  = new MoneyLedgerService($db, $clock, $this->money, $this->plan, $this->plaid);
        $this->invest  = new InvestService($db);
        $this->search  = new SearchService($db);
        $this->reddit  = new RedditService($db);
        $this->todos   = TodoService::fromDatabase($db);
        $this->shopping = new ShoppingService($db, $clock);
        $this->calls = new CallService($db, $clock);
        $this->pantry = new PantryService($db, $clock, $this->shopping);
        $this->meals = new MealBuilder($db, $this->shopping, $this->pantry);
        $this->digest = new DigestService($db);
        $this->health = new HealthService($db, $clock);
        $this->healthLog = new HealthLogService($db, $clock, $this->habits);
        $this->graph = new GraphService($db);
        $this->sheets = StockSheetSync::make($db, $clock);
        $this->briefs = new MorningBriefService($db, $clock, $this->bills, $this->digest, $this->health, $this->sheets);
        $this->handoffs = new HandoffService($db, $clock);
        $this->briefsDigest = new BriefDigestService($db, $clock);
        $this->trips = new TripService($db, $clock, $this->pages);
        $this->videos = new VideoSlateService($db, $clock);
        $this->findings = new FindingService($db, $clock, $this->pages);
        $this->widgetData = new WidgetDataService(
            $this->widgets,
            $this->metrics,
            $this->points,
            $this->habits,
            $clock,
            $this->life,
            $this->money,
            $this->plan,
            $this->invest,
            $this->pages,
            new MediaService($db),
            $this->reddit,
            $this->shopping,
            $this->calls,
            $this->pantry,
            $db,
            $this->ledger,
            $this->handoffs,
        );
    }

    public static function fromDatabase(Database $db): self
    {
        return new self($db, Clock::fromConfig());
    }
}
