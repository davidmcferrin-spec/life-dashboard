<?php

declare(strict_types=1);

namespace Dashboard\Package;

final class CarrierDetector
{
    public const CARRIERS = ['auto', 'ups', 'fedex', 'dhl', 'usps', 'amazon', 'ontrac', 'lasership'];

    /** @var list<string> */
    public const LINK_ONLY_CARRIERS = ['usps', 'amazon', 'ontrac', 'lasership'];

    /** @var array<string, array{label: string, detail: string}> */
    public const LINK_ONLY_META = [
        'amazon' => [
            'label'  => 'Track on Amazon',
            'detail' => 'Amazon does not offer a public tracking API.',
        ],
        'usps' => [
            'label'  => 'Track on USPS',
            'detail' => 'USPS recipient tracking is link-only (API requires shipper MID authorization).',
        ],
        'ontrac' => [
            'label'  => 'Track on OnTrac',
            'detail' => 'OnTrac has no public tracking API for personal dashboards.',
        ],
        'lasership' => [
            'label'  => 'Track on LaserShip',
            'detail' => 'LaserShip has no public tracking API for personal dashboards.',
        ],
    ];

    public static function normalizeTrackingNumber(string $number): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($number)) ?? '');
    }

    public static function normalizeCarrier(string $carrier): string
    {
        $carrier = strtolower(trim($carrier));

        return in_array($carrier, self::CARRIERS, true) ? $carrier : 'auto';
    }

    /**
     * Guess carrier from tracking number format.
     */
    public static function detect(string $trackingNumber): ?string
    {
        $tn = self::normalizeTrackingNumber($trackingNumber);

        if ($tn === '') {
            return null;
        }

        if (preg_match('/^1Z[A-Z0-9]{16}$/', $tn) === 1) {
            return 'ups';
        }

        if (preg_match('/^TBA[A-Z0-9]+$/', $tn) === 1) {
            return 'amazon';
        }

        if (preg_match('/^1LS[A-Z0-9]+$/', $tn) === 1 || preg_match('/^LX[A-Z0-9]{8,}$/', $tn) === 1) {
            return 'lasership';
        }

        if (preg_match('/^[CD]\d{10,18}$/', $tn) === 1) {
            return 'ontrac';
        }

        if (self::looksLikeUsps($tn)) {
            return 'usps';
        }

        if (preg_match('/^\d{10}$/', $tn) === 1) {
            return 'dhl';
        }

        if (preg_match('/^\d{12,22}$/', $tn) === 1) {
            return 'fedex';
        }

        return null;
    }

    private static function looksLikeUsps(string $tn): bool
    {
        if (preg_match('/^420\d{5}/', $tn) === 1) {
            return strlen($tn) >= 28;
        }

        if (preg_match('/^\d{20,22}$/', $tn) !== 1) {
            return false;
        }

        return preg_match('/^(91|92|93|94|95|96|97|98)/', $tn) === 1;
    }

    /**
     * Resolve stored carrier (including auto) to a concrete provider id.
     */
    public static function resolveCarrier(string $storedCarrier, string $trackingNumber): ?string
    {
        $stored = self::normalizeCarrier($storedCarrier);

        if ($stored !== 'auto') {
            return $stored;
        }

        return self::detect($trackingNumber);
    }

    public static function isLinkOnlyCarrier(?string $carrier): bool
    {
        if ($carrier === null) {
            return false;
        }

        return in_array(strtolower($carrier), self::LINK_ONLY_CARRIERS, true);
    }

    public static function linkOnlyStatusLabel(?string $carrier): ?string
    {
        if ($carrier === null) {
            return null;
        }

        return self::LINK_ONLY_META[strtolower($carrier)]['label'] ?? null;
    }

    public static function defaultExternalUrl(string $carrier, string $trackingNumber): ?string
    {
        $tn = self::normalizeTrackingNumber($trackingNumber);
        $carrier = strtolower($carrier);

        return match ($carrier) {
            'ups'       => 'https://www.ups.com/track?tracknum=' . rawurlencode($tn),
            'fedex'     => 'https://www.fedex.com/fedextrack/?trknbr=' . rawurlencode($tn),
            'dhl'       => 'https://www.dhl.com/us-en/home/tracking.html?tracking-id=' . rawurlencode($tn),
            'usps'      => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . rawurlencode($tn),
            'amazon'    => 'https://track.amazon.com/tracking/' . rawurlencode($tn),
            'ontrac'    => 'https://www.ontrac.com/tracking/?number=' . rawurlencode($tn),
            'lasership' => 'https://www.lasership.com/track/' . rawurlencode($tn),
            default     => null,
        };
    }
}
