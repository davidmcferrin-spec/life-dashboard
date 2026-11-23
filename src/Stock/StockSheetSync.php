<?php

declare(strict_types=1);

namespace Dashboard\Stock;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;

final class StockSheetSync
{
    public function __construct(
        private readonly StockSheetConfig $settings,
        private readonly StockSheetStore $store,
        private readonly SheetReader $sheets,
        private readonly Clock $clock,
    ) {}

    public static function make(Database $db, Clock $clock, ?SheetReader $sheets = null): self
    {
        return new self(
            new StockSheetSettings($db),
            new PgStockSheetStore($db),
            $sheets ?? new GoogleSheetClient(new StreamSheetTransport()),
            $clock,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{settings: array{spreadsheet_id: string, key_hint: string, configured: bool}}
     */
    public function saveSettings(int $userId, array $input): array
    {
        return ['settings' => $this->settings->save($userId, $input)];
    }

    /**
     * @return array<string, mixed>
     */
    public function page(int $userId): array
    {
        return [
            'settings'     => $this->settings->view($userId),
            'watchlist'    => $this->store->watchlist($userId),
            'commodities'  => $this->store->commodities($userId),
            'play_account' => $this->store->play($userId),
            'sync'         => $this->status($userId),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, sync: array<string, mixed>}
     */
    public function watchlist(int $userId): array
    {
        return ['rows' => $this->store->watchlist($userId), 'sync' => $this->status($userId)];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, sync: array<string, mixed>}
     */
    public function commodities(int $userId): array
    {
        return ['rows' => $this->store->commodities($userId), 'sync' => $this->status($userId)];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, sync: array<string, mixed>}
     */
    public function play(int $userId): array
    {
        return ['rows' => $this->store->play($userId), 'sync' => $this->status($userId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $userId): array
    {
        $view = $this->settings->view($userId);
        $row = $this->store->status($userId);
        $state = (string) ($row['status'] ?? 'never');
        $last = isset($row['last_synced_at']) && is_string($row['last_synced_at']) && $row['last_synced_at'] !== ''
            ? $row['last_synced_at']
            : null;
        $configured = (bool) $view['configured'];

        return [
            'configured'      => $configured,
            'spreadsheet_id'  => (string) $view['spreadsheet_id'],
            'status'          => $configured ? $state : 'never',
            'sync_error'      => $configured ? ($row['sync_error'] ?? null) : null,
            'last_synced_at'  => $last,
            'last_attempt_at' => $row['last_attempt_at'] ?? null,
            'stale'           => $configured && SheetFreshness::stale($last, $this->clock->now(), $this->clock->timezone(), $state),
            'watchlist_count' => (int) ($row['watchlist_count'] ?? 0),
            'commodity_count' => (int) ($row['commodity_count'] ?? 0),
            'play_count'      => (int) ($row['play_count'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(int $userId): array
    {
        $status = $this->status($userId);
        if (!$status['configured']) {
            return [
                'configured'     => false,
                'stale'          => false,
                'last_synced_at' => null,
                'benchmarks'     => [],
                'movers'         => [],
            ];
        }

        return SheetSnapshot::from($this->store->watchlist($userId), $this->store->commodities($userId), $status);
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(int $userId): array
    {
        $result = $this->syncUser($userId);

        return [
            'configured' => $result['configured'],
            'ok'         => $result['ok'],
            'sync'       => $result['status'],
        ];
    }

    /**
     * @return array{users: int, synced: int, skipped: int, failed: int}
     */
    public function syncAll(): array
    {
        $synced = 0;
        $skipped = 0;
        $failed = 0;
        $ids = $this->settings->userIds();
        foreach ($ids as $userId) {
            $result = $this->syncUser($userId);
            if (!$result['configured']) {
                $skipped++;
                continue;
            }
            if (!empty($result['ok'])) {
                $synced++;
            } else {
                $failed++;
            }
        }

        return [
            'users'   => count($ids),
            'synced'  => $synced,
            'skipped' => $skipped,
            'failed'  => $failed,
        ];
    }

    /**
     * @return array{configured: bool, ok: bool, status: array<string, mixed>}
     */
    public function syncUser(int $userId): array
    {
        $creds = $this->settings->credentials($userId);
        if ($creds === null) {
            return [
                'configured' => false,
                'ok'         => false,
                'status'     => $this->status($userId),
            ];
        }
        $stamp = $this->clock->utcString($this->clock->now());
        try {
            $grids = $this->sheets->fetch($creds['account'], $creds['spreadsheet_id']);
            $watchlist = SheetGrid::watchlist($grids['Watchlist'] ?? []);
            $commodities = SheetGrid::commodities($grids['Commodities'] ?? []);
            $play = SheetGrid::play($grids['Play Account'] ?? []);
            $this->store->replace($userId, $watchlist, $commodities, $play, [
                'last_synced_at'  => $stamp,
                'last_attempt_at' => $stamp,
            ]);
        } catch (SheetException $e) {
            $this->store->writeError($userId, SheetException::safe($e->getMessage()), $stamp);

            return [
                'configured' => true,
                'ok'         => false,
                'status'     => $this->status($userId),
            ];
        } catch (\Throwable) {
            $this->store->writeError($userId, 'The sheet sync failed.', $stamp);

            return [
                'configured' => true,
                'ok'         => false,
                'status'     => $this->status($userId),
            ];
        }

        return [
            'configured' => true,
            'ok'         => true,
            'status'     => $this->status($userId),
        ];
    }
}
