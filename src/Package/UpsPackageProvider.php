<?php

declare(strict_types=1);

namespace Dashboard\Package;

use Dashboard\Support\HttpClient;

final class UpsPackageProvider implements PackageStatusProvider
{
    public function __construct(
        private readonly UpsTokenManager $tokens,
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    public function supportsCarrier(string $carrier): bool
    {
        return $carrier === 'ups';
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
                statusLabel: 'UPS not configured',
                externalUrl: CarrierDetector::defaultExternalUrl('ups', $trackingNumber),
            );
        }

        $url = $this->tokens->baseUrl()
            . '/api/track/v1/details/'
            . rawurlencode($trackingNumber)
            . '?locale=en_US&returnSignature=false&returnMilestones=false';

        try {
            $response = $this->http->request('GET', $url, null, [
                'Authorization'   => 'Bearer ' . $token,
                'Accept'          => 'application/json',
                'transId'         => 'dashboard-' . bin2hex(random_bytes(8)),
                'transactionSrc'  => 'personal-dashboard',
            ], 15);
        } catch (\Throwable $e) {
            error_log('[Dashboard][UPS] Track failed: ' . $e->getMessage());
            return new PackageStatusResult(
                statusLabel: 'UPS unreachable',
                externalUrl: CarrierDetector::defaultExternalUrl('ups', $trackingNumber),
            );
        }

        if ($response['status'] !== 200) {
            return new PackageStatusResult(
                statusLabel: 'UPS HTTP ' . $response['status'],
                externalUrl: CarrierDetector::defaultExternalUrl('ups', $trackingNumber),
            );
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return new PackageStatusResult(
                statusLabel: 'UPS invalid response',
                externalUrl: CarrierDetector::defaultExternalUrl('ups', $trackingNumber),
            );
        }

        return $this->parseResponse($data, $trackingNumber);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseResponse(array $data, string $trackingNumber): PackageStatusResult
    {
        $shipment = $data['trackResponse']['shipment'][0] ?? null;
        $pkg      = is_array($shipment) ? ($shipment['package'][0] ?? null) : null;

        if (!is_array($pkg)) {
            return new PackageStatusResult(
                statusLabel: 'No UPS match',
                externalUrl: CarrierDetector::defaultExternalUrl('ups', $trackingNumber),
                raw: $data,
            );
        }

        $currentStatus = $pkg['currentStatus'] ?? [];
        $statusType    = strtoupper((string) ($currentStatus['type'] ?? ''));
        $description   = trim((string) ($currentStatus['description'] ?? ''));
        $statusLabel   = $description !== '' ? $description : ($statusType !== '' ? $statusType : 'In transit');

        $delivery = $pkg['deliveryDate'][0] ?? null;
        $eta      = null;
        if (is_array($delivery) && !empty($delivery['date'])) {
            $time = (string) ($delivery['time'] ?? '000000');
            $eta  = $this->formatUpsDateTime((string) $delivery['date'], $time);
        }

        $activity = $pkg['activity'][0] ?? null;
        $lastEventAt = null;
        $detail      = null;
        if (is_array($activity)) {
            $detail = trim((string) ($activity['status']['description'] ?? ''));
            if (!empty($activity['date'])) {
                $lastEventAt = $this->formatUpsDateTime(
                    (string) $activity['date'],
                    (string) ($activity['time'] ?? '000000'),
                );
            }
        }

        $delivered = $statusType === 'D'
            || str_contains(strtolower($statusLabel), 'delivered');

        return new PackageStatusResult(
            statusLabel: $statusLabel,
            statusDetail: $detail ?: null,
            estimatedDeliveryAt: $eta,
            lastEventAt: $lastEventAt,
            externalUrl: CarrierDetector::defaultExternalUrl('ups', $trackingNumber),
            raw: $data,
            markComplete: $delivered,
        );
    }

    private function formatUpsDateTime(string $date, string $time): ?string
    {
        if (strlen($date) !== 8) {
            return null;
        }

        $time = str_pad(preg_replace('/\D/', '', $time) ?? '000000', 6, '0', STR_PAD_RIGHT);

        return sprintf(
            '%s-%s-%s %s:%s:%s',
            substr($date, 0, 4),
            substr($date, 4, 2),
            substr($date, 6, 2),
            substr($time, 0, 2),
            substr($time, 2, 2),
            substr($time, 4, 2),
        );
    }
}
