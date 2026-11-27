<?php

declare(strict_types=1);

/**
 * Front controller for `php -S`. Static files under public/ are served as-is.
 */

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$query = strpos($uri, '?');
if ($query !== false) {
    $uri = substr($uri, 0, $query);
}
$path = $uri !== '' ? $uri : '/';
$file = dirname(__DIR__) . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require dirname(__DIR__) . '/public/index.php';
