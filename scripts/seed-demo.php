#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Fill a throwaway database with a fictional demo account for screenshots.
 *
 * Refuses any database name other than lifedash_demo, then wipes that
 * database's public schema. Nothing here is copied from a live server.
 *
 *   createdb -O lifedash lifedash_demo
 *   php scripts/seed-demo.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\Auth\Auth;
use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\DB\Schema;
use Dashboard\Pantry\PantryDates;
use Dashboard\Settings\PreferenceService;
use Dashboard\Support\Clock;
use Dashboard\Tracking\Services;
use Dashboard\Widgets\WidgetService;

const DEMO_DB = 'lifedash_demo';
const DEMO_USER = 'demo';
const DEMO_EMAIL = 'demo@example.test';
const DEMO_PASSWORD = 'screenshot-demo';

Config::overlay('DB_NAME', DEMO_DB);
Database::reset();
$db = Database::getInstance();
if ($db->fetchScalar('SELECT current_database()') !== DEMO_DB) {
    fwrite(STDERR, "Refusing to seed: connected database is not " . DEMO_DB . ".\n");
    exit(1);
}

$db->pdo()->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
(new Schema($db))->migrate(quiet: true);

$auth = new Auth($db);
$userId = $auth->createUser(DEMO_USER, DEMO_EMAIL, DEMO_PASSWORD, isAdmin: true);
$clock = new Clock('America/Chicago');
$svc = new Services($db, $clock);
$prefs = new PreferenceService($db);
$today = $clock->today();

$prefs->setTheme($userId, 'jarvis');
$prefs->setJarvisAccent($userId, '#1aa6a6');
$prefs->setAiPresence($userId, false);
$prefs->set($userId, WidgetService::COMPACT_PREF, '1');
$prefs->set($userId, 'dashboard_grid_seeded', '1');

$walk = habit($svc, $userId, 'Walk', 'walk', '#3dd68c', 5);
$read = habit($svc, $userId, 'Read', 'read', '#7aa2ff', 4);
$stretch = habit($svc, $userId, 'Stretch', 'stretch', '#f0b429', 3);
foreach ([-6, -5, -4, -3, -1, 0] as $offset) {
    $svc->habits->set($userId, $walk, true, day($today, $offset), 'manual');
}
foreach ([-6, -4, -2, 0] as $offset) {
    $svc->habits->set($userId, $read, true, day($today, $offset), 'manual');
}
foreach ([-5, -3, -1] as $offset) {
    $svc->habits->set($userId, $stretch, true, day($today, $offset), 'manual');
}

$mood = $svc->metrics->ensureMood($userId);
$moodNotes = [
    -6 => [3, 'Quiet morning'],
    -4 => [4, 'Long walk'],
    -2 => [4, 'Easy evening'],
    -1 => [5, 'Slept well'],
    0 => [4, 'Steady day'],
];
foreach ($moodNotes as $offset => [$value, $note]) {
    $svc->points->record($userId, (int) $mood['id'], [
        'value' => $value,
        'date' => day($today, $offset),
        'note' => $note,
        'source' => 'manual',
    ]);
}

$steps = $svc->metrics->create($userId, [
    'name' => 'Steps',
    'key' => 'steps',
    'kind' => 'number',
    'unit' => 'steps',
    'config' => ['decimals' => 0, 'goal' => 8000, 'goal_direction' => 'up'],
], 'user');
$water = $svc->metrics->create($userId, [
    'name' => 'Water',
    'key' => 'water_oz',
    'kind' => 'number',
    'unit' => 'oz',
    'config' => ['decimals' => 0, 'goal' => 64, 'goal_direction' => 'up'],
], 'user');
$sleep = $svc->metrics->create($userId, [
    'name' => 'Sleep',
    'key' => 'sleep_hours',
    'kind' => 'number',
    'unit' => 'h',
    'config' => ['decimals' => 1, 'goal' => 8, 'goal_direction' => 'up'],
], 'user');
$stepSeries = [4200, 6100, 8800, 5400, 7300, 9100, 4700, 8200, 6900, 7600, 5100, 8400, 6300, 6420];
foreach ($stepSeries as $i => $value) {
    $svc->points->record($userId, (int) $steps['id'], [
        'value' => $value,
        'date' => day($today, $i - 13),
        'source' => 'manual',
        'dedupe_key' => 'demo:steps:' . day($today, $i - 13),
    ]);
}
$svc->points->record($userId, (int) $water['id'], [
    'value' => 40,
    'date' => $today,
    'source' => 'manual',
    'dedupe_key' => 'demo:water:' . $today,
]);
$svc->points->record($userId, (int) $sleep['id'], [
    'value' => 7.4,
    'date' => $today,
    'source' => 'manual',
    'dedupe_key' => 'demo:sleep:' . $today,
]);

$svc->todos->ensureDefaults($userId);
$lists = [];
foreach ($svc->todos->listSummaries($userId) as $list) {
    $lists[$list['name']] = (int) $list['id'];
}
todo($svc, $userId, $lists['Work'], 'Draft the Friday note', day($today, 1), 2);
todo($svc, $userId, $lists['Work'], 'Send the studio the rundown', day($today, 0), 1);
todo($svc, $userId, $lists['Personal'], 'Return the library books', day($today, 3), 3);
todo($svc, $userId, $lists['Home'], 'Replace the hallway bulb', day($today, 2), 3);

$groceries = $svc->shopping->createList($userId, 'Groceries');
$hardware = $svc->shopping->createList($userId, 'Hardware');
$svc->shopping->addItems($userId, (int) $groceries['id'], [
    'oat milk',
    '2 lb rice',
    'sourdough',
    'sparkling water',
], 'user');
$svc->shopping->addItems($userId, (int) $hardware['id'], [
    'hallway bulbs',
    'painter tape',
], 'user');

$bread = $svc->pantry->upsertProduct($userId, [
    'name' => 'Sourdough',
    'category' => 'Bakery',
    'default_unit' => 'pkg',
    'units_per_package' => 11,
    'piece_unit' => 'slices',
    'shelf_life_fridge_days' => 7,
    'shelf_life_pantry_days' => 4,
]);
$waterPack = $svc->pantry->upsertProduct($userId, [
    'name' => 'Sparkling water',
    'category' => 'Beverages',
    'default_unit' => 'pkg',
    'units_per_package' => 8,
    'piece_unit' => 'cans',
    'shelf_life_pantry_days' => 180,
]);
$berries = $svc->pantry->upsertProduct($userId, [
    'name' => 'Mixed berries',
    'category' => 'Frozen',
    'default_unit' => 'bag',
    'shelf_life_freezer_days' => 90,
]);
$ketchup = $svc->pantry->upsertProduct($userId, [
    'name' => 'Ketchup',
    'category' => 'Pantry',
    'default_unit' => 'bottle',
    'shelf_life_fridge_days' => 60,
]);
$svc->pantry->addItems($userId, [[
    'product_id' => $bread['id'],
    'quantity' => 1,
    'location' => 'fridge',
    'purchased_at' => day($today, -1),
]], DEMO_USER);
$svc->pantry->addItems($userId, [[
    'product_id' => $waterPack['id'],
    'quantity' => 1,
    'location' => 'pantry',
    'purchased_at' => day($today, -2),
]], DEMO_USER);
$svc->pantry->addItems($userId, [[
    'product_id' => $berries['id'],
    'quantity' => 1,
    'unit' => 'bag',
    'location' => 'freezer',
    'purchased_at' => day($today, -10),
]], DEMO_USER);
$svc->pantry->addItems($userId, [[
    'product_id' => $ketchup['id'],
    'quantity' => 1,
    'qty_level' => 'low',
    'location' => 'fridge',
    'purchased_at' => day($today, -20),
]], DEMO_USER);

$checking = $svc->money->createAccount($userId, [
    'name' => 'Checking',
    'kind' => 'checking',
    'institution' => 'Northwind Bank',
    'balance' => 4280.16,
]);
$savings = $svc->money->createAccount($userId, [
    'name' => 'Savings',
    'kind' => 'savings',
    'institution' => 'Northwind Bank',
    'balance' => 12640.00,
]);
$card = $svc->money->createAccount($userId, [
    'name' => 'Harbor card',
    'kind' => 'credit',
    'institution' => 'Harbor Credit',
    'balance' => -640.22,
]);
$tx = [
    [$checking['id'], -42.18, 'Corner market', 'Food', -1],
    [$checking['id'], -18.40, 'City transit', 'Transport', -2],
    [$checking['id'], -12.60, 'Streaming', 'Subscriptions', -3],
    [$checking['id'], -45.00, 'Phone plan', 'Subscriptions', -4],
    [$checking['id'], 2100.00, 'Payroll', 'Income', -5],
    [$card['id'], -28.15, 'Cafe', 'Food', -1],
    [$savings['id'], 200.00, 'Transfer', 'Savings', -6],
];
foreach ($tx as $i => [$accountId, $amount, $payee, $category, $offset]) {
    $svc->money->addTransaction($userId, [
        'account_id' => $accountId,
        'amount' => $amount,
        'date' => day($today, $offset),
        'payee' => $payee,
        'category' => $category,
        'dedupe_key' => 'demo:tx:' . $i,
    ], 'manual');
}
$month = (int) substr($today, 5, 2);
$year = (int) substr($today, 0, 4);
$svc->plan->setBudgetLine($userId, $year, $month, 'Food', 400, null);
$svc->plan->setBudgetLine($userId, $year, $month, 'Transport', 80, 5);
$svc->plan->setBudgetLine($userId, $year, $month, 'Subscriptions', 60, 12);
$svc->plan->saveDebt($userId, [
    'name' => 'Harbor card',
    'balance' => 640.22,
    'apr' => 18.9,
    'minimum' => 35,
    'payoff_order' => 1,
    'due_day' => 12,
]);
$svc->plan->saveSinkingFund($userId, [
    'name' => 'Holiday trip',
    'target' => 500,
    'balance' => 180,
    'due_on' => day($today, 80),
]);
$svc->plan->setBabyStep($userId, 2, 'Paying down the card.');
$svc->plan->addCreditScore($userId, day($today, -12), 736, 0.22);

$svc->calls->log($userId, [
    'direction' => 'inbound',
    'phone' => '5550100142',
    'name' => 'Jordan Hale',
    'category' => 'personal',
    'reason' => 'Confirm Saturday pickup',
    'summary' => 'Asked to confirm a Saturday pickup at the community hall.',
    'status' => 'needs_return',
    'return_needed' => true,
    'urgency' => 'normal',
    'call_at' => day($today, 0) . ' 14:10:00',
], 'manual');
$svc->calls->log($userId, [
    'direction' => 'inbound',
    'phone' => '5550100198',
    'name' => 'Harbor Clinic',
    'company' => 'Harbor Clinic',
    'category' => 'personal',
    'reason' => 'Thursday appointment reminder',
    'summary' => 'Reminder for a routine Thursday visit. No account number was left.',
    'status' => 'resolved',
    'urgency' => 'low',
    'call_at' => day($today, -1) . ' 09:40:00',
], 'manual');
$svc->calls->log($userId, [
    'direction' => 'inbound',
    'phone' => '5550100177',
    'name' => 'Avery Quinn',
    'category' => 'vip',
    'reason' => 'Hold two seats for Friday',
    'summary' => 'Asked to hold two seats. No payment details.',
    'status' => 'needs_return',
    'return_needed' => true,
    'urgency' => 'high',
    'call_at' => day($today, 0) . ' 11:05:00',
], 'manual');

$folder = $svc->pages->createFolder($userId, 'Notes', null, DEMO_USER);
$staples = $svc->pages->create($userId, [
    'title' => 'Pantry staples',
    'kind' => 'reference',
    'folder_id' => $folder['id'],
    'body' => "Keep sourdough, oat milk, rice, and sparkling water on hand.\n\nBerries live in the freezer.\n",
], DEMO_USER);
$svc->pages->create($userId, [
    'title' => 'Weeknight plan',
    'kind' => 'note',
    'folder_id' => $folder['id'],
    'body' => "Tuesday: rice and berries.\n\nSee [[Pantry staples]].\n",
], DEMO_USER);
$svc->pages->create($userId, [
    'title' => 'Cedar Bay weekend',
    'kind' => 'project',
    'folder_id' => $folder['id'],
    'body' => "A made-up weekend note for the screenshot. No address.\n\nPack the sourdough from [[Pantry staples]].\n",
], DEMO_USER);

$placed = [
    $svc->widgets->create($userId, 'habits', null, [
        'metric_ids' => [$walk, $read, $stretch],
        'days' => '7',
    ], ['x' => 0, 'y' => 0, 'w' => 4, 'h' => 8], 'user'),
    $svc->widgets->create($userId, 'mood', null, ['days' => '14'], ['x' => 4, 'y' => 0, 'w' => 4, 'h' => 8], 'user'),
    $svc->widgets->create($userId, 'goal', null, [
        'metric_ids' => [(int) $water['id'], (int) $steps['id']],
        'view' => 'day',
    ], ['x' => 8, 'y' => 0, 'w' => 4, 'h' => 8], 'user'),
    $svc->widgets->create($userId, 'legacy.todos', null, [], ['x' => 0, 'y' => 8, 'w' => 4, 'h' => 8], 'user'),
    $svc->widgets->create($userId, 'shopping', null, [], ['x' => 4, 'y' => 8, 'w' => 4, 'h' => 11], 'user'),
    $svc->widgets->create($userId, 'pantry', null, ['soon_days' => 7], ['x' => 8, 'y' => 8, 'w' => 4, 'h' => 8], 'user'),
    $svc->widgets->create($userId, 'calls', null, ['limit' => 6], ['x' => 0, 'y' => 19, 'w' => 8, 'h' => 12], 'user'),
    $svc->widgets->create($userId, 'spending', null, ['period' => '30d'], ['x' => 8, 'y' => 19, 'w' => 4, 'h' => 6], 'user'),
    $svc->widgets->create($userId, 'chart', 'Steps', [
        'metric_ids' => [(int) $steps['id']],
        'style' => 'line',
        'period' => '30d',
        'bucket' => 'day',
        'agg' => 'last',
    ], ['x' => 0, 'y' => 31, 'w' => 8, 'h' => 6], 'user'),
    $svc->widgets->create($userId, 'pages', null, ['limit' => 6], ['x' => 8, 'y' => 31, 'w' => 4, 'h' => 5], 'user'),
    $svc->widgets->create($userId, 'networth', null, [], ['x' => 0, 'y' => 37, 'w' => 8, 'h' => 4], 'user'),
];
// Locked height keeps the seeded rows. Auto-fit would resize during a screenshot.
$db->execute(
    'UPDATE dashboard_widgets SET height_locked = 1 WHERE user_id = ?',
    [$userId]
);
$byType = [];
foreach ($placed as $widget) {
    $byType[$widget['type']] = (int) $widget['id'];
}
$svc->widgets->saveBreakpointLayout($userId, 18, [
    ['id' => $byType['habits'], 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 8],
    ['id' => $byType['mood'], 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 8],
    ['id' => $byType['goal'], 'x' => 12, 'y' => 0, 'w' => 6, 'h' => 8],
    ['id' => $byType['legacy.todos'], 'x' => 0, 'y' => 8, 'w' => 6, 'h' => 8],
    ['id' => $byType['shopping'], 'x' => 6, 'y' => 8, 'w' => 6, 'h' => 11],
    ['id' => $byType['pantry'], 'x' => 12, 'y' => 8, 'w' => 6, 'h' => 8],
    ['id' => $byType['calls'], 'x' => 0, 'y' => 19, 'w' => 9, 'h' => 12],
    ['id' => $byType['spending'], 'x' => 9, 'y' => 19, 'w' => 4, 'h' => 6],
    ['id' => $byType['pages'], 'x' => 13, 'y' => 19, 'w' => 5, 'h' => 5],
    ['id' => $byType['chart'], 'x' => 0, 'y' => 31, 'w' => 12, 'h' => 6],
    ['id' => $byType['networth'], 'x' => 12, 'y' => 31, 'w' => 6, 'h' => 4],
]);

$pantryToken = $svc->tokens->create($userId, 'Demo pantry', ['pantry:read'], null, true);
$moneyToken = $svc->tokens->create($userId, 'Demo money', ['money:read', 'money:write'], null, true);
$db->execute('UPDATE api_tokens SET prefix = ? WHERE id = ?', ['ld_demopantry', $pantryToken['id']]);
$db->execute('UPDATE api_tokens SET prefix = ? WHERE id = ?', ['ld_demomoney', $moneyToken['id']]);

fwrite(STDOUT, "Seeded " . DEMO_DB . " as " . DEMO_USER . " / " . DEMO_PASSWORD . "\n");
fwrite(STDOUT, "Page " . $staples['id'] . " is Pantry staples.\n");

function day(string $today, int $offset): string
{
    return PantryDates::addDays($today, $offset);
}

function habit(Services $svc, int $userId, string $name, string $key, string $color, int $perWeek): int
{
    $row = $svc->habits->create($userId, [
        'name' => $name,
        'key' => $key,
        'color' => $color,
        'target_per_week' => $perWeek,
    ], 'user');
    return (int) $row['id'];
}

function todo(Services $svc, int $userId, int $listId, string $title, string $due, int $priority): void
{
    $access = $svc->todos->resolveAccess($listId, $userId);
    if ($access === null) {
        throw new RuntimeException('Missing todo list ' . $listId);
    }
    $svc->todos->createItem($access, $userId, [
        'title' => $title,
        'due_date' => $due,
        'priority' => $priority,
    ]);
}
