<?php

declare(strict_types=1);

namespace Dashboard\Brief;

use Dashboard\DB\Database;
use Dashboard\Digest\DigestService;
use Dashboard\Grants\SectorGate;
use Dashboard\Health\HealthService;
use Dashboard\Home\HomeStatus;
use Dashboard\Money\BillsService;
use Dashboard\Spotify\SpotifyService;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;

/**
 * One short brief from stored calendar, digests, payday, home, music, and health.
 * A denied sector is named and skipped. Balances and vitals are never invented.
 */
final class MorningBriefService
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly BillsService $bills,
        private readonly DigestService $digest,
        private readonly HealthService $health,
        private readonly SectorGate $grants,
    ) {}

    /**
     * @return array{brief: array<string, mixed>}
     */
    public function compose(int $userId, ?string $kind, ?int $principalId, string $tool = 'brief_me'): array
    {
        $facts = [
            'calendar' => ['connected' => false],
            'digests'  => $this->digests($userId),
            'money'    => $this->money($userId, $kind, $principalId, $tool),
            'home'     => $this->home($userId, $kind, $principalId, $tool),
            'music'    => $this->music($userId),
            'health'   => $this->healthFacts($userId, $kind, $principalId, $tool),
        ];
        $body = BriefText::render($facts);
        $this->db->execute(
            'INSERT INTO morning_briefs (user_id, body) VALUES (?, ?)',
            [$userId, $body]
        );
        $this->db->execute(
            'DELETE FROM morning_briefs WHERE user_id = ? AND id NOT IN (
                SELECT id FROM morning_briefs WHERE user_id = ? ORDER BY id DESC LIMIT 14
             )',
            [$userId, $userId]
        );
        $row = $this->db->fetchOne(
            'SELECT id, body, composed_at FROM morning_briefs WHERE user_id = ? ORDER BY id DESC LIMIT 1',
            [$userId]
        );

        return ['brief' => [
            'id'          => (int) ($row['id'] ?? 0),
            'body'        => (string) ($row['body'] ?? $body),
            'composed_at' => (string) ($row['composed_at'] ?? ''),
        ]];
    }

    /**
     * @return array{briefs: list<array<string, mixed>>}
     */
    public function history(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, body, composed_at FROM morning_briefs WHERE user_id = ? ORDER BY id DESC LIMIT 14',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'          => (int) $row['id'],
                'body'        => (string) $row['body'],
                'composed_at' => (string) $row['composed_at'],
            ];
        }

        return ['briefs' => $out];
    }

    /**
     * @return array<string, mixed>
     */
    public function schedule(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT enabled, local_time, last_ran_on FROM brief_schedules WHERE user_id = ?',
            [$userId]
        );

        return [
            'enabled'     => $row !== null && (int) $row['enabled'] === 1,
            'local_time'  => $row['local_time'] ?? '07:00',
            'last_ran_on' => $row['last_ran_on'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveSchedule(int $userId, array $input): array
    {
        $enabled = !empty($input['enabled']);
        $time = trim((string) ($input['local_time'] ?? '07:00'));
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw ValidationException::withFields(['local_time' => 'Use HH:MM in 24-hour time.']);
        }
        $this->db->execute(
            'INSERT INTO brief_schedules (user_id, enabled, local_time)
             VALUES (?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE
             SET enabled = EXCLUDED.enabled, local_time = EXCLUDED.local_time, updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $enabled ? 1 : 0, $time]
        );

        return $this->schedule($userId);
    }

    /**
     * @return array{ran: int, waiting: int}
     */
    public function runDue(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT user_id, local_time, last_ran_on FROM brief_schedules WHERE enabled = 1',
            []
        );
        $local = $this->clock->now()->setTimezone($this->clock->timezone());
        $today = $local->format('Y-m-d');
        $hm = $local->format('H:i');
        $ran = 0;
        $waiting = 0;
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            if ((string) $row['local_time'] > $hm || (string) ($row['last_ran_on'] ?? '') === $today) {
                $waiting++;
                continue;
            }
            try {
                if ($this->health->status($userId)['open_wearables']['configured'] ?? false) {
                    $this->health->sync($userId);
                }
                $this->compose($userId, null, null, 'brief_schedule');
                $this->db->execute(
                    'UPDATE brief_schedules SET last_ran_on = ? WHERE user_id = ?',
                    [$today, $userId]
                );
                $ran++;
            } catch (\Throwable) {
                $waiting++;
            }
        }

        return ['ran' => $ran, 'waiting' => $waiting];
    }

    /**
     * @return list<array{title: string, summary: string}>
     */
    private function digests(int $userId): array
    {
        try {
            $feed = $this->digest->items($userId, ['limit' => 3]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($feed['items'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'title'   => (string) ($item['title'] ?? ''),
                'summary' => (string) ($item['summary'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function money(int $userId, ?string $kind, ?int $principalId, string $tool): array
    {
        if (!$this->grants->take($userId, $kind, $principalId, 'money', $tool)) {
            return ['omitted' => 'needs-grant'];
        }
        try {
            $pressure = $this->bills->pressure($userId);
        } catch (\Throwable) {
            return ['error' => true, 'configured' => false];
        }
        $bills = [];
        foreach ($pressure['bills'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $bills[] = [
                'name'   => (string) ($line['name'] ?? ''),
                'amount' => isset($line['amount']) ? (float) $line['amount'] : null,
                'due_on' => (string) ($line['due_on'] ?? ''),
            ];
        }

        return [
            'configured'   => !empty($pressure['configured']),
            'next_payday'  => $pressure['next_payday'] ?? null,
            'pulls_total'  => $pressure['pulls_total'] ?? null,
            'debt_total'   => $pressure['debt_total'] ?? null,
            'unassigned'   => $pressure['unassigned'] ?? null,
            'bill_count'   => count($bills),
            'bills'        => $bills,
            'moves_money'  => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function home(int $userId, ?string $kind, ?int $principalId, string $tool): array
    {
        if (!$this->grants->take($userId, $kind, $principalId, 'home', $tool)) {
            return ['omitted' => 'needs-grant'];
        }
        unset($userId);
        try {
            return (new HomeStatus($this->db))->status();
        } catch (\Throwable) {
            return ['configured' => true, 'reachable' => false, 'detail' => 'Home Assistant did not answer.'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function music(int $userId): array
    {
        try {
            $spotify = SpotifyService::fromDatabase($this->db);
            $settings = $spotify->settings($userId);
            if (empty($settings['connected'])) {
                return ['connected' => false];
            }
            $now = $spotify->playback()->cachedNow($userId, false);
        } catch (\Throwable) {
            return ['error' => true, 'connected' => true];
        }
        if (!is_array($now) || empty($now['name'])) {
            return ['connected' => true, 'playing' => false];
        }
        $artists = [];
        foreach ($now['artists'] ?? [] as $artist) {
            if (is_string($artist)) {
                $artists[] = $artist;
            } elseif (is_array($artist) && isset($artist['name'])) {
                $artists[] = (string) $artist['name'];
            }
        }

        return [
            'connected' => true,
            'playing'   => !empty($now['is_playing']) || !empty($now['name']),
            'name'      => (string) $now['name'],
            'artists'   => $artists,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthFacts(int $userId, ?string $kind, ?int $principalId, string $tool): array
    {
        if (!$this->grants->take($userId, $kind, $principalId, 'health', $tool)) {
            return ['omitted' => 'needs-grant'];
        }
        try {
            $snap = $this->health->snapshot($userId);
        } catch (\Throwable) {
            return ['configured' => false, 'stored' => false];
        }
        $text = null;
        if (is_array($snap['row'] ?? null)) {
            $row = $snap['row'];
            $row['used'] = [];
            $text = BriefText::health((string) $row['day'], $row, (string) $snap['today']);
            if ($row['day'] !== $snap['today']) {
                $text = 'No vitals are stored for today. Latest stored day is ' . $row['day'] . '. ' . $text;
            }
        }

        return [
            'configured' => !empty($snap['configured']),
            'stored'     => !empty($snap['stored']),
            'text'       => $text,
        ];
    }
}
