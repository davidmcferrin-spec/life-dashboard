<?php

declare(strict_types=1);

namespace Dashboard\Flight;

/**
 * Flight data provider (OpenSky, FlightAware, etc.).
 *
 * @param array<string, mixed> $watch Row from user_flight_watches
 */
interface FlightStatusProvider
{
    /**
     * @param array<string, mixed> $watch
     */
    public function fetchStatus(array $watch): FlightStatusResult;
}
