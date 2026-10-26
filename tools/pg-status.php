<?php
/**
 * tools/pg-status.php: what the PostgreSQL side looks like, read only.
 *
 *   php tools/pg-status.php            both databases (PGDATABASE and the test database)
 *   php tools/pg-status.php --test     the test database only
 *   php tools/pg-status.php --quiet    no report; exit 0 when the test database answers
 *   php tools/pg-status.php --other-sessions
 *                                      prints one number: client sessions on the cluster
 *                                      other than this one (tools/estate-pg.ps1 asks before
 *                                      it stops the drive's cluster without Node, D-013)
 *
 * Reports which environment files exist and which PG* names each supplies
 * (never a value), the effective driver, and for each database: the role
 * connected, who owns the database and its tables, row counts and the
 * schema_migrations registry. Runs SELECT statements only, so it is safe
 * against the live PostgreSQL database.
 *
 * Exit codes: 0 every database asked for answered, 1 one did not, 3 no
 * PostgreSQL credentials from any source.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/db.php';

$quiet = in_array('--quiet', $argv, true);
$onlyTest = in_array('--test', $argv, true) || $quiet;
$say = function (string $s) use ($quiet): void {
    if (!$quiet) {
        echo $s, PHP_EOL;
    }
};

lab_load_env();

// The count tools/estate-pg.ps1 needs before it stops the drive's cluster
// without Node: client sessions other than this query's own, as
// estate-pg.js counts them. The application role is no superuser, so rows of
// other roles' sessions show no backend_type; a row with a database and no
// visible type counts as a session, which can only leave the cluster running
// (an autovacuum worker), never stop it under another application.
if (in_array('--other-sessions', $argv, true)) {
    if (!lab_pg_credentials_present()) {
        fwrite(STDERR, 'No PostgreSQL credentials (pg.env beside the data folder, or .env).' . PHP_EOL);
        exit(3);
    }
    try {
        $pg = db_pg_connect(getenv('PGDATABASE') ?: 'pacelab');
        $n = (int) $pg->query("SELECT count(*) FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND (backend_type = 'client backend' OR (backend_type IS NULL AND datname IS NOT NULL))")->fetchColumn();
    } catch (Throwable $e) {
        fwrite(STDERR, 'Could not count sessions: ' . db_pg_error_kind($e) . PHP_EOL);
        exit(1);
    }
    echo $n, PHP_EOL;
    exit(0);
}

$say('Driver for the application: ' . db_driver()
    . ' (PACELAB_DRIVER ' . (getenv('PACELAB_DRIVER') === false ? 'unset' : 'set')
    . ', config.local.json databaseDriver "' . (string) (lab_config()['databaseDriver'] ?? '') . '")');
$say('Environment files, in the order they are read:');
foreach (lab_env_sources() as $s) {
    $say('  ' . str_pad($s['source'], 32) . ($s['exists'] ? 'present, names: ' . (implode(' ', $s['names']) ?: 'none') : 'absent'));
}
if (!lab_pg_credentials_present()) {
    $say('No PostgreSQL credentials: PGUSER and PGPASSWORD are not set by any source.');
    exit(3);
}

$targets = $onlyTest ? ['test' => lab_test_database()] : ['live' => getenv('PGDATABASE') ?: 'pacelab', 'test' => lab_test_database()];
$bad = 0;
foreach ($targets as $label => $name) {
    $say('');
    $say("Database '$name' ($label)");
    try {
        $pg = db_pg_connect($name);
    } catch (PDOException $e) {
        $msg = "  could not connect to '$name': " . db_pg_error_kind($e);
        $quiet ? fwrite(STDERR, trim($msg) . PHP_EOL) : $say($msg);
        $bad++;
        continue;
    }
    if ($quiet) {
        continue;
    }
    $q = fn (string $sql, array $p = []) => (function () use ($pg, $sql, $p) { $st = $pg->prepare($sql); $st->execute($p); return $st; })(); // db-calls: allow read-only status probe on a second named database; SQL is literal
    $info = $q("SELECT current_user AS who, current_database() AS db, version() AS v,
                       pg_get_userbyid(d.datdba) AS dbowner,
                       (SELECT pg_get_userbyid(nspowner) FROM pg_namespace WHERE nspname = 'public') AS schemaowner
                FROM pg_database d WHERE d.datname = current_database()")->fetch();
    $say('  connected as ' . $info['who'] . '; database owner ' . $info['dbowner'] . '; schema public owner ' . $info['schemaowner']);
    $say('  ' . strtok((string) $info['v'], ','));
    $tables = $q("SELECT tablename, tableowner FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll();
    if (!$tables) {
        $say('  no tables in schema public');
        continue;
    }
    $owners = array_count_values(array_column($tables, 'tableowner'));
    $say('  ' . count($tables) . ' tables; owners: ' . implode(', ', array_map(fn ($o, $n) => "$o ($n)", array_keys($owners), $owners)));
    $counts = [];
    foreach ($tables as $t) {
        try {
            $counts[] = $t['tablename'] . '=' . $q('SELECT COUNT(*) FROM "' . str_replace('"', '""', $t['tablename']) . '"')->fetchColumn(); // db-calls: allow table names come from pg_tables, quoted
        } catch (PDOException $e) {
            $counts[] = $t['tablename'] . '=(no read permission)';
        }
    }
    $say('  rows: ' . implode(' ', $counts));
    if (in_array('schema_migrations', array_column($tables, 'tablename'), true)) {
        foreach ($q('SELECT version, name, status, applied_at FROM schema_migrations ORDER BY version')->fetchAll() as $r) {
            $say('  migration ' . $r['version'] . '_' . $r['name'] . ' ' . $r['status'] . ' ' . $r['applied_at']);
        }
    } else {
        $say('  no schema_migrations registry');
    }
}
exit($bad ? 1 : 0);
