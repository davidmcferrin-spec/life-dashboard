<?php

declare(strict_types=1);

/**
 * /api/time — authoritative server clock for client offset sync.
 *
 * Auth: session required (enforced in router).
 */

if (!defined('ROOT_DIR')) {
    require dirname(__DIR__) . '/load-app.php';
}

jsonResponse([
    'server_time_ms'  => (int) round(microtime(true) * 1000),
    'server_time_utc' => gmdate('c'),
]);
