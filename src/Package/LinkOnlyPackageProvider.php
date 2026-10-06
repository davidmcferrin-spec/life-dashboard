<?php

declare(strict_types=1);

namespace Dashboard\Package;

/**
 * Carriers without a viable personal/recipient tracking API — deep link only.
 */
final class LinkOnlyPackageProvider implements PackageStatusProvider
{
    public function __construct(
        private readonly string $carrier,
        private readonly string $statusLabel,
        private readonly ?string $statusDetail = null,
    ) {}

    public function supportsCarrier(string $carrier): bool
    {
        return $carrier === $this->carrier;
    }

    /**
     * @param array<string, mixed> $watch
     */
    public function fetchStatus(array $watch): PackageStatusResult
    {
        $trackingNumber = CarrierDetector::normalizeTrackingNumber((string) $watch['tracking_number']);
        $externalUrl    = $this->resolveExternalUrl($watch, $trackingNumber);

        return new PackageStatusResult(
            statusLabel: $this->statusLabel,
            statusDetail: $this->statusDetail,
            externalUrl: $externalUrl,
        );
    }

    /**
     * @param array<string, mixed> $watch
     */
    private function resolveExternalUrl(array $watch, string $trackingNumber): ?string
    {
        $stored = trim((string) ($watch['external_url'] ?? ''));

        if ($stored !== '' && filter_var($stored, FILTER_VALIDATE_URL)) {
            return $stored;
        }

        if ($trackingNumber !== '') {
            return CarrierDetector::defaultExternalUrl($this->carrier, $trackingNumber);
        }

        return null;
    }
}
