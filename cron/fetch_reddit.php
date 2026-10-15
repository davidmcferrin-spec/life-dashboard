#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Refresh tracked subreddits into reddit_posts.
 * One request per second. A rate limit stops the rest of this run.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Reddit\RedditClient;
use Dashboard\Reddit\RedditService;

$db = Database::getInstance();
$start = microtime(true);
echo '[' . gmdate('Y-m-d H:i:s') . '] Starting Reddit fetch...' . PHP_EOL;

$users = $db->fetchAll('SELECT DISTINCT user_id FROM reddit_subs');
$reddit = new RedditService($db);
$client = RedditClient::fromConfig();
foreach ($users as $row) {
    $userId = (int) $row['user_id'];
    try {
        $errors = $reddit->refresh($userId, $client);
        echo '[OK]    user ' . $userId . ($errors === [] ? '' : ' (' . count($errors) . ' error(s))') . PHP_EOL;
        foreach ($errors as $error) {
            echo '        ' . $error . PHP_EOL;
        }
    } catch (\Throwable $e) {
        echo '[ERROR] user ' . $userId . ': ' . $e->getMessage() . PHP_EOL;
    }
    if ($client->rateLimited()) {
        echo '[STOP]  Reddit rate limit — remaining users wait for the next run.' . PHP_EOL;
        break;
    }
}

$elapsed = round(microtime(true) - $start, 2);
echo '[' . gmdate('Y-m-d H:i:s') . '] Done. ' . count($users) . " user(s). {$elapsed}s" . PHP_EOL;
