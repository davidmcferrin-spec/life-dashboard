<?php

declare(strict_types=1);

/**
 * Bootstrap loader for supported layouts:
 *
 * Dreamhost (nested):  public/ at domain root, app at ./personal-dashboard/
 * Dreamhost (sibling): public/ at domain root, app at ../personal-dashboard/
 * Local dev:           public/ subdirectory, app at repo root (parent of public/)
 */

if (is_file(__DIR__ . '/personal-dashboard/bootstrap.php')) {
    define('DASHBOARD_WEB_ROOT', __DIR__);
    require __DIR__ . '/personal-dashboard/bootstrap.php';
    return;
}

$siblingApp = dirname(__DIR__) . '/personal-dashboard/bootstrap.php';
if (is_file($siblingApp)) {
    define('DASHBOARD_WEB_ROOT', __DIR__);
    require $siblingApp;
    return;
}

$repoRoot = dirname(__DIR__);
if (is_file($repoRoot . '/bootstrap.php')) {
    require $repoRoot . '/bootstrap.php';
    return;
}

throw new RuntimeException(
    'Cannot find bootstrap.php. Expected ./personal-dashboard/bootstrap.php (nested), '
    . '../personal-dashboard/bootstrap.php (sibling), or ../bootstrap.php (local dev).'
);
