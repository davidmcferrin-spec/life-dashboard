<?php

declare(strict_types=1);

namespace Dashboard\Widgets;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Tracking\DatapointService;
use Dashboard\Tracking\HabitService;
use Dashboard\Tracking\MetricService;

/**
 * System vitals and today's agenda for the overview widgets.
 * The numbers come from rows the user already has. Nothing is invented.
 */
final class OverviewCards
{
    public function __construct(
        private readonly Database $db,
        private readonly HabitService $habits,
        private readonly MetricService $metrics,
        private readonly DatapointService $points,
        private readonly Clock $clock,
    ) {}

    /**
     * @return array{note: ?string, vitals: list<array<string, mixed>>, agenda: list<array<string, mixed>>}
     */
    public function board(int $userId): array
    {
        try {
            return [
                'note'   => null,
                'vitals' => $this->vitals($userId),
                'agenda' => $this->agenda($userId),
            ];
        } catch (\Throwable $e) {
            error_log('Overview widgets: ' . $e->getMessage());
            return [
                'note'   => 'Vitals and agenda could not be loaded.',
                'vitals' => [],
                'agenda' => [],
            ];
        }
    }

    /**
     * The three links that used to sit under the search box.
     *
     * @return list<array{title: string, line: string, href: string}>
     */
    public static function actions(): array
    {
        return [
            ['title' => 'Brief my day', 'line' => 'Grok Digest', 'href' => '/digest'],
            ['title' => 'Analyze systems', 'line' => 'System status', 'href' => '/settings/pings'],
            ['title' => 'Optimize energy', 'line' => 'Sleep, steps, and exercise', 'href' => '/settings/life'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vitals(int $userId): array
    {
        $today = $this->clock->today();
        $vitals = [];

        $habitSummary = $this->habits->summary($userId, null, 1);
        $habitRows = $habitSummary['habits'] ?? [];
        if (is_array($habitRows) && $habitRows !== []) {
            $habitTotal = count($habitRows);
            $habitDone = 0;
            foreach ($habitRows as $habit) {
                if (!empty($habit['done_today'])) {
                    $habitDone++;
                }
            }
            $vitals[] = [
                'label'  => 'Habits',
                'detail' => $habitDone . ' of ' . $habitTotal . ' today',
                'meter'  => ['min' => 0, 'max' => $habitTotal, 'now' => $habitDone, 'pct' => self::bar((float) $habitDone, (float) $habitTotal)],
            ];
        }

        $pingRows = $this->db->fetchAll(
            'SELECT is_up FROM user_ping_targets WHERE user_id = ? AND is_active = 1',
            [$userId]
        );
        if ($pingRows !== []) {
            $pingUp = 0;
            $pingChecked = 0;
            foreach ($pingRows as $ping) {
                if ($ping['is_up'] === null) {
                    continue;
                }
                $pingChecked++;
                if ((int) $ping['is_up'] === 1) {
                    $pingUp++;
                }
            }
            $vitals[] = [
                'label'  => 'Pings',
                'detail' => $pingChecked === 0 ? 'Not checked' : $pingUp . ' of ' . $pingChecked . ' up',
                'meter'  => $pingChecked === 0 ? null : ['min' => 0, 'max' => $pingChecked, 'now' => $pingUp, 'pct' => self::bar((float) $pingUp, (float) $pingChecked)],
            ];
        }

        foreach ($this->metrics->list($userId) as $metric) {
            if (count($vitals) >= 4) {
                break;
            }
            $kind = (string) $metric['kind'];
            if ($kind === 'habit') {
                continue;
            }
            $config = is_array($metric['config']) ? $metric['config'] : [];
            try {
                $points = $this->points->list($userId, (int) $metric['id'], $today, $today, 1);
            } catch (\Throwable) {
                continue;
            }
            $latest = $points[0] ?? null;
            if ($latest === null) {
                continue;
            }
            $value = (float) $latest['value'];
            $name = (string) $metric['name'];
            if ($kind === 'scale') {
                $min = isset($config['min']) ? (int) $config['min'] : 1;
                $max = isset($config['max']) ? (int) $config['max'] : 5;
                if ($max <= $min) {
                    continue;
                }
                $vitals[] = [
                    'label'  => $name,
                    'detail' => self::formatNum($value, 0) . ' / ' . $max,
                    'meter'  => ['min' => $min, 'max' => $max, 'now' => $value, 'pct' => self::bar($value - $min, (float) ($max - $min))],
                ];
                continue;
            }
            if ($kind === 'number') {
                $direction = (string) ($config['goal_direction'] ?? 'up');
                if ($direction === 'down' || !isset($config['goal']) || !is_numeric($config['goal']) || (float) $config['goal'] <= 0) {
                    continue;
                }
                $goal = (float) $config['goal'];
                $decimals = isset($config['decimals']) && is_numeric($config['decimals'])
                    ? max(0, min(4, (int) $config['decimals']))
                    : (abs($value - round($value)) < 0.001 ? 0 : 1);
                $unit = is_string($metric['unit'] ?? null) ? trim((string) $metric['unit']) : '';
                $detail = self::formatNum($value, $decimals) . ' of ' . self::formatNum($goal, $decimals);
                if ($unit !== '') {
                    $detail .= ' ' . $unit;
                }
                $vitals[] = [
                    'label'  => $name,
                    'detail' => $detail,
                    'meter'  => ['min' => 0, 'max' => max($goal, $value), 'now' => $value, 'pct' => self::bar($value, $goal)],
                ];
                continue;
            }
            if ($kind === 'duration' && isset($config['goal_seconds']) && is_numeric($config['goal_seconds']) && (int) $config['goal_seconds'] > 0) {
                $goalSeconds = (int) $config['goal_seconds'];
                $vitals[] = [
                    'label'  => $name,
                    'detail' => self::formatDur($value) . ' of ' . self::formatDur((float) $goalSeconds),
                    'meter'  => ['min' => 0, 'max' => max($goalSeconds, $value), 'now' => $value, 'pct' => self::bar($value, (float) $goalSeconds)],
                ];
            }
        }

        return array_slice($vitals, 0, 4);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function agenda(int $userId): array
    {
        $today = $this->clock->today();
        $tomorrow = Clock::shiftDate($today, -1);
        $tz = $this->clock->timezone();
        $agenda = [];

        $todoRows = $this->db->fetchAll(
            'SELECT i.id, i.title, i.due_date
             FROM user_todo_items i
             JOIN user_todo_lists l ON l.id = i.list_id
             WHERE i.status = \'open\'
               AND i.due_date IS NOT NULL
               AND i.due_date <= ?
               AND (
                    l.user_id = ?
                    OR i.assigned_to_user_id = ?
                    OR EXISTS (
                        SELECT 1 FROM todo_list_user_shares s
                        WHERE s.list_id = l.id AND s.shared_with_user_id = ?
                    )
               )
             ORDER BY i.due_date ASC, i.id ASC
             LIMIT 8',
            [$today, $userId, $userId, $userId]
        );
        foreach ($todoRows as $todo) {
            $dueDay = substr((string) $todo['due_date'], 0, 10);
            $dueDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $dueDay);
            if ($dueDt === false) {
                continue;
            }
            $timeLabel = $dueDay === $today
                ? 'Today'
                : ($dueDt->format('Y') === substr($today, 0, 4) ? $dueDt->format('M j') : $dueDt->format('M j, Y'));
            $agenda[] = [
                'sort'     => ($dueDay === $today ? '0 ' : '1 ') . $dueDay . ' 00:00:00',
                'time'     => $timeLabel,
                'datetime' => $dueDay,
                'title'    => (string) $todo['title'],
                'href'     => '/todos',
            ];
        }

        $flightRows = $this->db->fetchAll(
            'SELECT ident, departure_at, origin_display, dest_display, origin_icao, dest_icao
             FROM user_flight_watches
             WHERE user_id = ? AND is_active = 1 AND is_complete = 0
               AND departure_at >= ? AND departure_at < ?
             ORDER BY departure_at ASC
             LIMIT 8',
            [$userId, $today . ' 00:00:00', $tomorrow . ' 00:00:00']
        );
        foreach ($flightRows as $flight) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr((string) $flight['departure_at'], 0, 19), $tz);
            if ($parsed === false) {
                continue;
            }
            $origin = trim((string) ($flight['origin_display'] ?: $flight['origin_icao'] ?: ''));
            $dest = trim((string) ($flight['dest_display'] ?: $flight['dest_icao'] ?: ''));
            $title = (string) $flight['ident'];
            if ($origin !== '' && $dest !== '') {
                $title .= ' ' . $origin . '–' . $dest;
            }
            $agenda[] = [
                'sort'     => '0 ' . $parsed->format('Y-m-d H:i:s'),
                'time'     => $parsed->format('g:i A'),
                'datetime' => $parsed->format('Y-m-d\TH:i:s'),
                'title'    => $title,
                'href'     => '/settings/flights',
            ];
        }

        $packageRows = $this->db->fetchAll(
            'SELECT label, status_label, estimated_delivery_at
             FROM user_package_watches
             WHERE user_id = ? AND is_active = 1 AND is_complete = 0
               AND estimated_delivery_at IS NOT NULL
               AND estimated_delivery_at >= ? AND estimated_delivery_at < ?
             ORDER BY estimated_delivery_at ASC
             LIMIT 8',
            [$userId, $today . ' 00:00:00', $tomorrow . ' 00:00:00']
        );
        foreach ($packageRows as $package) {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr((string) $package['estimated_delivery_at'], 0, 19), $tz);
            if ($parsed === false) {
                continue;
            }
            $label = trim((string) ($package['label'] ?? ''));
            $status = trim((string) ($package['status_label'] ?? ''));
            $title = $label !== '' ? $label : ($status !== '' ? $status : 'Package');
            $agenda[] = [
                'sort'     => '0 ' . $parsed->format('Y-m-d H:i:s'),
                'time'     => $parsed->format('H:i:s') === '00:00:00' ? 'Today' : $parsed->format('g:i A'),
                'datetime' => $parsed->format('Y-m-d\TH:i:s'),
                'title'    => $title,
                'href'     => '/settings/packages',
            ];
        }

        usort($agenda, static fn (array $a, array $b): int => [$a['sort'], $a['title']] <=> [$b['sort'], $b['title']]);
        return array_slice($agenda, 0, 5);
    }

    private static function bar(float $now, float $span): int
    {
        if ($span <= 0) {
            return 0;
        }
        return (int) max(0, min(100, round(($now / $span) * 100)));
    }

    private static function formatNum(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', ',');
    }

    private static function formatDur(float $seconds): string
    {
        $s = max(0, (int) round($seconds));
        if ($s < 60) {
            return $s . 's';
        }
        $minutes = intdiv($s, 60);
        if ($minutes < 60) {
            return $minutes . 'm';
        }
        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;
        return $rem === 0 ? $hours . 'h' : $hours . 'h ' . $rem . 'm';
    }
}
