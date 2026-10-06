<?php

declare(strict_types=1);

namespace Dashboard\Flight;

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

final class FlightWatchService
{
    public function __construct(
        private readonly FlightWatchRepository $repo,
        private readonly FlightStatusProvider $provider,
        private readonly PreferenceService $prefs,
    ) {}

    public static function fromDatabase(Database $db): self
    {
        return new self(
            new FlightWatchRepository($db),
            FlightProviderFactory::make(),
            new PreferenceService($db),
        );
    }

    public function ensureDefaults(int $userId): void
    {
        if ($this->prefs->hasWidgetDefaultsSeeded($userId, 'flights')) {
            return;
        }

        $tz = Config::get('APP_TIMEZONE', 'America/New_York');
        $tzObj = new \DateTimeZone($tz);

        $examples = [
            ['ident' => 'AA100', 'days' => 7],
            ['ident' => 'UA500', 'days' => 14],
            ['ident' => 'DL200', 'days' => 21],
        ];

        $order = 0;
        foreach ($examples as $ex) {
            $dep = new \DateTimeImmutable('now', $tzObj);
            $dep = $dep->modify('+' . $ex['days'] . ' days')->setTime(14, 30);

            $this->repo->insert([
                'user_id'      => $userId,
                'ident'        => self::normalizeIdent($ex['ident']),
                'departure_at' => $dep->format('Y-m-d H:i:s'),
                'arrival_at'   => $dep->modify('+4 hours')->format('Y-m-d H:i:s'),
                'sort_order'   => $order++,
            ]);
        }

        $this->prefs->markWidgetDefaultsSeeded($userId, 'flights');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForDashboard(int $userId): array
    {
        $this->ensureDefaults($userId);
        $this->maintainWatches();

        $rows = $this->repo->listForUser($userId, true);

        return array_map(fn (array $row): array => $this->format($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(int $userId): array
    {
        $this->ensureDefaults($userId);
        $this->maintainWatches();

        return array_map(
            fn (array $row): array => $this->format($row),
            $this->repo->listForUser($userId, false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        int $userId,
        string $ident,
        string $departureAt,
        ?string $arrivalAt = null,
        ?string $originIcao = null,
        ?string $destIcao = null,
    ): array {
        $this->ensureDefaults($userId);

        $ident = self::normalizeIdent($ident);
        if ($ident === '') {
            throw new \InvalidArgumentException('Flight ident is required.');
        }

        $id = $this->repo->insert([
            'user_id'      => $userId,
            'ident'        => $ident,
            'departure_at' => $departureAt,
            'arrival_at'   => $arrivalAt,
            'origin_icao'  => $originIcao !== null ? strtoupper(trim($originIcao)) : null,
            'dest_icao'    => $destIcao !== null ? strtoupper(trim($destIcao)) : null,
            'sort_order'   => $this->repo->nextSortOrder($userId),
        ]);

        $row = $this->repo->findByIdForUser($id, $userId);

        if ($row === null) {
            throw new \RuntimeException('Failed to create flight watch.');
        }

        return $this->format($row);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, int $userId, array $fields): ?array
    {
        $allowed = [];

        if (isset($fields['ident'])) {
            $allowed['ident'] = self::normalizeIdent((string) $fields['ident']);
        }
        if (isset($fields['departure_at'])) {
            $allowed['departure_at'] = (string) $fields['departure_at'];
        }
        if (array_key_exists('arrival_at', $fields)) {
            $allowed['arrival_at'] = $fields['arrival_at'];
        }
        if (isset($fields['origin_icao'])) {
            $allowed['origin_icao'] = strtoupper(trim((string) $fields['origin_icao'])) ?: null;
        }
        if (isset($fields['dest_icao'])) {
            $allowed['dest_icao'] = strtoupper(trim((string) $fields['dest_icao'])) ?: null;
        }
        if (array_key_exists('is_active', $fields)) {
            $allowed['is_active'] = $fields['is_active'] ? 1 : 0;
        }

        if ($allowed === []) {
            $row = $this->repo->findByIdForUser($id, $userId);
            return $row !== null ? $this->format($row) : null;
        }

        $this->repo->update($id, $userId, $allowed);
        $row = $this->repo->findByIdForUser($id, $userId);

        return $row !== null ? $this->format($row) : null;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->repo->delete($id, $userId);
    }

    public function pollWatch(array $watch): void
    {
        if (!$this->isInPollWindow($watch)) {
            return;
        }

        $result = $this->provider->fetchStatus($watch);
        $this->repo->applyStatus((int) $watch['id'], $result);

        if ($this->shouldMarkComplete($watch, $result)) {
            $this->repo->markComplete((int) $watch['id'], (int) $watch['user_id']);
        }
    }

    public function pollDueWatches(): int
    {
        $this->maintainWatches();

        $minutes = Config::int('FLIGHT_POLL_MINUTES', 30);
        $due     = $this->repo->listDueForPoll($minutes);
        $count   = 0;

        foreach ($due as $watch) {
            if (!$this->isInPollWindow($watch)) {
                continue;
            }
            $this->pollWatch($watch);
            $count++;
        }

        return $count;
    }

    public function markExpiredWatches(): int
    {
        $marked = 0;

        foreach ($this->repo->listIncomplete() as $watch) {
            if (!$this->shouldMarkComplete($watch, null)) {
                continue;
            }

            $this->repo->markComplete((int) $watch['id'], (int) $watch['user_id']);
            $marked++;
        }

        return $marked;
    }

    public function purgeExpiredWatches(): int
    {
        $hours  = max(1, Config::int('FLIGHT_PURGE_HOURS', 12));
        $tz     = new \DateTimeZone(Config::get('APP_TIMEZONE', 'America/New_York'));
        $cutoff = (new \DateTimeImmutable('now', $tz))->modify('-' . $hours . ' hours');

        return $this->repo->deleteExpiredBefore($cutoff->format('Y-m-d H:i:s'));
    }

    /**
     * @return array{marked: int, purged: int}
     */
    public function maintainWatches(): array
    {
        return [
            'marked' => $this->markExpiredWatches(),
            'purged' => $this->purgeExpiredWatches(),
        ];
    }

    /**
     * @param array<string, mixed> $watch
     */
    public function isInPollWindow(array $watch): bool
    {
        if ((int) ($watch['is_complete'] ?? 0) === 1) {
            return false;
        }

        $tz  = new \DateTimeZone(Config::get('APP_TIMEZONE', 'America/New_York'));
        $now = new \DateTimeImmutable('now', $tz);
        $dep = new \DateTimeImmutable((string) $watch['departure_at'], $tz);

        if ($now < $dep->modify('-24 hours')) {
            return false;
        }

        return !$this->shouldMarkComplete($watch, null);
    }

    public static function normalizeIdent(string $ident): string
    {
        $ident = strtoupper(preg_replace('/\s+/', '', trim($ident)) ?? '');

        return mb_substr($ident, 0, 16);
    }

    /**
     * @param array<string, mixed> $watch
     */
    private function shouldMarkComplete(array $watch, ?FlightStatusResult $result): bool
    {
        if ((int) ($watch['is_complete'] ?? 0) === 1) {
            return true;
        }

        if ($result?->markComplete === true) {
            return true;
        }

        $tz  = new \DateTimeZone(Config::get('APP_TIMEZONE', 'America/New_York'));
        $now = new \DateTimeImmutable('now', $tz);
        $dep = new \DateTimeImmutable((string) $watch['departure_at'], $tz);

        if (!empty($watch['arrival_at'])) {
            $arr = new \DateTimeImmutable((string) $watch['arrival_at'], $tz);
            if ($now > $arr->modify('+2 hours')) {
                return true;
            }
        }

        return $now > $dep->modify('+18 hours');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        $tz = Config::get('APP_TIMEZONE', 'America/New_York');

        return [
            'id'              => (int) $row['id'],
            'ident'           => (string) $row['ident'],
            'departure_at'    => (string) $row['departure_at'],
            'arrival_at'      => $row['arrival_at'] ?? null,
            'origin_icao'     => $row['origin_icao'] ?? null,
            'dest_icao'       => $row['dest_icao'] ?? null,
            'origin_display'  => $row['origin_display'] ?? $row['origin_icao'] ?? null,
            'dest_display'    => $row['dest_display'] ?? $row['dest_icao'] ?? null,
            'is_active'       => (bool) $row['is_active'],
            'is_complete'     => (bool) $row['is_complete'],
            'in_poll_window'  => $this->isInPollWindow($row),
            'status_label'    => $row['status_label'] ?? 'Scheduled',
            'airborne'        => (bool) ($row['airborne'] ?? false),
            'latitude'        => $row['latitude'] !== null ? (float) $row['latitude'] : null,
            'longitude'       => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            'altitude_m'      => $row['altitude_m'] !== null ? (float) $row['altitude_m'] : null,
            'velocity_ms'     => $row['velocity_ms'] !== null ? (float) $row['velocity_ms'] : null,
            'last_checked_at' => $row['last_checked_at'] ?? null,
            'external_url'    => $this->buildExternalUrl($row),
            'timezone'        => $tz,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildExternalUrl(array $row): ?string
    {
        $ident = (string) $row['ident'];

        if ($ident === '') {
            return null;
        }

        $raw = $row['raw_json'] ?? null;

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded) && (isset($decoded['ident_icao']) || isset($decoded['ident_iata']))) {
                return FlightAwareFlightProvider::webUrlForFlight($decoded, $ident);
            }
        }

        return FlightAwareFlightProvider::webUrlForIdent($ident);
    }
}
