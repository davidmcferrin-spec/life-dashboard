<?php

declare(strict_types=1);

namespace Dashboard\Package;

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;

final class PackageWatchService
{
    private const MAX_WATCHES = 10;

    public function __construct(
        private readonly PackageWatchRepository $repo,
        private readonly PreferenceService $prefs,
    ) {}

    public static function fromDatabase(Database $db): self
    {
        return new self(
            new PackageWatchRepository($db),
            new PreferenceService($db),
        );
    }

    public static function maxWatches(): int
    {
        return max(1, Config::int('PACKAGE_MAX_PER_USER', self::MAX_WATCHES));
    }

    public function ensureDefaults(int $userId): void
    {
        if ($this->prefs->hasWidgetDefaultsSeeded($userId, 'packages')) {
            return;
        }

        $this->repo->insert([
            'user_id'         => $userId,
            'tracking_number' => 'EXAMPLE',
            'carrier'         => 'auto',
            'label'           => 'Replace with your tracking #',
            'is_active'       => 0,
            'sort_order'      => 0,
        ]);

        $this->prefs->markWidgetDefaultsSeeded($userId, 'packages');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForDashboard(int $userId): array
    {
        $this->ensureDefaults($userId);
        $this->purgeExpiredWatches();

        return array_map(
            fn (array $row): array => $this->format($row),
            $this->repo->listForUser($userId, true),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(int $userId): array
    {
        $this->ensureDefaults($userId);
        $this->purgeExpiredWatches();

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
        string $trackingNumber,
        string $carrier = 'auto',
        ?string $label = null,
        ?string $externalUrl = null,
    ): array {
        $this->ensureDefaults($userId);

        $trackingNumber = CarrierDetector::normalizeTrackingNumber($trackingNumber);
        if ($trackingNumber === '') {
            throw new \InvalidArgumentException('Tracking number is required.');
        }

        $carrier = CarrierDetector::normalizeCarrier($carrier);

        if ($this->repo->countActiveForUser($userId) >= self::maxWatches()) {
            throw new \InvalidArgumentException('Maximum of ' . self::maxWatches() . ' active package watches allowed.');
        }

        $externalUrl = $this->sanitizeExternalUrl($externalUrl);
        if ($externalUrl === null) {
            $resolved = CarrierDetector::resolveCarrier($carrier, $trackingNumber) ?? $carrier;
            if ($resolved !== 'auto') {
                $externalUrl = CarrierDetector::defaultExternalUrl($resolved, $trackingNumber);
            }
        }

        $id = $this->repo->insert([
            'user_id'         => $userId,
            'tracking_number' => $trackingNumber,
            'carrier'         => $carrier,
            'label'           => $label !== null ? trim($label) : null,
            'external_url'    => $externalUrl,
            'sort_order'      => $this->repo->nextSortOrder($userId),
        ]);

        $row = $this->repo->findByIdForUser($id, $userId);
        if ($row === null) {
            throw new \RuntimeException('Failed to create package watch.');
        }

        if ($this->shouldPoll($row)) {
            $this->pollWatch($row);
            $row = $this->repo->findByIdForUser($id, $userId) ?? $row;
        } elseif ($this->isLinkOnlyWatch($row)) {
            $this->applyLinkOnlyStatus($row);
            $row = $this->repo->findByIdForUser($id, $userId) ?? $row;
        }

        return $this->format($row);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, int $userId, array $fields): ?array
    {
        $allowed = [];

        if (isset($fields['tracking_number'])) {
            $tn = CarrierDetector::normalizeTrackingNumber((string) $fields['tracking_number']);
            if ($tn === '') {
                throw new \InvalidArgumentException('Tracking number is required.');
            }
            $allowed['tracking_number'] = $tn;
        }
        if (isset($fields['carrier'])) {
            $allowed['carrier'] = CarrierDetector::normalizeCarrier((string) $fields['carrier']);
        }
        if (array_key_exists('label', $fields)) {
            $allowed['label'] = $fields['label'] !== null ? trim((string) $fields['label']) : null;
        }
        if (array_key_exists('external_url', $fields)) {
            $allowed['external_url'] = $this->sanitizeExternalUrl(
                $fields['external_url'] !== null ? (string) $fields['external_url'] : null,
            );
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

    /**
     * @param array<string, mixed> $watch
     */
    public function pollWatch(array $watch): void
    {
        if (!$this->shouldPoll($watch)) {
            if ($this->isLinkOnlyWatch($watch)) {
                $this->applyLinkOnlyStatus($watch);
            }
            return;
        }

        $carrier = $this->resolveCarrierForWatch($watch);
        if ($carrier === null) {
            $this->repo->applyStatus((int) $watch['id'], new PackageStatusResult(
                statusLabel: 'Unknown carrier',
                statusDetail: 'Set carrier manually or check the tracking number.',
                externalUrl: null,
            ));
            return;
        }

        $provider = PackageProviderFactory::forCarrier($carrier);
        if ($provider === null) {
            return;
        }

        $result = $provider->fetchStatus($watch);
        $this->repo->applyStatus((int) $watch['id'], $result);

        if ($result->markComplete) {
            $this->repo->markComplete((int) $watch['id'], (int) $watch['user_id']);
        }
    }

    public function pollDueWatches(): int
    {
        $this->purgeExpiredWatches();

        $minutes = Config::int('PACKAGE_POLL_MINUTES', 120);
        $due     = $this->repo->listDueForPoll($minutes);
        $count   = 0;

        foreach ($due as $watch) {
            $this->pollWatch($watch);
            $count++;
        }

        return $count;
    }

    public function purgeExpiredWatches(): int
    {
        $days   = max(1, Config::int('PACKAGE_PURGE_DAYS', 7));
        $tz     = new \DateTimeZone(Config::get('APP_TIMEZONE', 'America/New_York'));
        $cutoff = (new \DateTimeImmutable('now', $tz))->modify('-' . $days . ' days');

        return $this->repo->deleteExpiredBefore($cutoff->format('Y-m-d H:i:s'));
    }

    /**
     * @param array<string, mixed> $watch
     */
    private function shouldPoll(array $watch): bool
    {
        if ((int) ($watch['is_active'] ?? 0) !== 1) {
            return false;
        }

        if ((int) ($watch['is_complete'] ?? 0) === 1) {
            return false;
        }

        return !$this->isLinkOnlyWatch($watch);
    }

    /**
     * @param array<string, mixed> $watch
     */
    private function isLinkOnlyWatch(array $watch): bool
    {
        $carrier = $this->resolveCarrierForWatch($watch);

        return CarrierDetector::isLinkOnlyCarrier($carrier)
            || CarrierDetector::isLinkOnlyCarrier((string) ($watch['carrier'] ?? ''));
    }

    /**
     * @param array<string, mixed> $watch
     */
    private function applyLinkOnlyStatus(array $watch): void
    {
        $carrier = $this->resolveCarrierForWatch($watch);

        if ($carrier === null || !CarrierDetector::isLinkOnlyCarrier($carrier)) {
            $stored = CarrierDetector::normalizeCarrier((string) ($watch['carrier'] ?? ''));
            $carrier = CarrierDetector::isLinkOnlyCarrier($stored) ? $stored : null;
        }

        if ($carrier === null) {
            return;
        }

        $provider = PackageProviderFactory::forCarrier($carrier);
        if ($provider === null) {
            return;
        }

        $result = $provider->fetchStatus($watch);
        $this->repo->applyStatus((int) $watch['id'], $result);
    }

    /**
     * @param array<string, mixed> $watch
     */
    private function resolveCarrierForWatch(array $watch): ?string
    {
        return CarrierDetector::resolveCarrier(
            (string) ($watch['carrier'] ?? 'auto'),
            (string) ($watch['tracking_number'] ?? ''),
        );
    }

    private function sanitizeExternalUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        return $url !== '' && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        $trackingNumber = (string) $row['tracking_number'];
        $resolved       = $this->resolveCarrierForWatch($row);
        $externalUrl    = $row['external_url'] ?? null;

        if (($externalUrl === null || $externalUrl === '') && $resolved !== null) {
            $externalUrl = CarrierDetector::defaultExternalUrl($resolved, $trackingNumber);
        }

        return [
            'id'                    => (int) $row['id'],
            'tracking_number'       => $trackingNumber,
            'carrier'               => (string) $row['carrier'],
            'carrier_resolved'      => $resolved,
            'label'                 => $row['label'] ?? null,
            'is_active'             => (bool) $row['is_active'],
            'is_complete'           => (bool) $row['is_complete'],
            'is_link_only'          => CarrierDetector::isLinkOnlyCarrier($resolved)
                || CarrierDetector::isLinkOnlyCarrier((string) $row['carrier']),
            'status_label'          => $row['status_label']
                ?? CarrierDetector::linkOnlyStatusLabel($resolved)
                ?? 'Pending',
            'status_detail'         => $row['status_detail'] ?? null,
            'estimated_delivery_at' => $row['estimated_delivery_at'] ?? null,
            'last_event_at'         => $row['last_event_at'] ?? null,
            'last_checked_at'       => $row['last_checked_at'] ?? null,
            'external_url'          => is_string($externalUrl) && $externalUrl !== '' ? $externalUrl : null,
        ];
    }
}
