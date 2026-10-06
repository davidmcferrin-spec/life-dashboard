<?php

declare(strict_types=1);

namespace Dashboard\Flight;

use Dashboard\Config;
use Dashboard\Support\HttpClient;

/**
 * FlightAware AeroAPI stub — enable when FLIGHT_PROVIDER=flightaware and key is set.
 */
final class FlightAwareFlightProvider implements FlightStatusProvider
{
    private const API_BASE = 'https://aeroapi.flightaware.com/aeroapi';

    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    /**
     * @param array<string, mixed> $watch
     */
    public function fetchStatus(array $watch): FlightStatusResult
    {
        $apiKey = Config::maybe('FLIGHTAWARE_API_KEY');

        if ($apiKey === null) {
            return new FlightStatusResult(
                statusLabel: 'FlightAware API key not set',
                airborne: false,
            );
        }

        $ident = FlightWatchService::normalizeIdent((string) $watch['ident']);
        $url   = self::API_BASE . '/flights/' . rawurlencode($ident);

        try {
            $response = $this->http->request('GET', $url, null, [
                'x-apikey' => $apiKey,
                'Accept'   => 'application/json',
            ], 15);
        } catch (\Throwable $e) {
            error_log('[Dashboard][FlightAware] ' . $e->getMessage());
            return new FlightStatusResult(
                statusLabel: 'FlightAware unreachable',
                airborne: false,
            );
        }

        if ($response['status'] !== 200) {
            return new FlightStatusResult(
                statusLabel: 'FlightAware HTTP ' . $response['status'],
                airborne: false,
            );
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['flights'][0])) {
            return new FlightStatusResult(
                statusLabel: 'No FlightAware match',
                airborne: false,
            );
        }

        $flight = $data['flights'][0];
        $status = (string) ($flight['status'] ?? 'Unknown');

        return new FlightStatusResult(
            statusLabel: $status,
            airborne: str_contains(strtolower($status), 'en route') || str_contains(strtolower($status), 'airborne'),
            originDisplay: (string) ($flight['origin']['code'] ?? $flight['origin']['code_iata'] ?? ''),
            destDisplay: (string) ($flight['destination']['code'] ?? $flight['destination']['code_iata'] ?? ''),
            raw: $flight,
            markComplete: self::isTerminalStatus($status),
        );
    }

    public static function webUrlForIdent(string $ident): string
    {
        return 'https://www.flightaware.com/live/flight/' . rawurlencode(
            FlightWatchService::normalizeIdent($ident)
        );
    }

    /**
     * @param array<string, mixed> $flight AeroAPI flight object
     */
    public static function webUrlForFlight(array $flight, string $ident): string
    {
        if (!empty($flight['ident_icao'])) {
            return self::webUrlForIdent((string) $flight['ident_icao']);
        }

        if (!empty($flight['ident_iata'])) {
            return self::webUrlForIdent((string) $flight['ident_iata']);
        }

        return self::webUrlForIdent($ident);
    }

    private static function isTerminalStatus(string $status): bool
    {
        $lower = strtolower($status);

        foreach (['arrived', 'cancelled', 'canceled', 'diverted', 'landed'] as $term) {
            if (str_contains($lower, $term)) {
                return true;
            }
        }

        return false;
    }
}
