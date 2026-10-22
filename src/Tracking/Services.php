<?php

declare(strict_types=1);

namespace Dashboard\Tracking;

use Dashboard\Audit\AuditLog;
use Dashboard\Calls\CallService;
use Dashboard\Digest\DigestService;
use Dashboard\Auth\ApiTokenService;
use Dashboard\DB\Database;
use Dashboard\Invest\InvestService;
use Dashboard\Knowledge\PageService;
use Dashboard\Life\LifeService;
use Dashboard\Media\MediaService;
use Dashboard\Money\MoneyPlanService;
use Dashboard\Money\MoneyService;
use Dashboard\Pantry\MealBuilder;
use Dashboard\Pantry\PantryService;
use Dashboard\Reddit\RedditService;
use Dashboard\Search\SearchService;
use Dashboard\Shopping\ShoppingService;
use Dashboard\Support\Clock;
use Dashboard\Todo\TodoService;
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
    public readonly InvestService $invest;
    public readonly SearchService $search;
    public readonly RedditService $reddit;
    public readonly TodoService $todos;
    public readonly ShoppingService $shopping;
    public readonly CallService $calls;
    public readonly PantryService $pantry;
    public readonly MealBuilder $meals;
    public readonly DigestService $digest;

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
        $this->invest  = new InvestService($db);
        $this->search  = new SearchService($db);
        $this->reddit  = new RedditService($db);
        $this->todos   = TodoService::fromDatabase($db);
        $this->shopping = new ShoppingService($db, $clock);
        $this->calls = new CallService($db, $clock);
        $this->pantry = new PantryService($db, $clock, $this->shopping);
        $this->meals = new MealBuilder($db, $this->shopping, $this->pantry);
        $this->digest = new DigestService($db);
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
        );
    }

    public static function fromDatabase(Database $db): self
    {
        return new self($db, Clock::fromConfig());
    }
}
