<?php

declare(strict_types=1);

namespace Dashboard\Flight;

use Dashboard\Support\HttpClient;

/**
 * OpenSky Network ADS-B flight lookup (callsign + optional ICAO24).
 */
final class OpenSkyFlightProvider implements FlightStatusProvider
{
    private const API_BASE = 'https://opensky-network.org/api';

    public function __construct(
        private readonly OpenSkyTokenManager $tokens,
        private readonly HttpClient $http = new HttpClient(),
    ) {}

    /**
     * @param array<string, mixed> $watch
     */
    public function fetchStatus(array $watch): FlightStatusResult
    {
        $ident = FlightWatchService::normalizeIdent((string) $watch['ident']);
        $token = $this->tokens->getAccessToken();

        if ($token === null) {
            return new FlightStatusResult(
                statusLabel: 'OpenSky not configured',
                airborne: false,
            );
        }

        $icao24 = isset($watch['icao24']) && $watch['icao24'] !== ''
            ? strtolower((string) $watch['icao24'])
            : null;

        $now   = time();
        $begin = $now - 7200;
        $end   = $now;

        $flight = null;

        if ($icao24 !== null) {
            $flight = $this->findFlightByIcao($token, $icao24, $begin, $end, $ident);
        }

        if ($flight === null) {
            $flight = $this->findFlightInInterval($token, $begin, $end, $ident);
        }

        if ($flight !== null && !empty($flight['icao24'])) {
            $icao24 = strtolower((string) $flight['icao24']);
        }

        $state = ($icao24 !== null) ? $this->fetchState($token, $icao24) : null;

        if ($state !== null) {
            return $this->fromStateVector($state, $ident, $flight, $icao24);
        }

        if ($flight !== null) {
            return $this->fromFlightRecord($flight, $ident, $icao24);
        }

        return new FlightStatusResult(
            statusLabel: 'No ADS-B data yet',
            airborne: false,
            icao24: $icao24,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchState(string $token, string $icao24): ?array
    {
        $url = self::API_BASE . '/states/all?icao24=' . urlencode($icao24);

        try {
            $response = $this->http->request('GET', $url, null, [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ], 12);
        } catch (\Throwable) {
            return null;
        }

        if ($response['status'] !== 200) {
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['states'][0])) {
            return null;
        }

        return $data['states'][0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findFlightInInterval(string $token, int $begin, int $end, string $ident): ?array
    {
        $url = self::API_BASE . '/flights/all?' . http_build_query([
            'begin' => $begin,
            'end'   => $end,
        ]);

        $flights = $this->getJson($token, $url);
        if ($flights === null) {
            return null;
        }

        return $this->matchCallsign($flights, $ident);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findFlightByIcao(
        string $token,
        string $icao24,
        int $begin,
        int $end,
        string $ident,
    ): ?array {
        $url = self::API_BASE . '/flights/aircraft?' . http_build_query([
            'icao24' => $icao24,
            'begin'  => $begin,
            'end'    => $end,
        ]);

        $flights = $this->getJson($token, $url);

        if ($flights === null) {
            return null;
        }

        return $this->matchCallsign($flights, $ident) ?? ($flights[0] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $flights
     * @return array<string, mixed>|null
     */
    private function matchCallsign(array $flights, string $ident): ?array
    {
        foreach ($flights as $flight) {
            if (!is_array($flight)) {
                continue;
            }
            $cs = strtoupper(trim((string) ($flight['callsign'] ?? '')));
            if ($cs === '' || $cs === $ident || str_starts_with($cs, $ident)) {
                return $flight;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function getJson(string $token, string $url): ?array
    {
        try {
            $response = $this->http->request('GET', $url, null, [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ], 20);
        } catch (\Throwable) {
            error_log('[Dashboard][OpenSky] Request failed: ' . $url);
            return null;
        }

        if ($response['status'] === 404) {
            return [];
        }

        if ($response['status'] !== 200) {
            error_log('[Dashboard][OpenSky] HTTP ' . $response['status'] . ' for ' . $url);
            return null;
        }

        $data = json_decode($response['body'], true);

        return is_array($data) ? $data : [];
    }

    /**
     * State vector row from /states/all.
     *
     * @param array<int, mixed> $state
     * @param array<string, mixed>|null $flight
     */
    private function fromStateVector(
        array $state,
        string $ident,
        ?array $flight,
        ?string $icao24,
    ): FlightStatusResult {
        $onGround = (bool) ($state[8] ?? true);
        $alt      = isset($state[13]) ? (float) $state[13] : (isset($state[7]) ? (float) $state[7] : null);
        $velocity = isset($state[9]) ? (float) $state[9] : null;
        $heading  = isset($state[10]) ? (float) $state[10] : null;
        $lat      = isset($state[6]) ? (float) $state[6] : null;
        $lon      = isset($state[5]) ? (float) $state[5] : null;

        $origin = $flight['estDepartureAirport'] ?? null;
        $dest   = $flight['estArrivalAirport'] ?? null;

        return new FlightStatusResult(
            statusLabel: $onGround ? 'On ground (ADS-B)' : 'Airborne',
            airborne: !$onGround,
            latitude: $lat,
            longitude: $lon,
            altitudeM: $alt,
            velocityMs: $velocity,
            heading: $heading,
            originDisplay: $origin !== null ? (string) $origin : null,
            destDisplay: $dest !== null ? (string) $dest : null,
            icao24: $icao24,
            raw: ['state' => $state, 'flight' => $flight],
            markComplete: $onGround && $flight !== null && isset($flight['lastSeen']),
        );
    }

    /**
     * @param array<string, mixed> $flight
     */
    private function fromFlightRecord(array $flight, string $ident, ?string $icao24): FlightStatusResult
    {
        $first = (int) ($flight['firstSeen'] ?? 0);
        $last  = (int) ($flight['lastSeen'] ?? 0);
        $now   = time();

        $likelyDone = $last > 0 && ($now - $last) > 7200;

        return new FlightStatusResult(
            statusLabel: $likelyDone ? 'Recently landed' : 'Tracked (no live position)',
            airborne: false,
            originDisplay: isset($flight['estDepartureAirport']) ? (string) $flight['estDepartureAirport'] : null,
            destDisplay: isset($flight['estArrivalAirport']) ? (string) $flight['estArrivalAirport'] : null,
            icao24: $icao24 ?? (isset($flight['icao24']) ? strtolower((string) $flight['icao24']) : null),
            raw: $flight,
            markComplete: $likelyDone,
        );
    }
}
