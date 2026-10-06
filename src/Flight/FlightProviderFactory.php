<?php

declare(strict_types=1);

namespace Dashboard\Flight;

use Dashboard\Config;

final class FlightProviderFactory
{
    public static function make(): FlightStatusProvider
    {
        $driver = strtolower(Config::get('FLIGHT_PROVIDER', 'opensky'));

        return match ($driver) {
            'flightaware' => new FlightAwareFlightProvider(),
            default       => new OpenSkyFlightProvider(new OpenSkyTokenManager()),
        };
    }
}
