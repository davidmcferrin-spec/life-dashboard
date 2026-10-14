<?php

declare(strict_types=1);

namespace Dashboard\Tracking;

use Dashboard\Audit\AuditLog;
use Dashboard\Auth\ApiTokenService;
use Dashboard\DB\Database;
use Dashboard\Invest\InvestService;
use Dashboard\Knowledge\PageService;
use Dashboard\Life\LifeService;
use Dashboard\Media\MediaService;
use Dashboard\Money\MoneyService;
use Dashboard\Search\SearchService;
use Dashboard\Support\Clock;
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
    public readonly InvestService $invest;
    public readonly SearchService $search;

    public function __construct(public readonly Database $db, public readonly Clock $clock)
    {
        $this->metrics = new MetricService($db);
        $this->points  = new DatapointService($db, $this->metrics, $clock);
        $this->habits  = new HabitService($this->metrics, $this->points, $clock);
        $this->widgets = new WidgetService($db, $this->metrics);
        $this->audit   = new AuditLog($db);
        $this->tokens  = new ApiTokenService($db);
        $this->pages   = new PageService($db, $this->audit);
        $this->life    = new LifeService($db, $this->metrics, $this->points, $clock, $this->audit);
        $this->money   = new MoneyService($db, $clock, $this->pages);
        $this->invest  = new InvestService($db);
        $this->search  = new SearchService($db);
        $this->widgetData = new WidgetDataService(
            $this->widgets,
            $this->metrics,
            $this->points,
            $this->habits,
            $clock,
            $this->life,
            $this->money,
            $this->invest,
            $this->pages,
            new MediaService($db),
        );
    }

    public static function fromDatabase(Database $db): self
    {
        return new self($db, Clock::fromConfig());
    }
}
