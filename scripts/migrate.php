#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/migrate.php — run database migrations and seed data.
 *
 * Usage:
 *   php scripts/migrate.php           # migrate + seed feeds
 *   php scripts/migrate.php --fresh   # drop all tables first (DESTRUCTIVE)
 *
 * Creates admin user on first run if no users exist.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\DB\Schema;
use Dashboard\Auth\Auth;

$db     = Database::getInstance();
$schema = new Schema($db);
$auth   = new Auth($db);

$fresh = in_array('--fresh', $argv ?? [], true);

// -------------------------------------------------------
// Fresh mode — drop all tables (DESTRUCTIVE)
// -------------------------------------------------------
if ($fresh) {
    echo "\n⚠️  --fresh flag: dropping all tables...\n";
    $tables = [
        'migrations', 'users', 'autologin_tokens', 'user_preferences',
        'bookmark_folders', 'bookmarks', 'feed_sources', 'user_feeds',
        'feed_cache', 'flights', 'notes', 'user_clipboards',
        'clipboard_share_links', 'clipboard_user_shares',
        'user_flight_watches', 'user_ping_targets', 'user_stock_symbols',
        'user_cloudpad_canvases', 'cloudpad_share_links', 'cloudpad_user_shares',
        'alarm_sounds', 'user_package_watches',
        'user_todo_lists', 'user_todo_items', 'todo_list_user_shares',
    ];
    if ($db->isPgsql()) {
        // Every table in the app schema, dependencies handled by CASCADE.
        $tables = array_column($db->fetchAll(
            "SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename"
        ), 'tablename');
    }
    foreach ($tables as $t) {
        $db->execute($db->isPgsql() ? "DROP TABLE IF EXISTS \"{$t}\" CASCADE" : "DROP TABLE IF EXISTS {$t}");
        echo "  - Dropped: {$t}\n";
    }
    echo "\n";
}

// -------------------------------------------------------
// Run migrations
// -------------------------------------------------------
echo "Running migrations...\n";
$schema->migrate();

echo "\nSeeding feed sources...\n";
$schema->seedFeedSources();

echo "\nRepairing feed source URLs...\n";
$schema->repairFeedSourceUrls();

echo "\nRepairing feed categories...\n";
$schema->repairFeedCategories();

echo "\nSeeding default todo lists...\n";
$schema->seedDefaultTodoLists();

echo "\nMarking widget default seeds for existing users...\n";
$schema->markWidgetDefaultsSeededForExistingUsers();

echo "\nMigrating dashboard feed pins for existing users...\n";
$schema->migrateDashboardFeedPins();

echo "\nSeeding stock dashboard pins for existing users...\n";
$schema->seedStocksDashboardPins();

echo "\nMigrating alarm sound storage...\n";
$schema->migrateAlarmSounds();

// -------------------------------------------------------
// Create first admin user if none exist
// -------------------------------------------------------
$userCount = (int) $db->fetchScalar('SELECT COUNT(*) FROM users');

if ($userCount === 0) {
    echo "\n--- First Run: Create Admin User ---\n";

    $username = prompt('Username: ');
    $email    = prompt('Email: ');
    $password = promptSecret('Password (hidden): ');

    if (strlen($password) < 8) {
        echo "Password must be at least 8 characters.\n";
        exit(1);
    }

    $userId = $auth->createUser($username, $email, $password, isAdmin: true);
    \Dashboard\Settings\FeedPreferenceService::fromDatabase($db)->seedDashboardPins($userId);
    echo "\n✓ Admin user created (ID: {$userId})\n";

    // Offer to generate an auto-login token
    $makeToken = strtolower(trim(prompt("\nGenerate auto-login token? [y/N]: ")));
    if ($makeToken === 'y') {
        $label     = prompt('Token label (e.g. "Home browser"): ');
        $expiryStr = prompt('Expiry interval (e.g. P1Y, P30D) or leave blank for no expiry: ');
        $token     = $auth->createToken($userId, $label ?: null, $expiryStr ?: null);
        $appUrl    = \Dashboard\Config::get('APP_URL', 'https://yourdomain.com');
        echo "\n✓ Auto-login URL:\n  {$appUrl}/auto/{$token}\n";
        echo "  Save this somewhere safe — it won't be shown again.\n";
    }
}

echo "\n✅ Done.\n\n";

// -------------------------------------------------------
// Helpers
// -------------------------------------------------------

function prompt(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

function promptSecret(string $label): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        echo $label;
        return trim((string) fgets(STDIN));
    }

    echo $label;
    // Only toggle echo on a real terminal (piped input, e.g. provisioning, has no TTY).
    $tty = function_exists('stream_isatty') && stream_isatty(STDIN);
    if ($tty) {
        system('stty -echo');
    }
    $input = trim((string) fgets(STDIN));
    if ($tty) {
        system('stty echo');
    }
    echo "\n";
    return $input;
}
