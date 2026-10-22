<?php

declare(strict_types=1);

namespace Dashboard\Widgets;

use Dashboard\Calls\CallService;
use Dashboard\Invest\InvestService;
use Dashboard\Knowledge\PageService;
use Dashboard\Media\MediaService;
use Dashboard\Pantry\PantryService;
use Dashboard\Reddit\RedditService;
use Dashboard\Shopping\ShoppingService;
use Dashboard\Life\LifeService;
use Dashboard\Money\MoneyPlanService;
use Dashboard\Money\MoneyService;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\DatapointService;
use Dashboard\Tracking\HabitService;
use Dashboard\Tracking\MetricService;

/**
 * WidgetDataService — computes what a native widget displays, server-side.
 *
 * One request per widget keeps renderers dumb, and gives the L2 MCP tools a
 * "what does this widget show?" answer for free. Every payload carries
 * `metric_ids` so the browser knows which realtime events should refresh it.
 */
final class WidgetDataService
{
    public function __construct(
        private readonly WidgetService $widgets,
        private readonly MetricService $metrics,
        private readonly DatapointService $points,
        private readonly HabitService $habits,
        private readonly Clock $clock,
        private readonly LifeService $life,
        private readonly MoneyService $money,
        private readonly MoneyPlanService $plan,
        private readonly InvestService $invest,
        private readonly PageService $pages,
        private readonly MediaService $media,
        private readonly RedditService $reddit,
        private readonly ShoppingService $shopping,
        private readonly CallService $calls,
        private readonly PantryService $pantry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forWidget(int $userId, int $widgetId): array
    {
        $w = $this->widgets->get($userId, $widgetId);
        $c = $w['config'];

        return match ($w['type']) {
            'habits' => $this->habitsData($userId, $c),
            'mood'   => $this->moodData($userId, $c),
            'stat'   => $this->statData($userId, $c),
            'chart'  => $this->chartData($userId, $c),
            'gauge'  => $this->gaugeData($userId, $c),
            'goal'   => $this->goalData($userId, $c),
            'table'  => $this->tableData($userId, $c),
            'streak'    => $this->streakData($userId, $c),
            'life'      => $this->lifeData($userId),
            'shopping'  => $this->shoppingData($userId, $c),
            'pantry'    => $this->pantry->summary($userId, (int) ($c['soon_days'] ?? 3)),
            'digest'    => \Dashboard\Digest\DigestService::fromDatabase()->card($userId),
            'calls'     => $this->callsData($userId, $c),
            'spending'  => $this->spendingData($userId, $c),
            'networth'       => $this->networthData($userId),
            'baby_step'      => $this->planCard($this->plan->babyStep($userId)),
            'debt_snowball'  => $this->planCard(['debts' => $this->plan->debts($userId)]),
            'food_week'      => $this->planCard($this->plan->foodWeek($userId)),
            'budget_actual'  => $this->planCard($this->plan->budget($userId, (int) substr($this->clock->today(), 0, 4), (int) substr($this->clock->today(), 5, 2))),
            'sinking_funds'  => $this->planCard(['funds' => $this->plan->sinkingFunds($userId)]),
            'bills_due'      => $this->planCard(['bills' => $this->plan->billsDue($userId)]),
            'retirement'     => $this->planCard($this->plan->retirement($userId)),
            'credit'         => $this->planCard($this->plan->credit($userId)),
            'portfolio' => $this->portfolioData($userId),
            'pages'     => $this->pagesData($userId, $c),
            'youtube'   => $this->media->payload($userId, 'youtube', $c),
            'reddit'      => $this->media->payload($userId, 'reddit', $c),
            'reddit_feed' => $this->redditFeed($userId, $c),
            'spotify'     => \Dashboard\Spotify\SpotifyService::fromDatabase()->card($userId),
            'x'         => $this->media->payload($userId, 'x', $c),
            'custom'    => $this->customData($userId, $c),
            default     => ['metric_ids' => []], // legacy widgets load their own data
        };
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function callsData(int $userId, array $config): array
    {
        return $this->calls->board($userId, (int) ($config['limit'] ?? 16));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function shoppingData(int $userId, array $config): array
    {
        $listId = (int) ($config['list_id'] ?? 0);
        $hours = (int) ($config['clear_hours'] ?? 24);
        try {
            return $this->shopping->board($userId, $listId, $hours);
        } catch (NotFoundException) {
            return $this->shopping->board($userId, 0, $hours);
        }
    }

    /**
     * Recent posts from the tracked-subreddit list. The fetch timer fills the table.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function redditFeed(int $userId, array $config): array
    {
        $filter = ['limit' => (int) ($config['limit'] ?? 12)];
        $sub = trim((string) ($config['subreddit'] ?? ''));
        if ($sub !== '') {
            $filter['subreddit'] = $sub;
        }
        try {
            $posts = $this->reddit->posts($userId, $filter);
        } catch (ValidationException $e) {
            return ['metric_ids' => [], 'posts' => [], 'empty' => $e->getMessage()];
        }
        return [
            'metric_ids' => [],
            'posts'      => $posts,
            'empty'      => 'No posts yet. Add subreddits under Settings → Reddit.',
        ];
    }

    /**
     * [from, to] local dates for a period key; from = null means unbounded.
     *
     * @return array{0: ?string, 1: string}
     */
    public static function periodRange(string $period, string $today): array
    {
        return match ($period) {
            'today' => [$today, $today],
            '7d'    => [Clock::shiftDate($today, 6), $today],
            '30d'   => [Clock::shiftDate($today, 29), $today],
            '90d'   => [Clock::shiftDate($today, 89), $today],
            '365d'  => [Clock::shiftDate($today, 364), $today],
            'all'   => [null, $today],
            default => throw new \InvalidArgumentException("Unknown period {$period}"),
        };
    }

    /**
     * The equally long window just before a period (for deltas); null for 'all'.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function previousRange(string $period, string $today): ?array
    {
        [$from] = self::periodRange($period, $today);
        if ($from === null) {
            return null;
        }
        $len = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($today))->days + 1;
        return [Clock::shiftDate($from, $len), Clock::shiftDate($from, 1)];
    }

    /**
     * All bucket keys between two dates (inclusive), matching Postgres date_trunc.
     *
     * @return list<string>
     */
    public static function bucketKeys(string $bucket, string $from, string $to): array
    {
        $start = new \DateTimeImmutable($from . ' 00:00:00');
        $end   = new \DateTimeImmutable($to . ' 00:00:00');
        $start = match ($bucket) {
            'day'   => $start,
            'week'  => $start->modify('-' . ((int) $start->format('N') - 1) . ' days'),
            'month' => $start->modify('first day of this month'),
            default => throw new \InvalidArgumentException("Unknown bucket {$bucket}"),
        };
        $step = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month'][$bucket];
        $keys = [];
        for ($d = $start; $d <= $end && count($keys) < 1000; $d = $d->modify($step)) {
            $keys[] = $d->format('Y-m-d');
        }
        return $keys;
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $c */
    private function habitsData(int $userId, array $c): array
    {
        $ids  = !empty($c['metric_ids']) ? array_map('intval', $c['metric_ids']) : null;
        $data = $this->habits->summary($userId, $ids, (int) ($c['days'] ?? 7));
        $data['metric_ids'] = $ids ?? array_column($data['habits'], 'id');
        $data['all_habits'] = $ids === null; // any new habit should appear too
        return $data;
    }

    /** @param array<string, mixed> $c */
    private function moodData(int $userId, array $c): array
    {
        $metric = isset($c['metric_id'])
            ? $this->metricOrNull($userId, (int) $c['metric_id'])
            : $this->metrics->ensureMood($userId);
        if ($metric === null) {
            return ['metric_ids' => [], 'error' => 'The metric for this widget was removed. Edit the widget to choose another.'];
        }
        $today = $this->clock->today();
        $days  = (int) ($c['days'] ?? 30);
        $from  = Clock::shiftDate($today, $days - 1);
        $id    = (int) $metric['id'];

        $todayPoints = $this->points->list($userId, $id, $today, $today, 20);
        $recentNotes = array_values(array_filter(
            $this->points->list($userId, $id, $from, $today, 200),
            static fn (array $p): bool => $p['note'] !== null
        ));

        return [
            'metric_ids' => [$id],
            'metric'     => $metric,
            'today'      => $today,
            'latest'     => $todayPoints[0] ?? null,
            'today_count'=> count($todayPoints),
            'series'     => $this->points->series($userId, $id, 'day', 'avg', $from, $today),
            'buckets'    => self::bucketKeys('day', $from, $today),
            'average'    => $this->points->aggregate($userId, $id, 'avg', $from, $today),
            'notes'      => array_slice($recentNotes, 0, 5),
        ];
    }

    /** @param array<string, mixed> $c */
    private function statData(int $userId, array $c): array
    {
        $metric = $this->metricOrNull($userId, (int) $c['metric_id']);
        if ($metric === null) {
            return self::missingMetric();
        }
        $today  = $this->clock->today();
        $period = (string) ($c['period'] ?? '7d');
        $agg    = (string) ($c['agg'] ?? 'last');
        [$from, $to] = self::periodRange($period, $today);

        $value    = $this->points->aggregate($userId, (int) $metric['id'], $agg, $from, $to);
        $prev     = null;
        $prevRange = self::previousRange($period, $today);
        if ($prevRange !== null && $agg !== 'last') {
            $prev = $this->points->aggregate($userId, (int) $metric['id'], $agg, $prevRange[0], $prevRange[1]);
        }

        return [
            'metric_ids' => [(int) $metric['id']],
            'metric'     => $metric,
            'value'      => $value,
            'previous'   => $prev,
            'agg'        => $agg,
            'period'     => $period,
            'decimals'   => (int) ($c['decimals'] ?? 1),
        ];
    }

    /** @param array<string, mixed> $c */
    private function chartData(int $userId, array $c): array
    {
        $today  = $this->clock->today();
        $period = (string) ($c['period'] ?? '30d');
        $bucket = (string) ($c['bucket'] ?? 'day');
        $agg    = (string) ($c['agg'] ?? 'avg');
        [$from, $to] = self::periodRange($period, $today);
        $from ??= Clock::shiftDate($today, 364);

        $series = [];
        $ids    = [];
        foreach ($c['metric_ids'] ?? [] as $mid) {
            $metric = $this->metricOrNull($userId, (int) $mid);
            if ($metric === null) {
                continue;
            }
            $ids[] = (int) $metric['id'];
            $series[] = [
                'metric' => $metric,
                'points' => $this->points->series($userId, (int) $metric['id'], $bucket, $agg, $from, $to),
            ];
        }

        return [
            'metric_ids' => $ids,
            'style'      => (string) ($c['style'] ?? 'line'),
            'bucket'     => $bucket,
            'buckets'    => self::bucketKeys($bucket, $from, $to),
            'series'     => $series,
            'error'      => $series === [] ? 'The metrics for this chart were removed. Edit the widget.' : null,
        ];
    }

    /**
     * Progress of value / goal, capped at 200% (2.0). No reading is empty.
     */
    public static function goalRatio(?float $value, float $goal): float
    {
        if ($value === null || $goal <= 0.0) {
            return 0.0;
        }
        return max(0.0, min(2.0, $value / $goal));
    }

    /** @param array<string, mixed> $c */
    private function goalData(int $userId, array $c): array
    {
        $ids = GoalLog::metricIds($c);
        $view = (string) ($c['view'] ?? 'day');
        if ($ids === []) {
            return self::missingMetric();
        }
        $today = $this->clock->today();
        $goals = [];
        foreach ($ids as $id) {
            $metric = $this->metricOrNull($userId, $id);
            if ($metric === null) {
                continue;
            }
            $goals[] = $this->oneGoal($userId, $c, $metric, $view, $today);
        }
        if ($goals === []) {
            return self::missingMetric();
        }
        $payload = [
            'metric_ids' => array_map(static fn (array $g): int => (int) $g['metric']['id'], $goals),
            'view'       => $view,
            'today'      => $today,
            'goals'      => $goals,
        ];
        $errors = array_values(array_filter(array_map(static fn (array $g): ?string => $g['error'] ?? null, $goals)));
        if (count($errors) === count($goals)) {
            $payload['error'] = $errors[0];
        }
        return $this->mirrorFirstGoal($payload);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $metric
     * @return array<string, mixed>
     */
    private function oneGoal(int $userId, array $config, array $metric, string $view, string $today): array
    {
        $id = (int) $metric['id'];
        $entry = GoalLog::logEntry($config, $metric);
        $mode = GoalLog::mode($metric, $entry);
        $goalRaw = $metric['config']['goal'] ?? null;
        $goal = is_numeric($goalRaw) ? (float) $goalRaw : null;
        $base = [
            'metric' => $metric,
            'mode'   => $mode,
            'icon'   => GoalLog::icon($metric),
        ];
        if ($goal === null || $goal <= 0.0) {
            return $base + ['error' => 'Set a goal on this metric to show progress.'];
        }
        try {
            $rawInc = isset($entry['increments']) ? (string) $entry['increments'] : GoalLog::defaultIncrements($metric, $mode);
            $increments = GoalLog::parseIncrements($rawInc);
        } catch (\DomainException) {
            $increments = GoalLog::parseIncrements(GoalLog::defaultIncrements($metric, $mode));
        }
        $log = [
            'increments' => $increments,
            'dedupe_key' => GoalLog::dedupeKey(GoalLog::prefix($entry, $metric), $today),
            'pips'       => $mode === 'count' ? GoalLog::pipCount($goal) : 0,
        ];
        if ($view === 'week') {
            $start = HabitService::weekStart($today);
            $end = Clock::shiftDate($start, -6);
            $byDate = [];
            foreach ($this->points->series($userId, $id, 'day', 'last', $start, $end) as $row) {
                $byDate[$row['bucket']] = $row['value'];
            }
            $days = [];
            $todayValue = null;
            for ($i = 0; $i < 7; $i++) {
                $date = Clock::shiftDate($start, -$i);
                $value = array_key_exists($date, $byDate) ? (float) $byDate[$date] : null;
                $ratio = self::goalRatio($value, $goal);
                if ($date === $today) {
                    $todayValue = $value;
                }
                $days[] = [
                    'date'    => $date,
                    'label'   => (new \DateTimeImmutable($date . ' 12:00:00 UTC'))->format('D'),
                    'value'   => $value,
                    'ratio'   => $ratio,
                    'percent' => (int) round($ratio * 100),
                    'today'   => $date === $today,
                    'future'  => $date > $today,
                ];
            }
            $ratio = self::goalRatio($todayValue, $goal);
            return $base + $log + [
                'goal'    => $goal,
                'value'   => $todayValue,
                'ratio'   => $ratio,
                'percent' => (int) round($ratio * 100),
                'days'    => $days,
            ];
        }

        $value = $this->points->aggregate($userId, $id, 'last', $today, $today);
        $ratio = self::goalRatio($value, $goal);
        return $base + $log + [
            'goal'    => $goal,
            'value'   => $value,
            'ratio'   => $ratio,
            'percent' => (int) round($ratio * 100),
        ];
    }

    /**
     * The first goal is also copied to the top level so a one-metric widget
     * still reads as value / goal / days.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mirrorFirstGoal(array $payload): array
    {
        $first = $payload['goals'][0] ?? null;
        if (!is_array($first)) {
            return $payload;
        }
        foreach (['metric', 'goal', 'value', 'ratio', 'percent', 'increments', 'dedupe_key', 'days', 'mode', 'icon', 'pips'] as $key) {
            if (array_key_exists($key, $first)) {
                $payload[$key] = $first[$key];
            }
        }
        return $payload;
    }

    /**
     * Add or subtract from today's running total and store it on the metric's
     * daily dedupe key (water_ml uses water:YYYY-MM-DD, movement_sessions uses
     * movement:YYYY-MM-DD). Last value wins. metricId is required when the
     * widget has more than one goal.
     *
     * @return array{dedupe_key: string, value: float, metric_id: int, data: array<string, mixed>}
     */
    public function adjustGoal(int $userId, int $widgetId, mixed $delta, string $source = 'widget', ?int $metricId = null): array
    {
        $widget = $this->widgets->get($userId, $widgetId);
        if ($widget['type'] !== 'goal') {
            throw new ValidationException('Only a goal widget can log this way.');
        }
        if (!is_numeric($delta) || !is_finite((float) $delta) || (float) $delta == 0.0 || abs((float) $delta) > 1e12) {
            throw ValidationException::withFields(['delta' => 'Enter an amount.']);
        }
        if (!in_array($source, DatapointService::SOURCES, true)) {
            throw ValidationException::withFields(['source' => 'Invalid source.']);
        }

        $config = $widget['config'];
        $ids = GoalLog::metricIds($config);
        if ($metricId === null) {
            if (count($ids) !== 1) {
                throw ValidationException::withFields(['metric_id' => 'Choose which goal to log.']);
            }
            $metricId = $ids[0];
        }
        if (!in_array($metricId, $ids, true)) {
            throw ValidationException::withFields(['metric_id' => 'That metric is not on this widget.']);
        }

        $metric = $this->metrics->get($userId, $metricId);
        $today = $this->clock->today();
        $entry = GoalLog::logEntry($config, $metric);
        $key = GoalLog::dedupeKey(GoalLog::prefix($entry, $metric), $today);
        $current = $this->points->valueForDedupe($userId, $metricId, $key);
        if ($current === null) {
            $current = $this->points->aggregate($userId, $metricId, 'last', $today, $today) ?? 0.0;
        }
        $next = max(0.0, $current + (float) $delta);
        $this->points->record($userId, $metricId, [
            'value'      => $next,
            'date'       => $today,
            'source'     => $source,
            'dedupe_key' => $key,
        ]);

        return [
            'dedupe_key' => $key,
            'metric_id'  => $metricId,
            'value'      => $next,
            'data'       => $this->goalData($userId, $config),
        ];
    }

    /** @param array<string, mixed> $c */
    private function gaugeData(int $userId, array $c): array
    {
        $metric = $this->metricOrNull($userId, (int) $c['metric_id']);
        if ($metric === null) {
            return self::missingMetric();
        }
        [$from, $to] = self::periodRange((string) ($c['period'] ?? 'today'), $this->clock->today());
        return [
            'metric_ids' => [(int) $metric['id']],
            'metric'     => $metric,
            'value'      => $this->points->aggregate($userId, (int) $metric['id'], (string) ($c['agg'] ?? 'last'), $from, $to),
            'min'        => (int) ($c['min'] ?? 0),
            'max'        => (int) ($c['max'] ?? 100),
            'period'     => (string) ($c['period'] ?? 'today'),
        ];
    }

    /** @param array<string, mixed> $c */
    private function tableData(int $userId, array $c): array
    {
        $metric = $this->metricOrNull($userId, (int) $c['metric_id']);
        if ($metric === null) {
            return self::missingMetric();
        }
        return [
            'metric_ids' => [(int) $metric['id']],
            'metric'     => $metric,
            'rows'       => $this->points->list($userId, (int) $metric['id'], null, null, (int) ($c['limit'] ?? 10)),
        ];
    }

    /** @param array<string, mixed> $c */
    private function streakData(int $userId, array $c): array
    {
        $metric = $this->metricOrNull($userId, (int) $c['metric_id']);
        if ($metric === null) {
            return self::missingMetric();
        }
        $summary = $this->habits->summary($userId, [(int) $metric['id']], 7);
        return [
            'metric_ids' => [(int) $metric['id']],
            'habit'      => $summary['habits'][0] ?? null,
            'days'       => $summary['days'],
        ];
    }

    /** @return array<string, mixed> */
    private function lifeData(int $userId): array
    {
        $rows = $this->life->today($userId);
        return [
            'metric_ids' => array_map(static fn (array $row): int => (int) $row['metric']['id'], $rows),
            'today'      => $this->clock->today(),
            'rows'       => $rows,
        ];
    }

    /** @param array<string, mixed> $c */
    private function spendingData(int $userId, array $c): array
    {
        $today = $this->clock->today();
        $period = (string) ($c['period'] ?? '30d');
        [$from, $to] = self::periodRange($period, $today);
        $summary = $this->money->summary($userId, $from ?? Clock::shiftDate($today, 3640), $to);
        return [
            'metric_ids' => [],
            'period'     => $period,
            'spent'      => $summary['spent'],
            'categories' => array_slice($summary['categories'], 0, 6),
            'from'       => $summary['from'],
            'to'         => $summary['to'],
        ];
    }

    /** @return array<string, mixed> */
    private function networthData(int $userId): array
    {
        $today = $this->clock->today();
        $summary = $this->money->summary($userId, Clock::shiftDate($today, 364), $today);
        return [
            'metric_ids' => [],
            'net_worth'  => $summary['net_worth'],
            'accounts'   => $summary['accounts'],
            'snapshots'  => $summary['snapshots'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function planCard(array $payload): array
    {
        $payload['metric_ids'] = [];
        return $payload;
    }

    /** @return array<string, mixed> */
    private function portfolioData(int $userId): array
    {
        $card = $this->invest->scorecard($userId);
        $card['metric_ids'] = [];
        $card['positions'] = array_slice($card['positions'], 0, 8);
        $card['activity'] = array_slice($card['activity'], 0, 5);
        return $card;
    }

    /** @param array<string, mixed> $c */
    private function pagesData(int $userId, array $c): array
    {
        return [
            'metric_ids' => [],
            'pages'      => $this->pages->recent($userId, (int) ($c['limit'] ?? 8)),
        ];
    }

    /** @param array<string, mixed> $c */
    private function customData(int $userId, array $c): array
    {
        $metrics = [];
        $ids = [];
        foreach ($c['metric_ids'] ?? [] as $mid) {
            $metric = $this->metricOrNull($userId, (int) $mid);
            if ($metric === null) {
                continue;
            }
            $ids[] = (int) $metric['id'];
            $latest = $this->points->list($userId, (int) $metric['id'], null, null, 1);
            $metrics[] = [
                'id'    => (int) $metric['id'],
                'key'   => $metric['key'],
                'name'  => $metric['name'],
                'unit'  => $metric['unit'],
                'kind'  => $metric['kind'],
                'last'  => $latest[0]['value'] ?? null,
                'date'  => $latest[0]['date'] ?? null,
            ];
        }
        return [
            'metric_ids' => $ids,
            'today'      => $this->clock->today(),
            'metrics'    => $metrics,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function metricOrNull(int $userId, int $id): ?array
    {
        try {
            $m = $this->metrics->get($userId, $id);
            return $m['archived'] ? null : $m;
        } catch (NotFoundException) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function missingMetric(): array
    {
        return ['metric_ids' => [], 'error' => 'The metric for this widget was archived or removed. Edit the widget to choose another.'];
    }
}
