#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Tracking\Services;

$counts = Services::fromDatabase(Database::getInstance())->briefs->runDue();

echo '[' . date('Y-m-d H:i:s') . '] morning brief: '
    . $counts['ran'] . ' ran, ' . $counts['waiting'] . ' waiting' . PHP_EOL;
