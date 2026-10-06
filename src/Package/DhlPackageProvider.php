<?php

declare(strict_types=1);

namespace Dashboard\Package;

use Dashboard\Config;
use Dashboard\Support\HttpClient;

/**
 * DHL Shipment Tracking — Unified API (read-only, API key).
 */
final class DhlPackageProvider implements PackageStatusProvider
{
    private const API_BASE = 'https://api-eu.dhl.com/track/shipments';

    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    public function supportsCarrier(string $carrier): bool
    {
        return $carrier === 'dhl';
    }

    /**
     * @param array<string, mixed> $watch
     */
    public function fetchStatus(array $watch): PackageStatusResult
    {
        $trackingNumber = CarrierDetector::normalizeTrackingNumber((string) $watch['tracking_number']);
        $apiKey         = Config::maybe('DHL_API_KEY');

        if ($apiKey === null) {
            return new PackageStatusResult(
                statusLabel: 'DHL not configured',
                externalUrl: CarrierDetector::defaultExternalUrl('dhl', $trackingNumber),
            );
        }

        $url = self::API_BASE . '?' . http_build_query([
            'trackingNumber' => $trackingNumber,
        ]);

        try {
            $response = $this->http->request('GET', $url, null, [
                'DHL-API-Key' => $apiKey,
                'Accept'      => 'application/json',
            ], 15);
        } catch (\Throwable $e) {
            error_log('[Dashboard][DHL] Track failed: ' . $e->getMessage());
            return new PackageStatusResult(
                statusLabel: 'DHL unreachable',
                externalUrl: CarrierDetector::defaultExternalUrl('dhl', $trackingNumber),
            );
        }

        if ($response['status'] !== 200) {
            return new PackageStatusResult(
                statusLabel: 'DHL HTTP ' . $response['status'],
                externalUrl: CarrierDetector::defaultExternalUrl('dhl', $trackingNumber),
            );
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return new PackageStatusResult(
                statusLabel: 'DHL invalid response',
                externalUrl: CarrierDetector::defaultExternalUrl('dhl', $trackingNumber),
            );
        }

        return $this->parseResponse($data, $trackingNumber);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data, string $trackingNumber): PackageStatusResult
    {
        $shipment = $data['shipments'][0] ?? null;

        if (!is_array($shipment)) {
            return new PackageStatusResult(
                statusLabel: 'No DHL match',
                externalUrl: CarrierDetector::defaultExternalUrl('dhl', $trackingNumber),
                raw: $data,
            );
        }

        $status      = $shipment['status'] ?? [];
        $statusLabel = trim((string) ($status['statusCode'] ?? $status['description'] ?? ''));
        if ($statusLabel === '' && !empty($status['status'])) {
            $statusLabel = (string) $status['status'];
        }
        if ($statusLabel === '') {
            $statusLabel = 'In transit';
        }

        $eta = null;
        if (!empty($shipment['estimatedTimeOfDelivery'])) {
            $eta = $this->normalizeTimestamp((string) $shipment['estimatedTimeOfDelivery']);
        }

        $events = $shipment['events'] ?? [];
        $lastEventAt = null;
        $detail      = null;
        if (is_array($events) && isset($events[0]) && is_array($events[0])) {
            $event  = $events[0];
            $detail = trim((string) ($event['description'] ?? ''));
            if (!empty($event['timestamp'])) {
                $lastEventAt = $this->normalizeTimestamp((string) $event['timestamp']);
            }
        }

        $statusCode = strtoupper((string) ($status['statusCode'] ?? ''));
        $delivered  = $statusCode === 'DELIVERED'
            || str_contains(strtolower($statusLabel), 'delivered');

        return new PackageStatusResult(
            statusLabel: $statusLabel,
            statusDetail: $detail ?: null,
            estimatedDeliveryAt: $eta,
            lastEventAt: $lastEventAt,
            externalUrl: CarrierDetector::defaultExternalUrl('dhl', $trackingNumber),
            raw: $data,
            markComplete: $delivered,
        );
    }

    private function normalizeTimestamp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
