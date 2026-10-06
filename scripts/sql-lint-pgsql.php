#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/sql-lint-pgsql.php — static SQL compatibility check against PostgreSQL.
 *
 * Finds every SQL string passed to Database::{fetchAll,fetchOne,fetchScalar,execute,query}
 * in the PHP sources, converts `?` placeholders to `$n`, and asks PostgreSQL to
 * PREPARE it. PREPARE parses, resolves tables/columns and type-checks without
 * executing anything, so it catches SQLite-only syntax, unknown functions,
 * double-quoted string literals, GROUP BY strictness and type mismatches.
 *
 * Interpolated fragments ("{$x}", '...' . $x . '...') are replaced with NULL.
 * If that makes a statement unparseable it is reported as REVIEW (not FAIL),
 * because the real value is only known at runtime.
 *
 * Usage (needs a migrated pgsql database — use a scratch/test DB, nothing is written):
 *   php scripts/sql-lint-pgsql.php            # human report, exit 1 on failures
 *   php scripts/sql-lint-pgsql.php --json     # machine-readable
 *
 * Exit codes: 0 = clean (REVIEW items allowed), 1 = at least one FAIL, 2 = setup error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\DB\SqlStatementExtractor;

$json = in_array('--json', $argv ?? [], true);

$db = Database::getInstance();
if (!$db->isPgsql()) {
    fwrite(STDERR, "DB_DRIVER must be pgsql for this check.\n");
    exit(2);
}

$roots = [ROOT_DIR . '/src', ROOT_DIR . '/cron', ROOT_DIR . '/scripts', ROOT_DIR . '/public', ROOT_DIR . '/templates'];
$statements = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = substr($file->getPathname(), strlen(ROOT_DIR) + 1);
        foreach (SqlStatementExtractor::extract((string) file_get_contents($file->getPathname())) as $stmt) {
            $stmt['file'] = $rel;
            $statements[] = $stmt;
        }
    }
}

$pdo     = $db->pdo();
$results = ['ok' => 0, 'review' => [], 'fail' => []];
$n       = 0;

foreach ($statements as $stmt) {
    $sql = SqlStatementExtractor::toPgPlaceholders($stmt['sql']);
    $name = 'lint_' . (++$n);

    try {
        $pdo->exec("PREPARE {$name} AS {$sql}");
        $pdo->exec("DEALLOCATE {$name}");
        $results['ok']++;
    } catch (PDOException $e) {
        $entry = [
            'file'    => $stmt['file'],
            'line'    => $stmt['line'],
            'dynamic' => $stmt['dynamic'],
            'error'   => trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? ''),
            'sql'     => trim(preg_replace('/\s+/', ' ', $stmt['sql']) ?? ''),
        ];
        if ($stmt['dynamic']) {
            $results['review'][] = $entry;
        } else {
            $results['fail'][] = $entry;
        }
    }
}

if ($json) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo 'Statements checked: ' . count($statements) . "\n";
    echo "  OK:     {$results['ok']}\n";
    echo '  REVIEW: ' . count($results['review']) . " (dynamic SQL — verify by hand)\n";
    echo '  FAIL:   ' . count($results['fail']) . "\n";
    foreach (['fail' => 'FAIL', 'review' => 'REVIEW'] as $key => $label) {
        foreach ($results[$key] as $r) {
            echo "\n[{$label}] {$r['file']}:{$r['line']}\n  {$r['error']}\n  SQL: " . mb_strimwidth($r['sql'], 0, 220, '…') . "\n";
        }
    }
}

exit($results['fail'] === [] ? 0 : 1);
