<?php

declare(strict_types=1);

namespace Dashboard\Flight;

/**
 * Normalized flight status from any provider.
 */
final class FlightStatusResult
{
    public function __construct(
        public readonly string $statusLabel,
        public readonly bool $airborne,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?float $altitudeM = null,
        public readonly ?float $velocityMs = null,
        public readonly ?float $heading = null,
        public readonly ?string $originDisplay = null,
        public readonly ?string $destDisplay = null,
        public readonly ?string $icao24 = null,
        public readonly ?array $raw = null,
        public readonly bool $markComplete = false,
    ) {}
}
