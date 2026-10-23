#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Jarvis\Wizard;
use Dashboard\Support\Clock;

$counts = (new Wizard(Database::getInstance(), Clock::fromConfig()))->runDue();

echo '[' . date('Y-m-d H:i:s') . '] jarvis routines: '
    . $counts['ran'] . ' ran, ' . $counts['waiting'] . ' waiting' . PHP_EOL;
