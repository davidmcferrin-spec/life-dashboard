<?php

declare(strict_types=1);

/**
 * Direct file access fallback (Apache serves existing files before rewrite).
 */
require dirname(__DIR__) . '/load-app.php';

use Dashboard\Api\FeedsHandler;
use Dashboard\Auth\Auth;
use Dashboard\DB\Database;

$db   = Database::getInstance();
$user = (new Auth($db))->requireAuth();

(new FeedsHandler($db))->list($user);
