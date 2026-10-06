<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\DB\Database;
use Dashboard\Flight\FlightWatchService;

final class FlightsHandler
{
    private FlightWatchService $flights;

    public function __construct(Database $db)
    {
        $this->flights = FlightWatchService::fromDatabase($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        $all = isset($_GET['all']) && $_GET['all'] === '1';
        $list = $all
            ? $this->flights->listAll((int) $user['id'])
            : $this->flights->listForDashboard((int) $user['id']);

        jsonResponse(['flights' => $list]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function create(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();

        try {
            $flight = $this->flights->create(
                (int) $user['id'],
                (string) ($body['ident'] ?? ''),
                (string) ($body['departure_at'] ?? ''),
                isset($body['arrival_at']) ? (string) $body['arrival_at'] : null,
                isset($body['origin_icao']) ? (string) $body['origin_icao'] : null,
                isset($body['dest_icao']) ? (string) $body['dest_icao'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['flight' => $flight], 201);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function update(array $user, array $params): void
    {
        validateCsrf();
        $id   = (int) ($params['id'] ?? 0);
        $body = $this->jsonBody();

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid flight id.'], 400);
        }

        try {
            $flight = $this->flights->update($id, (int) $user['id'], $body);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if ($flight === null) {
            jsonResponse(['error' => 'Flight not found.'], 404);
        }

        jsonResponse(['flight' => $flight]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function delete(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0 || !$this->flights->delete($id, (int) $user['id'])) {
            jsonResponse(['error' => 'Flight not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
