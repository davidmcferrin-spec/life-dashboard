<?php

declare(strict_types=1);

namespace Dashboard\Package;

use Dashboard\Support\HttpClient;

final class FedExPackageProvider implements PackageStatusProvider
{
    public function __construct(
        private readonly FedExTokenManager $tokens,
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    public function supportsCarrier(string $carrier): bool
    {
        return $carrier === 'fedex';
    }

    /**
     * @param array<string, mixed> $watch
     */
    public function fetchStatus(array $watch): PackageStatusResult
    {
        $trackingNumber = CarrierDetector::normalizeTrackingNumber((string) $watch['tracking_number']);
        $token          = $this->tokens->getAccessToken();

        if ($token === null) {
            return new PackageStatusResult(
                statusLabel: 'FedEx not configured',
                externalUrl: CarrierDetector::defaultExternalUrl('fedex', $trackingNumber),
            );
        }

        $payload = json_encode([
            'trackingInfo' => [[
                'trackingNumberInfo' => [
                    'trackingNumber' => $trackingNumber,
                ],
            ]],
            'includeDetailedScans' => true,
        ], JSON_THROW_ON_ERROR);

        try {
            $response = $this->http->request(
                'POST',
                $this->tokens->baseUrl() . '/track/v1/trackingnumbers',
                $payload,
                [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                15,
            );
        } catch (\Throwable $e) {
            error_log('[Dashboard][FedEx] Track failed: ' . $e->getMessage());
            return new PackageStatusResult(
                statusLabel: 'FedEx unreachable',
                externalUrl: CarrierDetector::defaultExternalUrl('fedex', $trackingNumber),
            );
        }

        if ($response['status'] !== 200) {
            return new PackageStatusResult(
                statusLabel: 'FedEx HTTP ' . $response['status'],
                externalUrl: CarrierDetector::defaultExternalUrl('fedex', $trackingNumber),
            );
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return new PackageStatusResult(
                statusLabel: 'FedEx invalid response',
                externalUrl: CarrierDetector::defaultExternalUrl('fedex', $trackingNumber),
            );
        }

        return $this->parseResponse($data, $trackingNumber);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data, string $trackingNumber): PackageStatusResult
    {
        $result = $data['output']['completeTrackResults'][0]['trackResults'][0] ?? null;

        if (!is_array($result)) {
            return new PackageStatusResult(
                statusLabel: 'No FedEx match',
                externalUrl: CarrierDetector::defaultExternalUrl('fedex', $trackingNumber),
                raw: $data,
            );
        }

        $latestStatus = $result['latestStatusDetail'] ?? [];
        $statusLabel  = trim((string) ($latestStatus['description'] ?? $latestStatus['statusByLocale'] ?? ''));
        if ($statusLabel === '') {
            $statusLabel = trim((string) ($result['statusDetail']['description'] ?? 'In transit'));
        }

        $eta = null;
        if (!empty($result['estimatedDeliveryTimeWindow']['window']['ends'])) {
            $eta = $this->normalizeFedExTimestamp((string) $result['estimatedDeliveryTimeWindow']['window']['ends']);
        } elseif (!empty($result['standardTransitTimeWindow']['window']['ends'])) {
            $eta = $this->normalizeFedExTimestamp((string) $result['standardTransitTimeWindow']['window']['ends']);
        }

        $scanEvents = $result['scanEvents'] ?? [];
        $lastEventAt = null;
        $detail      = null;
        if (is_array($scanEvents) && isset($scanEvents[0]) && is_array($scanEvents[0])) {
            $event = $scanEvents[0];
            $detail = trim((string) ($event['eventDescription'] ?? ''));
            if (!empty($event['date'])) {
                $lastEventAt = $this->normalizeFedExTimestamp((string) $event['date']);
            }
        }

        $code      = strtoupper((string) ($latestStatus['code'] ?? ''));
        $delivered = $code === 'DL'
            || str_contains(strtolower($statusLabel), 'delivered');

        return new PackageStatusResult(
            statusLabel: $statusLabel !== '' ? $statusLabel : 'In transit',
            statusDetail: $detail ?: null,
            estimatedDeliveryAt: $eta,
            lastEventAt: $lastEventAt,
            externalUrl: CarrierDetector::defaultExternalUrl('fedex', $trackingNumber),
            raw: $data,
            markComplete: $delivered,
        );
    }

    private function normalizeFedExTimestamp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
