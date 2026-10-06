<?php

declare(strict_types=1);

/**
 * /api/weather — returns current weather for lat/lon coords.
 *
 * Query params: lat, lon
 * Auth: must be called from an authenticated session (enforced in router).
 * Data source: OpenWeatherMap free tier (current weather endpoint).
 */

if (!defined('ROOT_DIR')) {
    require dirname(__DIR__) . '/load-app.php';
}

use Dashboard\Config;

header('Content-Type: application/json; charset=utf-8');

$lat = (float) ($_GET['lat'] ?? 0);
$lon = (float) ($_GET['lon'] ?? 0);

if ($lat === 0.0 && $lon === 0.0) {
    jsonResponse(['error' => 'Missing lat/lon'], 400);
}

$apiKey = Config::maybe('OWM_API_KEY');

if ($apiKey === null) {
    jsonResponse(['error' => 'Weather API key not configured.'], 503);
}

// Cache per lat/lon, 10-minute TTL (free tier: 1000 calls/day)
$cacheKey  = sprintf('weather_%s_%s', round($lat, 2), round($lon, 2));
$cacheFile = STORAGE_DIR . '/cache/weather/' . $cacheKey . '.json';
$cacheTTL  = 600; // 10 minutes

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached !== null) {
        $cached['cached'] = true;
        echo json_encode($cached);
        exit;
    }
}

// Fetch from OpenWeatherMap
$url = sprintf(
    'https://api.openweathermap.org/data/2.5/weather?lat=%s&lon=%s&units=imperial&appid=%s',
    $lat, $lon, $apiKey
);

$ctx  = stream_context_create(['http' => ['timeout' => 5]]);
$body = @file_get_contents($url, false, $ctx);

if ($body === false) {
    jsonResponse(['error' => 'Could not reach weather service.'], 502);
}

$raw = json_decode($body, true);

if (!is_array($raw) || !isset($raw['main'])) {
    jsonResponse(['error' => 'Invalid weather response.'], 502);
}

$result = [
    'city'        => $raw['name'] ?? 'Unknown',
    'temp'        => (int) round($raw['main']['temp']),
    'feels_like'  => (int) round($raw['main']['feels_like']),
    'humidity'    => (int) $raw['main']['humidity'],
    'description' => $raw['weather'][0]['description'] ?? '',
    'icon'        => $raw['weather'][0]['icon']        ?? '01d',
    'wind_speed'  => (int) round($raw['wind']['speed'] ?? 0),
    'visibility'  => isset($raw['visibility']) ? (int) round($raw['visibility'] / 1609) : 0, // m → mi
    'fetched_at'  => date('c'),
    'cached'      => false,
];

// Write cache
$cacheDir = dirname($cacheFile);
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0750, true);
}
file_put_contents($cacheFile, json_encode($result));

echo json_encode($result);
