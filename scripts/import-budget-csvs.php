#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-time import of The McFerrin Budget CSVs.
 *
 *   php scripts/import-budget-csvs.php --user USERNAME --dir /path/to/csvs
 *
 * Optional map.json in that folder renames columns. See docs/money-import.md.
 * The script asks once which payee is the Creator subscription. That name is
 * not saved, not logged, and not written to the database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Money\BudgetImporter;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

$args = $argv;
array_shift($args);
$userName = null;
$dir = null;
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--user' && isset($args[$i + 1])) {
        $userName = $args[++$i];
        continue;
    }
    if ($args[$i] === '--dir' && isset($args[$i + 1])) {
        $dir = $args[++$i];
        continue;
    }
    fwrite(STDERR, "usage: php scripts/import-budget-csvs.php --user USERNAME --dir /path/to/csvs\n");
    exit(64);
}
if ($userName === null || $dir === null) {
    fwrite(STDERR, "usage: php scripts/import-budget-csvs.php --user USERNAME --dir /path/to/csvs\n");
    exit(64);
}

$db = Database::getInstance();
$user = $db->fetchOne('SELECT id, username FROM users WHERE username = ? OR email = ?', [$userName, $userName]);
if ($user === null) {
    fwrite(STDERR, "No user named {$userName}.\n");
    exit(1);
}

$secret = readCreatorName(STDIN);
$svc = Services::fromDatabase($db);
$importer = new BudgetImporter($db, $svc->money, $svc->plan, $svc->pages);

try {
    $counts = $importer->importDirectory((int) $user['id'], $dir, $secret);
} catch (ValidationException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Imported for {$user['username']}:\n";
foreach (['transactions', 'accounts', 'budget_lines', 'debts', 'sinking_funds', 'snapshots', 'credit_scores', 'retirement', 'notes', 'aliased'] as $key) {
    echo sprintf("  %-16s %s\n", $key, (string) $counts[$key]);
}
echo "Postgres is now the source of truth. Delete the CSVs from this server once the counts look right.\n";

/**
 * One line from the terminal. Empty means there is nothing to alias.
 * The answer is not printed back.
 */
function readCreatorName($stream): string
{
    if (stream_isatty($stream)) {
        fwrite(STDOUT, "Which payee name should be stored as \"Creator subscription\"?\n");
        fwrite(STDOUT, "Press Enter if none. This answer is not saved.\n> ");
    }
    $line = fgets($stream);
    if ($line === false) {
        return '';
    }
    return trim($line);
}
