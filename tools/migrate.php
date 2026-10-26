<?php
/**
 * migrate.php: numbered SQL migrations for PaCELab Equip, on SQLite or
 * PostgreSQL, run from the command line. The runner itself is
 * includes/db_migrations.php, shared with install.php and the tests.
 *
 * Usage:
 *   php tools/migrate.php --db <file.sqlite> [--create] [--dry] [--status]
 *   php tools/migrate.php --pg test [--dry] [--status]
 *        the test database (PACELAB_TEST_PGDATABASE, PGTESTDATABASE, pacelab_test)
 *   php tools/migrate.php --pg live [--status] | [--owner-approved]
 *        PGDATABASE; applying anything there needs --owner-approved
 *   php tools/migrate.php --dsn "pgsql:host=...;dbname=..." [--dry] [--status]
 *        any other PostgreSQL database
 *   ... --mark-applied 0001 --owner-approved
 *        record the baseline as applied without running it (a database whose
 *        schema already matches it; the owner says yes first)
 *   --dir <folder>   migrations folder (default: migrations beside tools)
 *
 * PostgreSQL credentials are the PG* names (PGUSER, PGPASSWORD and the rest)
 * from the environment, pg.env beside the data folder or .env, read by
 * includes/env.php; never on the command line. DB_USER and DB_PASSWORD, the
 * template's older names, still work for --dsn when PGUSER is unset.
 *
 * Exit codes: 0 done, 1 a migration failed or a checksum changed, 2 usage.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/env.php';
require APP_ROOT . '/includes/db_migrations.php';

$opts = getopt('', ['db:', 'dsn:', 'pg:', 'dir:', 'create', 'dry', 'status', 'mark-applied:', 'owner-approved']);
$dir = $opts['dir'] ?? mig_dir();
$say = fn (string $s) => fwrite(STDOUT, $s . PHP_EOL);
$fail = function (string $s, int $code = 1): never { fwrite(STDERR, $s . PHP_EOL); exit($code); };
$approved = isset($opts['owner-approved']);
$readOnly = isset($opts['status']) || isset($opts['dry']);

if (isset($opts['pg'])) {
    $which = (string) $opts['pg'];
    if (!in_array($which, ['test', 'live'], true)) {
        $fail('--pg takes test or live.', 2);
    }
    if (!lab_pg_credentials_present()) {
        $fail('No PostgreSQL credentials: set PGUSER and PGPASSWORD (pg.env beside the data folder, or .env).', 2);
    }
    $name = $which === 'test' ? lab_test_database() : (getenv('PGDATABASE') ?: 'pacelab');
    if ($which === 'test' && !str_ends_with($name, '_test')) {
        $fail("Refusing: the test database '$name' does not end in _test.", 2);
    }
    if ($which === 'live' && !$readOnly && !$approved) {
        $fail("Stop and ask: --pg live writes the schema of '$name'. Rerun with --owner-approved once the owner has said yes, or use --status.");
    }
    require APP_ROOT . '/includes/db.php';
    try {
        $pdo = db_pg_connect($name);
    } catch (PDOException $e) {
        $fail("Could not connect to '$name': " . db_pg_error_kind($e));
    }
    $driver = 'pgsql';
    $say("PostgreSQL database '$name' as role " . $pdo->query('SELECT current_user')->fetchColumn()); // db-calls: allow identity line on the tool's own connection
} elseif (isset($opts['dsn'])) {
    lab_load_env();
    $user = getenv('PGUSER') ?: (getenv('DB_USER') ?: null);
    $pass = getenv('PGUSER') ? (getenv('PGPASSWORD') ?: null) : (getenv('DB_PASSWORD') ?: null);
    $pdo = new PDO((string) $opts['dsn'], $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); // db-calls: allow the migration tool connects as the role it is given
    $driver = 'pgsql';
    $say('PostgreSQL as role ' . $pdo->query('SELECT current_user')->fetchColumn()); // db-calls: allow identity line on the tool's own connection
} elseif (isset($opts['db'])) {
    $file = (string) $opts['db'];
    if (!is_file($file) && !isset($opts['create'])) {
        $fail('No database at ' . $file . '. Refusing to create one (pass --create for a new database).', 2);
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); // db-calls: allow the migration tool opens the file it is given
    $pdo->exec('PRAGMA foreign_keys = ON'); // db-calls: allow SQLite connection setting; dialect-ok: SQLite branch of the migration tool
    $pdo->exec('PRAGMA busy_timeout = 15000'); // db-calls: allow SQLite connection setting; dialect-ok: SQLite branch of the migration tool
    $driver = 'sqlite';
} else {
    $fail('Usage: php tools/migrate.php --db <file> | --pg test|live | --dsn <pgsql dsn> [--create] [--dry] [--status] [--mark-applied NNNN --owner-approved]', 2);
}

try {
    if (isset($opts['mark-applied'])) {
        $v = (string) $opts['mark-applied'];
        if (!$approved) {
            $fail('Stop and ask: marking ' . $v . ' writes to the real database. Rerun with --owner-approved once the owner has said yes.');
        }
        $say('marked ' . mig_mark_applied($pdo, $driver, $v, $dir) . ' as applied without running it.');
        exit(0);
    }

    $st = mig_status($pdo, $driver, $dir);
    if ($st['problems']) {
        $fail('Refusing to run: ' . implode('; ', $st['problems']) . '.');
    }
    $say('declared ' . count($st['found']) . ', applied ' . (count($st['found']) - count($st['pending'])) . ', pending ' . count($st['pending']));
    if ($readOnly) {
        foreach ($st['pending'] as $v => $m) {
            $say('  pending ' . $v . '_' . $m['name'] . ' (' . basename($m['file']) . ')');
        }
        exit(0);
    }
    mig_apply($pdo, $driver, $dir, $say);
    $say('done.');
} catch (InvalidArgumentException $e) {
    $fail($e->getMessage(), 2);
} catch (Throwable $e) {
    $fail($e->getMessage());
}
