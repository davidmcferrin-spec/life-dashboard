#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/migrate-sqlite-to-pgsql.php — import a personal-dashboard SQLite DB
 * into the life-dashboard PostgreSQL database configured in .env (DB_DRIVER=pgsql).
 *
 * Usage:
 *   php scripts/migrate-sqlite-to-pgsql.php --sqlite=/path/to/dashboard.db --dry-run
 *   php scripts/migrate-sqlite-to-pgsql.php --sqlite=/path/to/dashboard.db
 *
 * Options:
 *   --sqlite=PATH     Source SQLite file (copy it off Dreamhost first; opened read-only).
 *   --dry-run         Preflight only: report what would be imported and any problems.
 *   --force           Allow importing over a target that already has users (REPLACES ALL DATA).
 *   --skip-orphans    Drop rows whose foreign-key parent is missing instead of aborting.
 *   --truncate-long   Cut text longer than its VARCHAR(n) instead of aborting.
 *
 * Files under storage/uploads/ are NOT in the database — rsync them separately
 * (see README "Migrating from personal-dashboard").
 *
 * Exit codes: 0 ok · 1 preflight/import failed · 2 usage/config error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\DB\Schema;
use Dashboard\DB\SqliteToPgsqlImporter;

$opts = getopt('', ['sqlite:', 'dry-run', 'force', 'skip-orphans', 'truncate-long', 'help']);

if (isset($opts['help']) || !isset($opts['sqlite'])) {
    fwrite(STDERR, "Usage: php scripts/migrate-sqlite-to-pgsql.php --sqlite=PATH [--dry-run] [--force] [--skip-orphans] [--truncate-long]\n");
    exit(2);
}

$sqlitePath = (string) $opts['sqlite'];
if (!is_file($sqlitePath) || !is_readable($sqlitePath)) {
    fwrite(STDERR, "SQLite file not found or unreadable: {$sqlitePath}\n");
    exit(2);
}

$db = Database::getInstance();
if (!$db->isPgsql()) {
    fwrite(STDERR, "Target must be PostgreSQL: set DB_DRIVER=pgsql in .env\n");
    exit(2);
}

$dryRun = isset($opts['dry-run']);

echo "Source: {$sqlitePath}\n";
echo 'Target: pgsql ' . \Dashboard\Config::get('DB_NAME', 'lifedash') . "\n";
echo $dryRun ? "Mode:   DRY RUN (nothing will be written)\n\n" : "Mode:   IMPORT\n\n";

// Make sure the target schema is current before reading its catalog.
echo "Ensuring target schema is migrated...\n";
(new Schema($db))->migrate();

$existingUsers = (int) $db->fetchScalar('SELECT COUNT(*) FROM users');
if ($existingUsers > 0 && !$dryRun && !isset($opts['force'])) {
    fwrite(STDERR, "\nTarget already has {$existingUsers} user(s). Importing REPLACES ALL DATA — re-run with --force if that is intended.\n");
    exit(2);
}

// Read-only, immutable open so a live Dreamhost copy is never modified.
$source = new PDO('sqlite:file:' . rawurlencode(realpath($sqlitePath)) . '?mode=ro&immutable=1', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$importer = new SqliteToPgsqlImporter(
    source: $source,
    target: $db->pdo(),
    skipOrphans: isset($opts['skip-orphans']),
    truncateLongText: isset($opts['truncate-long']),
    logger: static function (string $line): void {
        echo $line . "\n";
    },
);

echo "\nPreflight...\n";
$plan = $importer->preflight();

foreach ($plan['warnings'] as $w) {
    echo "  ! {$w}\n";
}

if ($plan['errors'] !== []) {
    echo "\n✗ Preflight found " . count($plan['errors']) . " problem(s):\n";
    foreach (array_slice($plan['errors'], 0, 50) as $err) {
        echo "  - {$err}\n";
    }
    if (count($plan['errors']) > 50) {
        echo '  … and ' . (count($plan['errors']) - 50) . " more\n";
    }
    echo "\nNothing was written.\n";
    exit(1);
}

$total = array_sum(array_map('count', $plan['rows']));
echo "\nPreflight OK — {$total} row(s) across " . count($plan['order']) . " table(s).\n";

if ($dryRun) {
    echo "Dry run complete. Re-run without --dry-run to import.\n";
    exit(0);
}

echo "\nImporting (single transaction)...\n";
try {
    $counts = $importer->import($plan);
} catch (\Throwable $e) {
    error_log('[Dashboard][Import] ' . $e->getMessage());
    fwrite(STDERR, "\n✗ Import failed and was rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n✅ Imported " . array_sum($counts) . " row(s).\n";
echo "Next: php scripts/migrate.php   (re-applies feed seeds/repairs on the imported data)\n";
exit(0);
