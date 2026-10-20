<?php

declare(strict_types=1);

namespace Dashboard\Widgets;

use Dashboard\Media\MediaSources;
use Dashboard\Support\ValidationException;

/**
 * WidgetTypeRegistry — every widget type the grid can hold, with a strict
 * config schema. The same catalogue drives the "Add widget" menu, the config
 * editor, server-side validation and (Phase L2) the MCP tools the AI uses, so
 * a type is defined exactly once, here.
 *
 * Field types:
 *   int      {min, max, default}
 *   enum     {options: [value => label], default}
 *   string   {max, default}
 *   bool     {default}
 *   metric   one metric id owned by the user   {kinds?: list<kind>, required?}
 *   metrics  list of metric ids                {kinds?, min_items?, max_items}
 */
final class WidgetTypeRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function types(): array
    {
        $periods = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '365d' => 'Last year', 'all' => 'All time'];
        $aggs    = ['last' => 'Latest', 'avg' => 'Average', 'sum' => 'Total', 'min' => 'Minimum', 'max' => 'Maximum', 'count' => 'Count'];

        return [
            // ---------------- native (L1) ----------------
            'habits' => [
                'label' => 'Habits', 'category' => 'tracking', 'singleton' => false,
                'description' => 'Tap to check off today\'s habits; streaks and a recent-days strip.',
                'size' => ['w' => 4, 'h' => 5], 'min' => ['w' => 3, 'h' => 3],
                'fields' => [
                    'metric_ids' => ['type' => 'metrics', 'label' => 'Habits (empty = all)', 'kinds' => ['habit'], 'min_items' => 0, 'max_items' => 20],
                    'days'       => ['type' => 'enum', 'label' => 'History', 'options' => ['7' => '7 days', '14' => '14 days'], 'default' => '7'],
                ],
            ],
            'mood' => [
                'label' => 'Mood', 'category' => 'tracking', 'singleton' => false,
                'description' => 'Log how you feel (1–5) with an optional note; recent trend.',
                'size' => ['w' => 3, 'h' => 3], 'min' => ['w' => 2, 'h' => 2],
                'fields' => [
                    'metric_id' => ['type' => 'metric', 'label' => 'Scale metric (empty = Mood)', 'kinds' => ['scale']],
                    'days'      => ['type' => 'enum', 'label' => 'Trend', 'options' => ['14' => '14 days', '30' => '30 days', '90' => '90 days'], 'default' => '30'],
                ],
            ],
            'stat' => [
                'label' => 'Stat', 'category' => 'data', 'singleton' => false,
                'description' => 'One number: latest, average or total of a metric over a period.',
                'size' => ['w' => 3, 'h' => 3], 'min' => ['w' => 2, 'h' => 2],
                'fields' => [
                    'metric_id' => ['type' => 'metric', 'label' => 'Metric', 'required' => true],
                    'agg'       => ['type' => 'enum', 'label' => 'Show', 'options' => $aggs, 'default' => 'last'],
                    'period'    => ['type' => 'enum', 'label' => 'Period', 'options' => $periods, 'default' => '7d'],
                    'decimals'  => ['type' => 'int', 'label' => 'Decimals', 'min' => 0, 'max' => 4, 'default' => 1],
                ],
            ],
            'chart' => [
                'label' => 'Chart', 'category' => 'data', 'singleton' => false,
                'description' => 'Line or bar chart of up to 4 metrics.',
                'size' => ['w' => 6, 'h' => 5], 'min' => ['w' => 3, 'h' => 3],
                'fields' => [
                    'metric_ids' => ['type' => 'metrics', 'label' => 'Metrics', 'min_items' => 1, 'max_items' => 4],
                    'style'      => ['type' => 'enum', 'label' => 'Style', 'options' => ['line' => 'Line', 'bar' => 'Bar'], 'default' => 'line'],
                    'period'     => ['type' => 'enum', 'label' => 'Period', 'options' => ['7d' => '7 days', '30d' => '30 days', '90d' => '90 days', '365d' => '1 year'], 'default' => '30d'],
                    'bucket'     => ['type' => 'enum', 'label' => 'Group by', 'options' => ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'], 'default' => 'day'],
                    'agg'        => ['type' => 'enum', 'label' => 'Combine', 'options' => $aggs, 'default' => 'avg'],
                ],
            ],
            'goal' => [
                'label' => 'Goals', 'category' => 'tracking', 'singleton' => false,
                'description' => 'One to four number metrics in a single card. Each row shows today, or seven days, against its goal (full at 100%, a different shade through 200%). Amount metrics tap to add; count metrics use pips.',
                'size' => ['w' => 3, 'h' => 3], 'min' => ['w' => 2, 'h' => 2],
                'fields' => [
                    'metric_ids' => ['type' => 'metrics', 'label' => 'Goals', 'kinds' => ['number'], 'min_items' => 1, 'max_items' => 4],
                    'view'       => ['type' => 'enum', 'label' => 'View', 'options' => ['day' => 'Today', 'week' => 'This week'], 'default' => 'day'],
                    'logs'       => ['type' => 'goal_logs', 'label' => 'Quick-add'],
                ],
            ],
            'gauge' => [
                'label' => 'Gauge', 'category' => 'data', 'singleton' => false,
                'description' => 'Progress toward a range, e.g. steps vs goal.',
                'size' => ['w' => 3, 'h' => 4], 'min' => ['w' => 2, 'h' => 3],
                'fields' => [
                    'metric_id' => ['type' => 'metric', 'label' => 'Metric', 'required' => true],
                    'agg'       => ['type' => 'enum', 'label' => 'Value', 'options' => $aggs, 'default' => 'last'],
                    'period'    => ['type' => 'enum', 'label' => 'Period', 'options' => $periods, 'default' => 'today'],
                    'min'       => ['type' => 'int', 'label' => 'Min', 'min' => -1000000000, 'max' => 1000000000, 'default' => 0],
                    'max'       => ['type' => 'int', 'label' => 'Max', 'min' => -1000000000, 'max' => 1000000000, 'default' => 100],
                ],
            ],
            'table' => [
                'label' => 'Recent entries', 'category' => 'data', 'singleton' => false,
                'description' => 'The latest values of a metric, with notes.',
                'size' => ['w' => 4, 'h' => 5], 'min' => ['w' => 3, 'h' => 3],
                'fields' => [
                    'metric_id' => ['type' => 'metric', 'label' => 'Metric', 'required' => true],
                    'limit'     => ['type' => 'int', 'label' => 'Rows', 'min' => 3, 'max' => 50, 'default' => 10],
                ],
            ],
            'streak' => [
                'label' => 'Streak', 'category' => 'tracking', 'singleton' => false,
                'description' => 'Current and best streak for one habit.',
                'size' => ['w' => 3, 'h' => 3], 'min' => ['w' => 2, 'h' => 2],
                'fields' => [
                    'metric_id' => ['type' => 'metric', 'label' => 'Habit', 'kinds' => ['habit'], 'required' => true],
                ],
            ],
            'life' => [
                'label' => 'Today', 'category' => 'life', 'singleton' => false,
                'description' => 'Sleep, steps, exercise, weight and travel for today.',
                'size' => ['w' => 4, 'h' => 5], 'min' => ['w' => 3, 'h' => 3],
                'fields' => [],
            ],
            'calls' => [
                'label' => 'Call log', 'category' => 'life', 'singleton' => false,
                'description' => 'Open returns first, with urgent, VIP, and debt-collector badges. Tap a row for the summary.',
                'size' => ['w' => 4, 'h' => 6], 'min' => ['w' => 3, 'h' => 4],
                'fields' => [
                    'limit' => ['type' => 'int', 'label' => 'Rows', 'min' => 3, 'max' => 40, 'default' => 16],
                ],
            ],
            'shopping' => [
                'label' => 'Shopping', 'category' => 'life', 'singleton' => false,
                'description' => 'Lists such as Groceries or Costco. Quick-add parses “2 lb chicken”, groups by aisle, and checks items off.',
                'size' => ['w' => 4, 'h' => 6], 'min' => ['w' => 3, 'h' => 4],
                'fields' => [
                    'list_id'     => ['type' => 'int', 'label' => 'One list (0 = tabs for every list)', 'min' => 0, 'max' => 1000000000, 'default' => 0],
                    'clear_hours' => ['type' => 'int', 'label' => 'Clear bought items after (hours, 0 = keep)', 'min' => 0, 'max' => 168, 'default' => 24],
                ],
            ],
            'pantry' => [
                'label' => 'Pantry', 'category' => 'life', 'singleton' => false,
                'description' => 'Counts by fridge, freezer, and pantry, plus items to use soon and anything below its par level.',
                'size' => ['w' => 4, 'h' => 4], 'min' => ['w' => 3, 'h' => 3],
                'fields' => [
                    'soon_days' => ['type' => 'int', 'label' => 'Use soon within (days)', 'min' => 1, 'max' => 30, 'default' => 3],
                ],
            ],
            'spending' => [
                'label' => 'Spending', 'category' => 'money', 'singleton' => false,
                'description' => 'Outflows by category over a period.',
                'size' => ['w' => 4, 'h' => 5], 'min' => ['w' => 3, 'h' => 3],
                'fields' => [
                    'period' => ['type' => 'enum', 'label' => 'Period', 'options' => $periods, 'default' => '30d'],
                ],
            ],
            'networth' => [
                'label' => 'Net worth trend', 'category' => 'money', 'singleton' => false,
                'description' => 'Net worth over the last year, plus account balances.',
                'size' => ['w' => 8, 'h' => 4], 'min' => ['w' => 4, 'h' => 3],
                'fields' => [],
            ],
            'baby_step' => [
                'label' => 'Baby Step', 'category' => 'money', 'singleton' => false,
                'description' => 'Which of the seven Baby Steps you are on.',
                'size' => ['w' => 4, 'h' => 4], 'min' => ['w' => 3, 'h' => 2],
                'fields' => [],
            ],
            'debt_snowball' => [
                'label' => 'Debt snowball', 'category' => 'money', 'singleton' => false,
                'description' => 'Debts in payoff order, with the target date and an estimate from the minimum payment.',
                'size' => ['w' => 6, 'h' => 6], 'min' => ['w' => 4, 'h' => 2],
                'fields' => [],
            ],
            'food_week' => [
                'label' => 'Food this week', 'category' => 'money', 'singleton' => false,
                'description' => 'This week\'s Food spending against the weekly cap.',
                'size' => ['w' => 3, 'h' => 3], 'min' => ['w' => 2, 'h' => 2],
                'fields' => [],
            ],
            'budget_actual' => [
                'label' => 'Budget vs actual', 'category' => 'money', 'singleton' => false,
                'description' => 'This month\'s planned lines against what was spent.',
                'size' => ['w' => 6, 'h' => 5], 'min' => ['w' => 4, 'h' => 2],
                'fields' => [],
            ],
            'sinking_funds' => [
                'label' => 'Sinking funds', 'category' => 'money', 'singleton' => false,
                'description' => 'Saved versus target for each sinking fund.',
                'size' => ['w' => 6, 'h' => 5], 'min' => ['w' => 4, 'h' => 2],
                'fields' => [],
            ],
            'bills_due' => [
                'label' => 'Bills due', 'category' => 'money', 'singleton' => false,
                'description' => 'Budget lines and debts whose due day falls in the next 10 days.',
                'size' => ['w' => 4, 'h' => 4], 'min' => ['w' => 3, 'h' => 2],
                'fields' => [],
            ],
            'retirement' => [
                'label' => 'Retirement', 'category' => 'money', 'singleton' => false,
                'description' => 'Contribution rate next to the 15% target.',
                'size' => ['w' => 4, 'h' => 3], 'min' => ['w' => 3, 'h' => 2],
                'fields' => [],
            ],
            'credit' => [
                'label' => 'Credit', 'category' => 'money', 'singleton' => false,
                'description' => 'Latest credit score and card utilization.',
                'size' => ['w' => 4, 'h' => 3], 'min' => ['w' => 3, 'h' => 2],
                'fields' => [],
            ],
            'portfolio' => [
                'label' => 'Portfolio', 'category' => 'money', 'singleton' => false,
                'description' => 'Read-only positions, unrealized result, recent activity.',
                'size' => ['w' => 6, 'h' => 6], 'min' => ['w' => 3, 'h' => 4],
                'fields' => [],
            ],
            'pages' => [
                'label' => 'Knowledge', 'category' => 'knowledge', 'singleton' => false,
                'description' => 'Recently touched pages in the vault.',
                'size' => ['w' => 4, 'h' => 5], 'min' => ['w' => 3, 'h' => 3],
                'fields' => [
                    'limit' => ['type' => 'int', 'label' => 'Pages', 'min' => 3, 'max' => 20, 'default' => 8],
                ],
            ],
            'youtube' => [
                'label' => 'YouTube', 'category' => 'media', 'singleton' => false,
                'description' => 'Latest videos from channels you choose. Cards rotate with a wipe or push. No embedded player.',
                'size' => ['w' => 4, 'h' => 5], 'min' => ['w' => 3, 'h' => 4],
                'fields' => self::carouselFields(
                    'Channels',
                    "One per line\n@handle\nhttps://www.youtube.com/channel/UC…",
                    true
                ),
            ],
            'reddit' => [
                'label' => 'Reddit', 'category' => 'media', 'singleton' => false,
                'description' => 'Latest posts from subreddits you choose. Cards rotate with a wipe or push.',
                'size' => ['w' => 4, 'h' => 5], 'min' => ['w' => 3, 'h' => 4],
                'fields' => self::carouselFields('Subreddits', "One per line\nnews\nr/programming", false),
            ],
            'reddit_feed' => [
                'label' => 'Reddit reader', 'category' => 'media', 'singleton' => false,
                'description' => 'Recent posts from the subreddits you track on Settings → Reddit.',
                'size' => ['w' => 6, 'h' => 6], 'min' => ['w' => 3, 'h' => 4],
                'fields' => [
                    'subreddit' => ['type' => 'string', 'label' => 'One subreddit (blank = all tracked)', 'max' => 21, 'default' => '', 'placeholder' => 'programming'],
                    'limit'     => ['type' => 'int', 'label' => 'Posts', 'min' => 3, 'max' => 40, 'default' => 12],
                ],
            ],
            'x' => [
                'label' => 'X', 'category' => 'media', 'singleton' => false,
                'description' => 'Recent posts from accounts you choose. Needs X_BEARER_TOKEN on the server. Cards rotate with a wipe or push.',
                'size' => ['w' => 4, 'h' => 5], 'min' => ['w' => 3, 'h' => 4],
                'fields' => self::carouselFields('Accounts', "One per line\n@handle", false),
            ],
            'custom' => [
                'label' => 'Custom', 'category' => 'custom', 'singleton' => false,
                'description' => 'Sandboxed markup. window.__DATA__ holds the metrics you attach. Scripts cannot reach the parent page.',
                'size' => ['w' => 4, 'h' => 4], 'min' => ['w' => 2, 'h' => 2],
                'fields' => [
                    'markup'     => ['type' => 'string', 'label' => 'Markup', 'max' => 40000, 'default' => '<p style="margin:12px;letter-spacing:.08em;text-transform:uppercase;font-size:11px;opacity:.7">Custom</p>'],
                    'metric_ids' => ['type' => 'metrics', 'label' => 'Metrics to pass in', 'min_items' => 0, 'max_items' => 8],
                ],
            ],

            // ---------------- legacy (personal-dashboard) ----------------
            'legacy.bookmarks'  => self::legacy('Bookmarks', 'Folders of links with favicons.', 4, 6),
            'legacy.weather'    => self::legacy('Weather', 'Current conditions and forecast.', 3, 3, 2, 2),
            'legacy.flights'    => self::legacy('Flights', 'Flight watches (ADS-B / FlightAware).', 4, 5),
            'legacy.packages'   => self::legacy('Packages', 'Package tracking.', 4, 5),
            'legacy.pings'      => self::legacy('System status', 'Ping / HTTP checks.', 4, 5),
            'legacy.stocks'     => self::legacy('Stocks', 'Stock watchlist.', 4, 5),
            'legacy.todos'      => self::legacy('Todos', 'Todo lists with tabs.', 3, 4, 2, 2),
            'legacy.clipboards' => self::legacy('Cloud clipboards', 'Pinned clipboards with Markdown preview.', 12, 6),
            'legacy.news'       => self::legacy('News feed', 'Merged RSS feed of your pinned sources.', 12, 8),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $type): array
    {
        $types = self::types();
        if (!isset($types[$type])) {
            throw ValidationException::withFields(['type' => "Unknown widget type '{$type}'."]);
        }
        return $types[$type];
    }

    public static function exists(string $type): bool
    {
        return isset(self::types()[$type]);
    }

    /**
     * Shared config for the rotating YouTube, Reddit, and X cards.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function carouselFields(string $sourcesLabel, string $placeholder, bool $shorts): array
    {
        $fields = [
            'sources'  => ['type' => 'string', 'label' => $sourcesLabel, 'max' => 4000, 'default' => '', 'setup' => true, 'placeholder' => $placeholder],
            'include'  => ['type' => 'string', 'label' => 'Only items containing', 'max' => 200, 'default' => '', 'placeholder' => 'apple, keynote'],
            'exclude'  => ['type' => 'string', 'label' => 'Hide items containing', 'max' => 200, 'default' => '', 'placeholder' => 'giveaway'],
            'interval' => ['type' => 'enum', 'label' => 'Rotate every', 'options' => ['8' => '8 seconds', '12' => '12 seconds', '20' => '20 seconds'], 'default' => '12'],
            'motion'   => ['type' => 'enum', 'label' => 'Motion', 'options' => ['wipe' => 'Wipe', 'push' => 'Push'], 'default' => 'wipe'],
            'limit'    => ['type' => 'int', 'label' => 'Cards in rotation', 'min' => 1, 'max' => 20, 'default' => 8],
        ];
        if ($shorts) {
            $fields['hide_shorts'] = ['type' => 'bool', 'label' => 'Hide Shorts when the feed marks them', 'default' => false];
        }
        return $fields;
    }

    public static function isLegacy(string $type): bool
    {
        return str_starts_with($type, 'legacy.');
    }

    /**
     * Validate and normalise a widget config. Unknown keys are rejected;
     * missing optional keys get their defaults.
     *
     * @param array<string, mixed> $config
     * @param callable(int): ?array<string, mixed> $findMetric returns the user's metric or null
     * @return array<string, mixed>
     */
    public static function validateConfig(string $type, array $config, callable $findMetric): array
    {
        $def    = self::get($type);
        $fields = $def['fields'] ?? [];

        if ($type === 'goal') {
            $config = GoalLog::acceptLegacy($config);
        }

        $unknown = array_diff(array_keys($config), array_keys($fields));
        if ($unknown !== []) {
            throw ValidationException::withFields(['config' => 'Unknown config keys for ' . $type . ': ' . implode(', ', $unknown) . '.']);
        }

        $out    = [];
        $errors = [];
        foreach ($fields as $name => $f) {
            $present = array_key_exists($name, $config) && $config[$name] !== null && $config[$name] !== '';
            $value   = $present ? $config[$name] : ($f['default'] ?? null);
            $path    = 'config.' . $name;

            try {
                switch ($f['type']) {
                    case 'int':
                        if ($value === null) {
                            break;
                        }
                        $i = filter_var($value, FILTER_VALIDATE_INT);
                        if ($i === false || $i < $f['min'] || $i > $f['max']) {
                            throw new \DomainException("Must be an integer {$f['min']}–{$f['max']}.");
                        }
                        $out[$name] = $i;
                        break;

                    case 'enum':
                        if ($value === null) {
                            break;
                        }
                        $s = (string) $value;
                        if (!array_key_exists($s, $f['options'])) {
                            throw new \DomainException('Must be one of: ' . implode(', ', array_keys($f['options'])) . '.');
                        }
                        $out[$name] = $s;
                        break;

                    case 'string':
                        if ($value === null) {
                            break;
                        }
                        $s = trim((string) $value);
                        if (mb_strlen($s) > ($f['max'] ?? 255)) {
                            throw new \DomainException('Too long (max ' . ($f['max'] ?? 255) . ').');
                        }
                        if ($s !== '' && !empty($f['pattern']) && !preg_match($f['pattern'], $s)) {
                            throw new \DomainException('Use letters, digits, and . _ - only.');
                        }
                        $out[$name] = $s;
                        break;

                    case 'bool':
                        if ($value === null) {
                            break;
                        }
                        $out[$name] = filter_var($value, FILTER_VALIDATE_BOOL);
                        break;

                    case 'metric':
                        if ($value === null) {
                            if (!empty($f['required'])) {
                                throw new \DomainException('Choose a metric.');
                            }
                            break;
                        }
                        $out[$name] = self::checkMetric($value, $f, $findMetric);
                        break;

                    case 'metrics':
                        $list = $value ?? [];
                        if (!is_array($list) || !array_is_list($list)) {
                            throw new \DomainException('Must be a list of metric ids.');
                        }
                        $min = $f['min_items'] ?? 0;
                        $max = $f['max_items'] ?? 20;
                        if (count($list) < $min || count($list) > $max) {
                            throw new \DomainException("Choose {$min}–{$max} metrics.");
                        }
                        $ids = [];
                        foreach ($list as $v) {
                            $ids[] = self::checkMetric($v, $f, $findMetric);
                        }
                        $out[$name] = array_values(array_unique($ids));
                        break;

                    case 'goal_logs':
                        $out[$name] = self::goalLogs($value);
                        break;

                    default:
                        throw new \LogicException("Unhandled field type {$f['type']}.");
                }
            } catch (\DomainException $e) {
                $errors[$path] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withFields($errors);
        }

        // Cross-field rules.
        if ($type === 'gauge' && ($out['max'] ?? 100) <= ($out['min'] ?? 0)) {
            throw ValidationException::withFields(['config.max' => 'Max must be greater than min.']);
        }
        if (in_array($type, ['youtube', 'reddit', 'x'], true)) {
            MediaSources::parse($type, (string) ($out['sources'] ?? ''));
        }
        if ($type === 'goal') {
            $out['logs'] = self::fillGoalLogs($out['metric_ids'] ?? [], $out['logs'] ?? [], $findMetric);
        }

        return $out;
    }

    /**
     * Metric ids a (validated) config depends on — used to route realtime updates.
     *
     * @param array<string, mixed> $config
     * @return list<int>
     */
    public static function metricRefs(string $type, array $config): array
    {
        if ($type === 'goal') {
            return GoalLog::metricIds($config);
        }
        $ids = [];
        foreach (self::get($type)['fields'] ?? [] as $name => $f) {
            if ($f['type'] === 'metric' && isset($config[$name])) {
                $ids[] = (int) $config[$name];
            } elseif ($f['type'] === 'metrics' && isset($config[$name]) && is_array($config[$name])) {
                foreach ($config[$name] as $id) {
                    $ids[] = (int) $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Public catalogue (for the UI and API) — without internal keys.
     *
     * @return list<array<string, mixed>>
     */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::types() as $type => $def) {
            $out[] = ['type' => $type] + $def;
        }
        return $out;
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function legacy(string $label, string $description, int $w, int $h, int $minW = 3, int $minH = 2): array
    {
        return [
            'label' => $label, 'category' => 'dashboard', 'singleton' => true,
            'description' => $description,
            'size' => ['w' => $w, 'h' => $h], 'min' => ['w' => $minW, 'h' => $minH],
            'fields' => [],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function goalLogs(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \DomainException('Quick-add settings must be an object keyed by metric id.');
        }
        $out = [];
        foreach ($value as $key => $row) {
            $id = filter_var($key, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                throw new \DomainException('Quick-add keys must be metric ids.');
            }
            if (!is_array($row) || array_is_list($row)) {
                throw new \DomainException('Each quick-add entry must be an object.');
            }
            $out[(string) $id] = GoalLog::cleanLogRow($row);
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @param array<string, array<string, mixed>> $logs
     * @param callable(int): ?array<string, mixed> $findMetric
     * @return array<string, array<string, mixed>>
     */
    private static function fillGoalLogs(array $ids, array $logs, callable $findMetric): array
    {
        foreach (array_keys($logs) as $key) {
            if (!in_array((int) $key, $ids, true)) {
                throw ValidationException::withFields(['config.logs' => 'Quick-add settings must match the chosen metrics.']);
            }
        }
        $filled = [];
        foreach ($ids as $id) {
            $entry = $logs[(string) $id] ?? [];
            $metric = $findMetric($id) ?? ['id' => $id];
            if (!isset($entry['increments'])) {
                $entry['increments'] = GoalLog::defaultIncrements($metric, GoalLog::mode($metric, $entry));
            }
            $filled[(string) $id] = $entry;
        }
        return $filled;
    }

    /**
     * @param array<string, mixed> $f
     * @param callable(int): ?array<string, mixed> $findMetric
     */
    private static function checkMetric(mixed $value, array $f, callable $findMetric): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new \DomainException('Invalid metric id.');
        }
        $metric = $findMetric($id);
        if ($metric === null) {
            throw new \DomainException("Metric {$id} not found.");
        }
        if (isset($f['kinds']) && !in_array($metric['kind'], $f['kinds'], true)) {
            throw new \DomainException("Metric '{$metric['name']}' must be of kind: " . implode(', ', $f['kinds']) . '.');
        }
        return $id;
    }
}
