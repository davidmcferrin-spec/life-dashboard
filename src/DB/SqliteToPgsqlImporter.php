<?php

declare(strict_types=1);

namespace Dashboard\DB;

use PDO;

/**
 * SqliteToPgsqlImporter — one-shot copy of a personal-dashboard SQLite database
 * into a migrated life-dashboard PostgreSQL database.
 *
 * Safety model:
 *   1. Preflight (read-only): column mapping, value conversion, VARCHAR length,
 *      and foreign-key orphan checks for every row. Nothing is written if
 *      preflight reports errors.
 *   2. Import: one transaction — TRUNCATE target tables, insert in FK order,
 *      reset identity sequences, verify row counts. Any failure rolls back.
 *
 * The target's schema is read from the PostgreSQL catalog, so tables added in
 * later migrations are handled without code changes (they are simply empty in
 * the source).
 */
final class SqliteToPgsqlImporter
{
    /** Tables never copied (the target keeps its own migration history). */
    public const SKIP_TABLES = ['migrations'];

    /** @var array<string, array<string, array{type: string, max_length: ?int, nullable: bool}>> */
    private array $targetColumns = [];

    /** @var array<string, list<array{column: string, ref_table: string, ref_column: string}>> */
    private array $foreignKeys = [];

    /** @var list<string> */
    private array $log = [];

    /**
     * @param callable(string): void|null $logger
     */
    public function __construct(
        private readonly PDO $source,
        private readonly PDO $target,
        private readonly bool $skipOrphans = false,
        private readonly bool $truncateLongText = false,
        private $logger = null,
    ) {
        $this->source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->target->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Read and validate everything without writing.
     *
     * @return array{
     *   order: list<string>,
     *   rows: array<string, list<array<string, mixed>>>,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   skipped: array<string, int>
     * }
     */
    public function preflight(): array
    {
        $this->loadTargetCatalog();

        $order    = self::topologicalOrder(array_keys($this->targetColumns), $this->foreignKeys);
        $source   = $this->sourceTables();
        $rows     = [];
        $errors   = [];
        $warnings = [];
        $skipped  = [];
        /** @var array<string, array<string, true>> $keys imported primary/unique key values per "table.column" */
        $keys = [];

        foreach ($order as $table) {
            $rows[$table] = [];
            if (!in_array($table, $source, true)) {
                $this->say("  · {$table}: not in source (new table) — left empty");
                continue;
            }

            $srcCols  = $this->sourceColumns($table);
            $tgtCols  = $this->targetColumns[$table];
            $common   = array_values(array_intersect(array_keys($tgtCols), $srcCols));
            $dropped  = array_values(array_diff($srcCols, array_keys($tgtCols)));
            if ($dropped !== []) {
                $warnings[] = "{$table}: source columns not in target, ignored: " . implode(', ', $dropped);
            }

            $colList = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $common));
            $stmt    = $this->source->query("SELECT {$colList} FROM \"{$table}\"");

            $rowNum = 0;
            while (($raw = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $rowNum++;
                $row = [];
                $rowErrors = [];

                foreach ($common as $col) {
                    try {
                        $row[$col] = self::convertValue($raw[$col], $tgtCols[$col], $this->truncateLongText);
                    } catch (\InvalidArgumentException $e) {
                        $rowErrors[] = "{$table} row {$rowNum} (id=" . ($raw['id'] ?? '?') . ") column {$col}: " . $e->getMessage();
                    }
                }

                // Target-only columns are omitted from the INSERT and take their DEFAULT.
                foreach ($common as $col) {
                    $isInt = in_array($tgtCols[$col]['type'], ['smallint', 'integer', 'bigint'], true);
                    if (!$tgtCols[$col]['nullable'] && $isInt && array_key_exists($col, $row) && $row[$col] === null && $raw[$col] === '') {
                        // PHP false stored as '' by the old PDO bindings — it meant 0.
                        $row[$col] = 0;
                        continue;
                    }
                    if (!$tgtCols[$col]['nullable'] && array_key_exists($col, $row) && $row[$col] === null) {
                        $rowErrors[] = "{$table} row {$rowNum} (id=" . ($raw['id'] ?? '?') . ") column {$col}: NULL in NOT NULL column";
                    }
                }

                // Foreign-key orphans (parents are imported first thanks to topological order).
                $orphan = null;
                foreach ($this->foreignKeys[$table] ?? [] as $fk) {
                    $value = $row[$fk['column']] ?? null;
                    if ($value === null) {
                        continue;
                    }
                    $refKey = $fk['ref_table'] . '.' . $fk['ref_column'];
                    if (!isset($keys[$refKey][(string) $value])) {
                        $orphan = "{$table} row {$rowNum} (id=" . ($raw['id'] ?? '?') . "): {$fk['column']}={$value} has no {$fk['ref_table']}.{$fk['ref_column']}";
                        break;
                    }
                }

                if ($orphan !== null) {
                    if ($this->skipOrphans) {
                        $skipped[$table] = ($skipped[$table] ?? 0) + 1;
                        $warnings[] = 'skipped orphan — ' . $orphan;
                        continue;
                    }
                    $rowErrors[] = $orphan . ' (use --skip-orphans to drop such rows)';
                }

                if ($rowErrors !== []) {
                    array_push($errors, ...$rowErrors);
                    continue;
                }

                $rows[$table][] = $row;
                foreach ($row as $col => $value) {
                    if ($value !== null) {
                        $keys[$table . '.' . $col][(string) $value] = true;
                    }
                }
            }

            $this->say(sprintf('  · %s: %d row(s) read', $table, count($rows[$table])));
        }

        foreach (array_diff($source, array_keys($this->targetColumns), self::SKIP_TABLES) as $legacy) {
            if (str_starts_with($legacy, 'sqlite_')) {
                continue;
            }
            $warnings[] = "source table {$legacy} has no target table — not imported";
        }

        return ['order' => $order, 'rows' => $rows, 'errors' => $errors, 'warnings' => $warnings, 'skipped' => $skipped];
    }

    /**
     * Write the preflighted rows. Throws (after rollback) on any failure.
     *
     * @param array{order: list<string>, rows: array<string, list<array<string, mixed>>>} $plan
     * @return array<string, int> rows imported per table
     */
    public function import(array $plan): array
    {
        $order  = $plan['order'];
        $counts = [];

        $this->target->beginTransaction();
        try {
            $quoted = implode(', ', array_map(static fn (string $t): string => '"' . $t . '"', $order));
            $this->target->exec("TRUNCATE {$quoted} RESTART IDENTITY CASCADE");

            foreach ($order as $table) {
                $rows = $plan['rows'][$table] ?? [];
                $counts[$table] = 0;
                if ($rows === []) {
                    continue;
                }

                $cols   = array_keys($rows[0]);
                $colSql = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', $cols));
                $ph     = implode(', ', array_fill(0, count($cols), '?'));
                $stmt   = $this->target->prepare("INSERT INTO \"{$table}\" ({$colSql}) OVERRIDING SYSTEM VALUE VALUES ({$ph})");

                foreach ($rows as $row) {
                    $stmt->execute(array_values($row));
                    $counts[$table]++;
                }

                $this->resetIdentity($table);
                $this->say(sprintf('  ✓ %s: %d row(s)', $table, $counts[$table]));
            }

            // Verify inside the transaction so a mismatch rolls everything back.
            foreach ($order as $table) {
                $actual = (int) $this->target->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
                if ($actual !== $counts[$table]) {
                    throw new \RuntimeException("Row count mismatch in {$table}: expected {$counts[$table]}, found {$actual}");
                }
            }

            $this->target->commit();
        } catch (\Throwable $e) {
            if ($this->target->inTransaction()) {
                $this->target->rollBack();
            }
            throw $e;
        }

        return $counts;
    }

    /** @return list<string> */
    public function logLines(): array
    {
        return $this->log;
    }

    // ---------------------------------------------------------------------
    // Pure helpers (unit-tested)
    // ---------------------------------------------------------------------

    /**
     * Convert a SQLite value for a PostgreSQL column.
     *
     * @param array{type: string, max_length: ?int, nullable: bool} $meta
     */
    public static function convertValue(mixed $value, array $meta, bool $truncateLongText = false): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $meta['type'];

        if (in_array($type, ['smallint', 'integer', 'bigint'], true)) {
            if (is_int($value)) {
                return $value;
            }
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            $s = trim((string) $value);
            if ($s === '') {
                return null; // SQLite let '' into integer columns (PHP false via PDO)
            }
            if (is_float($value) && floor($value) === $value) {
                return (int) $value;
            }
            if (!preg_match('/^-?\d+$/', $s)) {
                throw new \InvalidArgumentException("not an integer: " . self::preview($s));
            }
            return (int) $s;
        }

        if ($type === 'double precision' || $type === 'real' || $type === 'numeric') {
            $s = trim((string) $value);
            if ($s === '') {
                return null;
            }
            if (!is_numeric($s)) {
                throw new \InvalidArgumentException("not numeric: " . self::preview($s));
            }
            return $s;
        }

        if (str_starts_with($type, 'timestamp') || $type === 'date') {
            $s = trim((string) $value);
            if ($s === '') {
                return null;
            }
            if (is_int($value) || preg_match('/^\d{9,11}$/', $s)) {
                // Unix epoch seconds → UTC wall clock.
                $s = gmdate('Y-m-d H:i:s', (int) $s);
            }
            try {
                $dt = new \DateTimeImmutable($s, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                throw new \InvalidArgumentException("not a date/time: " . self::preview($s));
            }
            if ($type === 'date') {
                return $dt->format('Y-m-d');
            }
            // Offsets such as "+00:00"/"Z" are normalised to UTC wall clock.
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        // Text types
        $s = is_string($value) ? $value : (string) $value;
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        $s = str_replace("\0", '', $s); // PostgreSQL text cannot hold NUL bytes

        $max = $meta['max_length'];
        if ($max !== null && mb_strlen($s) > $max) {
            if (!$truncateLongText) {
                throw new \InvalidArgumentException(sprintf('length %d exceeds VARCHAR(%d) (use --truncate-long)', mb_strlen($s), $max));
            }
            $s = mb_substr($s, 0, $max);
        }

        return $s;
    }

    /**
     * Parents before children. Tables in a cycle (none today) are appended in name order.
     *
     * @param list<string> $tables
     * @param array<string, list<array{column: string, ref_table: string, ref_column: string}>> $fks
     * @return list<string>
     */
    public static function topologicalOrder(array $tables, array $fks): array
    {
        sort($tables, SORT_STRING);
        $deps = [];
        foreach ($tables as $t) {
            $deps[$t] = [];
            foreach ($fks[$t] ?? [] as $fk) {
                if ($fk['ref_table'] !== $t && in_array($fk['ref_table'], $tables, true)) {
                    $deps[$t][$fk['ref_table']] = true;
                }
            }
        }

        $order = [];
        while ($deps !== []) {
            $ready = array_keys(array_filter($deps, static fn (array $d): bool => $d === []));
            if ($ready === []) {
                $rest = array_keys($deps);
                sort($rest, SORT_STRING);
                return array_merge($order, $rest);
            }
            sort($ready, SORT_STRING);
            foreach ($ready as $t) {
                $order[] = $t;
                unset($deps[$t]);
                foreach ($deps as &$d) {
                    unset($d[$t]);
                }
                unset($d);
            }
        }

        return $order;
    }

    // ---------------------------------------------------------------------

    private function loadTargetCatalog(): void
    {
        $this->targetColumns = [];
        $this->foreignKeys   = [];

        $cols = $this->target->query(
            "SELECT c.table_name, c.column_name, c.data_type, c.character_maximum_length, c.is_nullable
             FROM information_schema.columns c
             JOIN information_schema.tables t
               ON t.table_schema = c.table_schema AND t.table_name = c.table_name
             WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE'
             ORDER BY c.table_name, c.ordinal_position"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($cols as $c) {
            if (in_array($c['table_name'], self::SKIP_TABLES, true)) {
                continue;
            }
            $this->targetColumns[$c['table_name']][$c['column_name']] = [
                'type'       => str_starts_with($c['data_type'], 'timestamp') ? 'timestamp' : $c['data_type'],
                'max_length' => $c['character_maximum_length'] !== null ? (int) $c['character_maximum_length'] : null,
                'nullable'   => $c['is_nullable'] === 'YES',
            ];
        }

        $fks = $this->target->query(
            "SELECT cl.relname AS table_name, a.attname AS column_name,
                    rcl.relname AS ref_table, ra.attname AS ref_column
             FROM pg_constraint con
             JOIN pg_class cl  ON cl.oid = con.conrelid
             JOIN pg_class rcl ON rcl.oid = con.confrelid
             JOIN pg_namespace n ON n.oid = cl.relnamespace
             JOIN LATERAL unnest(con.conkey)  WITH ORDINALITY AS k(attnum, ord)  ON true
             JOIN LATERAL unnest(con.confkey) WITH ORDINALITY AS rk(attnum, ord) ON rk.ord = k.ord
             JOIN pg_attribute a  ON a.attrelid = con.conrelid  AND a.attnum = k.attnum
             JOIN pg_attribute ra ON ra.attrelid = con.confrelid AND ra.attnum = rk.attnum
             WHERE con.contype = 'f' AND n.nspname = current_schema()"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($fks as $fk) {
            $this->foreignKeys[$fk['table_name']][] = [
                'column'     => $fk['column_name'],
                'ref_table'  => $fk['ref_table'],
                'ref_column' => $fk['ref_column'],
            ];
        }
    }

    /** @return list<string> */
    private function sourceTables(): array
    {
        return array_map(
            'strval',
            // sql-lint:ignore — SQLite source catalog
            $this->source->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /** @return list<string> */
    private function sourceColumns(string $table): array
    {
        $cols = [];
        // sql-lint:ignore — SQLite source catalog
        foreach ($this->source->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[] = (string) $c['name'];
        }
        return $cols;
    }

    private function resetIdentity(string $table): void
    {
        $seq = $this->target->query(
            "SELECT pg_get_serial_sequence('\"{$table}\"', 'id')"
        )->fetchColumn();

        if (!is_string($seq) || $seq === '') {
            return; // e.g. alarm_sounds uses a text primary key
        }

        $this->target->exec(
            "SELECT setval('{$seq}', COALESCE((SELECT MAX(id) FROM \"{$table}\"), 0) + 1, false)"
        );
    }

    private function say(string $line): void
    {
        $this->log[] = $line;
        if ($this->logger !== null) {
            ($this->logger)($line);
        }
    }

    private static function preview(string $s): string
    {
        return '"' . mb_strimwidth($s, 0, 40, '…') . '"';
    }
}
