<?php

declare(strict_types=1);

namespace Dashboard\Health;

use Dashboard\Support\JsonHttp;

/**
 * Reads sleep, activity, and workouts from a self-hosted Open Wearables API.
 * Samsung OAuth stays in Open Wearables. This class only sends the API key.
 */
final class OpenWearables
{
    /** @var null|callable(string, array<string, string>): array{status: int, json: array<string, mixed>|null, error: string} */
    private $http;

    public function __construct(private readonly string $base, private readonly string $token, ?callable $http = null)
    {
        $this->http = $http;
    }

    /**
     * @return array{points: list<array<string, mixed>>, workouts: list<array<string, mixed>>, errors: list<string>}
     */
    public function pull(string $userId, string $start, string $end, string $ingestedAt): array
    {
        HealthSettings::userId($userId);
        $errors = [];
        $sleep = $this->rows('summaries/sleep', $userId, $start, $end, $errors);
        $activity = $this->rows('summaries/activity', $userId, $start, $end, $errors);
        $workouts = $this->rows('events/workouts', $userId, $start, $end, $errors);
        $mapped = WearableMap::bundle($sleep, $activity, $workouts, $ingestedAt);
        $mapped['errors'] = $errors;

        return $mapped;
    }

    /**
     * @param list<string> $errors
     * @return list<array<string, mixed>>
     */
    private function rows(string $path, string $userId, string $start, string $end, array &$errors): array
    {
        $query = http_build_query([
            'start_date' => $start,
            'end_date'   => $end,
            'limit'      => 100,
        ]);
        $url = $this->base . '/api/v1/users/' . rawurlencode($userId) . '/' . $path . '?' . $query;
        $res = $this->send($url);
        if ($res['json'] === null) {
            $errors[] = $path . ': ' . ($res['error'] !== '' ? $res['error'] : 'no JSON');

            return [];
        }
        $data = $res['json']['data'] ?? null;

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * @return array{status: int, json: array<string, mixed>|null, error: string}
     */
    private function send(string $url): array
    {
        $headers = ['X-Open-Wearables-API-Key' => $this->token];
        if ($this->http !== null) {
            return ($this->http)($url, $headers);
        }

        return JsonHttp::get($url, $headers, 8, $this->token);
    }
}
