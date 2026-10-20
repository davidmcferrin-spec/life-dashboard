<?php

declare(strict_types=1);

/**
 * Front controller for `php -S`. Static files under public/ are served as-is.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = dirname(__DIR__) . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
