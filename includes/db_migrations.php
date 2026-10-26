<?php
/**
 * db_migrations.php: the numbered-migration runner, as a library.
 *
 * One runner for three callers: tools/migrate.php (the command line),
 * install.php (a new PostgreSQL installation) and tests/run-tests.php (the
 * PostgreSQL test database is built from the migrations on every run, so the
 * suite exercises exactly what a new installation gets).
 *
 * Source: drez-db-access PHP track (templates\php\migrate.php). Rules from
 * drez-append-only-migrations: never edit an applied migration (a SHA-256 per
 * file proves it), record failures, one transaction per migration with its
 * registry row, stop at the first failure.
 *
 * Files, in <app>\migrations\:
 *   0001_baseline.sql            runs on both engines
 *   0002_add_x.sqlite.sql        runs on SQLite only, beside
 *   0002_add_x.pgsql.sql         its PostgreSQL twin (same number and name)
 *
 * Registry: schema_migrations(version, name, checksum, applied_at, status, error).
 * Only status 'ok' counts as applied; a failed row is retried next run.
 *
 * Every function takes the PDO handle it works on, because the callers hold
 * connections db() does not: the owner role, the test database, a scratch
 * file. That is why the PDO calls below carry db-calls: allow.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/** The default migrations folder. */
function mig_dir(): string
{
    return APP_ROOT . DIRECTORY_SEPARATOR . 'migrations';
}

/**
 * The migrations for one engine: version => ['name' => ..., 'file' => ...],
 * in order. Throws on two files claiming one number or a version with only
 * the other engine's twin.
 */
function mig_found(string $dir, string $driver): array
{
    if (!is_dir($dir)) {
        throw new RuntimeException('No migrations folder at ' . $dir);
    }
    $found = [];
    foreach (scandir($dir) as $f) {
        if (!preg_match('/^(\d{4})_([a-z0-9_]+?)(?:\.(sqlite|pgsql))?\.sql$/', $f, $m)) {
            continue;
        }
        [, $v, $name] = $m;
        $engine = $m[3] ?? '';
        if ($engine !== '' && $engine !== $driver) {
            $found[$v]['twin'] = true;
            continue;
        }
        if (isset($found[$v]['file'])) {
            throw new RuntimeException('Two migrations claim number ' . $v . ': ' . basename($found[$v]['file']) . ' and ' . $f);
        }
        $found[$v] = ($found[$v] ?? []) + ['name' => $name, 'file' => $dir . DIRECTORY_SEPARATOR . $f];
    }
    ksort($found, SORT_STRING);
    foreach ($found as $v => $m) {
        if (!isset($m['file'])) {
            throw new RuntimeException('Migration ' . $v . ' has no file for ' . $driver . ' (only its twin for the other engine).');
        }
    }
    return $found;
}

/** SHA-256 of a migration file with line endings normalised. */
function mig_checksum(string $file): string
{
    return hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($file)));
}

/** Create the registry when missing. Called only when something is about to be recorded. */
function mig_ensure_registry(PDO $pdo): void
{
    // db-calls: allow the migration registry, a literal statement on the caller's connection
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations ( -- db-calls: allow the migration registry
        version    VARCHAR(16) PRIMARY KEY,
        name       VARCHAR(255) NOT NULL,
        checksum   VARCHAR(64) NOT NULL,
        applied_at VARCHAR(32) NOT NULL,
        status     VARCHAR(8) NOT NULL,
        error      TEXT
    )');
}

/** The registry rows by version; empty when there is no registry yet. */
function mig_applied(PDO $pdo): array
{
    $applied = [];
    try {
        foreach ($pdo->query('SELECT version, name, checksum, status FROM schema_migrations') as $r) { // db-calls: allow the migration registry, a literal statement on the caller's connection
            $applied[$r['version']] = $r;
        }
    } catch (PDOException $e) {
        $applied = [];                  // no registry yet: nothing applied
        if ($pdo->inTransaction()) {    // db-calls: allow PostgreSQL aborts the transaction on a failed probe
            $pdo->rollBack();           // db-calls: allow PostgreSQL aborts the transaction on a failed probe
        }
    }
    return $applied;
}

/**
 * Where a database stands: declared, applied, pending, and every frozen-file
 * problem (an applied migration whose file changed or vanished).
 */
function mig_status(PDO $pdo, string $driver, ?string $dir = null): array
{
    $found = mig_found($dir ?? mig_dir(), $driver);
    $applied = mig_applied($pdo);
    $problems = [];
    foreach ($applied as $v => $r) {
        if ($r['status'] !== 'ok') {
            continue;
        }
        if (!isset($found[$v])) {
            $problems[] = 'migration ' . $v . ' (' . $r['name'] . ') was applied but its file is gone';
        } elseif (mig_checksum($found[$v]['file']) !== $r['checksum']) {
            $problems[] = 'migration ' . $v . ' changed after it was applied; put the change in a new migration';
        }
    }
    $pending = array_filter($found, fn ($v) => !isset($applied[$v]) || $applied[$v]['status'] !== 'ok', ARRAY_FILTER_USE_KEY);
    return ['found' => $found, 'applied' => $applied, 'pending' => $pending, 'problems' => $problems];
}

/** Write one registry row, replacing any earlier row for that version. */
function mig_record(PDO $pdo, string $v, string $name, string $checksum, string $status, ?string $error): void
{
    $pdo->prepare('DELETE FROM schema_migrations WHERE version = ?')->execute([$v]); // db-calls: allow the migration registry, bound parameters
    $pdo->prepare('INSERT INTO schema_migrations (version, name, checksum, applied_at, status, error) VALUES (?, ?, ?, ?, ?, ?)') // db-calls: allow the migration registry, bound parameters
        ->execute([$v, $name, $checksum, gmdate('Y-m-d\TH:i:s\Z'), $status, $error]);
}

/**
 * Apply every pending migration in order, each in its own transaction with
 * its registry row. Stops at the first failure, records it, and throws.
 * Refuses to start when an applied file changed. Returns the versions applied.
 */
function mig_apply(PDO $pdo, string $driver, ?string $dir = null, ?callable $say = null): array
{
    $say ??= static function (string $s): void {};
    $st = mig_status($pdo, $driver, $dir);
    if ($st['problems']) {
        throw new RuntimeException('Refusing to run: ' . implode('; ', $st['problems']) . '.');
    }
    if ($st['pending']) {
        mig_ensure_registry($pdo);
    }
    $done = [];
    foreach ($st['pending'] as $v => $m) {
        $sql = (string) file_get_contents($m['file']);
        $sum = mig_checksum($m['file']);
        try {
            $pdo->beginTransaction(); // db-calls: allow one transaction per migration with its registry row
            $pdo->exec($sql);         // db-calls: allow a committed migration file, run as written
            mig_record($pdo, $v, $m['name'], $sum, 'ok', null);
            $pdo->commit();           // db-calls: allow one transaction per migration with its registry row
            $say('applied ' . $v . '_' . $m['name']);
            $done[] = $v;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { // db-calls: allow roll back the failed migration before recording it
                $pdo->rollBack();         // db-calls: allow roll back the failed migration before recording it
            }
            mig_ensure_registry($pdo);
            mig_record($pdo, $v, $m['name'], $sum, 'failed', $e->getMessage());
            throw new RuntimeException('FAILED ' . $v . '_' . $m['name'] . ': ' . $e->getMessage() . ' (recorded; later migrations not run)', 0, $e);
        }
    }
    return $done;
}

/**
 * Record one migration as applied without running it (a database whose
 * schema already matches it, such as the live SQLite file and the 0001
 * baseline). Refuses when the registry already holds anything.
 */
function mig_mark_applied(PDO $pdo, string $driver, string $v, ?string $dir = null): string
{
    $st = mig_status($pdo, $driver, $dir);
    if (!isset($st['found'][$v])) {
        throw new InvalidArgumentException('No migration numbered ' . $v . '.');
    }
    if (count($st['applied']) > 0) {
        throw new RuntimeException('Refusing: schema_migrations already records ' . implode(', ', array_keys($st['applied'])) . '.');
    }
    mig_ensure_registry($pdo);
    $m = $st['found'][$v];
    mig_record($pdo, $v, $m['name'], mig_checksum($m['file']), 'ok', null);
    return $v . '_' . $m['name'];
}

/**
 * The tables in schema public of a PostgreSQL database, with their owners.
 * For the test harness and the tools that must refuse to touch a database
 * they do not own.
 */
function mig_pg_tables(PDO $pdo): array
{
    return $pdo->query("SELECT tablename, tableowner FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") // db-calls: allow catalog read on the caller's connection
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Empty a PostgreSQL TEST database completely: every table in schema public
 * dropped, so the migrations can build it again from nothing. Refuses unless
 * the connected database's name ends in _test and the connected role owns
 * every table it would drop.
 */
function mig_pg_reset_test_database(PDO $pdo): void
{
    $db = (string) $pdo->query('SELECT current_database()')->fetchColumn(); // db-calls: allow identity check before a destructive step
    if (!str_ends_with($db, '_test')) {
        throw new RuntimeException("Refusing to empty '$db': only a database whose name ends in _test may be reset.");
    }
    $me = (string) $pdo->query('SELECT current_user')->fetchColumn(); // db-calls: allow identity check before a destructive step
    $tables = mig_pg_tables($pdo);
    $foreign = array_keys(array_filter($tables, fn ($owner) => $owner !== $me));
    if ($foreign) {
        throw new RuntimeException("Refusing to empty '$db': $me does not own " . implode(', ', $foreign)
            . '. Hand the test database to the application role (see DEPLOY.md, PostgreSQL).');
    }
    foreach (array_keys($tables) as $t) {
        $pdo->exec('DROP TABLE IF EXISTS public."' . str_replace('"', '""', $t) . '" CASCADE'); // db-calls: allow test database reset; names come from pg_tables, quoted
    }
}
