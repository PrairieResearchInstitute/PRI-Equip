<?php
/**
 * tools/pg-load.php: copy a SQLite database into PostgreSQL, then prove the copy.
 *
 *   php tools/pg-load.php --source <lab.sqlite>                       into the test database
 *   php tools/pg-load.php --source <lab.sqlite> --target live --replace --owner-approved
 *                                                                     into PGDATABASE (switch-over)
 *   php tools/pg-load.php --source <lab.sqlite> --verify-only [--target live]
 *                                                                     compare only, write nothing
 *   --no-setval      advance sequences with nextval() even where setval() is allowed
 *   --keep-snapshot  keep the VACUUM INTO snapshot in the scratch folder
 *   --clean-after    test target only: drop every table again once verified, so
 *                    no copy of real rows stays in the test database (the gate uses it)
 *
 * What it does, in order:
 *   1. Opens the source read-only and takes a consistent snapshot of it with
 *      VACUUM INTO, in the scratch folder beside the data folder (or the
 *      system temp when none is configured). Everything after reads the
 *      snapshot, so a running application cannot change the rows mid-load and
 *      the source file is never opened for writing.
 *   2. Test target: drops and rebuilds the test database from the migrations,
 *      exactly as the test suite does. Live target: requires the schema to be
 *      there already, refuses when any table holds rows unless --replace, and
 *      refuses without --owner-approved.
 *   3. Copies every table in foreign-key order with the original ids, inside
 *      one transaction, converting an empty string in a date column to NULL
 *      (and saying how many it converted).
 *   4. Moves every identity sequence past the highest id: setval() when the
 *      role may, otherwise nextval() until it is past (the application role
 *      holds USAGE on the sequences, which is all nextval() needs).
 *   5. Verifies: row counts per table, then every row of every table field by
 *      field by primary key, then a SHA-256 checksum per table over a
 *      canonical form of the rows, computed the same way on both sides, and
 *      that each sequence's next value is above the highest id. Any
 *      difference ends with FAILED and exit code 1.
 *
 * Credentials: the PG* names through includes/env.php (pg.env beside the data
 * folder, .env, or the environment); nothing is printed but table names,
 * counts and checksums. Exit codes: 0 verified, 1 a difference or a failure,
 * 2 usage or refusal.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/db.php';
require APP_ROOT . '/includes/db_migrations.php';

$opts = getopt('', ['source:', 'target:', 'replace', 'owner-approved', 'verify-only', 'keep-snapshot', 'no-setval', 'clean-after']);
$say = fn (string $s) => fwrite(STDOUT, $s . PHP_EOL);
$fail = function (string $s, int $code = 1): never { fwrite(STDERR, $s . PHP_EOL); exit($code); };

/* Foreign-key order: every table after the tables it points at. */
const LOAD_TABLES = [
    'units', 'labs', 'grants', 'equipment', 'lab_members', 'equipment_costs',
    'export_batches', 'usage_records', 'reservations', 'email_log',
    'admin_users', 'login_attempts', 'picklists', 'settings',
];
/* Primary keys; settings is keyed by text and has no sequence. */
const LOAD_KEYS = [
    'units' => 'unit_id', 'labs' => 'lab_id', 'grants' => 'grant_id', 'equipment' => 'equipment_id',
    'lab_members' => 'member_id', 'equipment_costs' => 'cost_id', 'export_batches' => 'batch_id',
    'usage_records' => 'usage_id', 'reservations' => 'reservation_id', 'email_log' => 'email_id',
    'admin_users' => 'user_id', 'login_attempts' => 'attempt_id', 'picklists' => 'picklist_id',
    'settings' => 'key',
];

$source = (string) ($opts['source'] ?? '');
$target = (string) ($opts['target'] ?? 'test');
$verifyOnly = isset($opts['verify-only']);
if ($source === '' || !in_array($target, ['test', 'live'], true)) {
    $fail('Usage: php tools/pg-load.php --source <lab.sqlite> [--target test|live] [--replace] [--owner-approved] [--verify-only]', 2);
}
if (!is_file($source)) {
    $fail('No SQLite database at ' . $source . '.', 2);
}
if (!lab_pg_credentials_present()) {
    $fail('No PostgreSQL credentials: set PGUSER and PGPASSWORD (pg.env beside the data folder, or .env).', 2);
}
$dbName = $target === 'test' ? lab_test_database() : (getenv('PGDATABASE') ?: 'pacelab');
if ($target === 'test' && !str_ends_with($dbName, '_test')) {
    $fail("Refusing: the test database '$dbName' does not end in _test.", 2);
}
if ($target === 'live' && !$verifyOnly && !isset($opts['owner-approved'])) {
    $fail("Stop and ask: loading '$dbName' replaces what the application will read after the switch. Rerun with --owner-approved once the owner has said yes.", 2);
}

// ------------------------------------------------------------------ 1. snapshot
$scratch = lab_temp_dir() ?? sys_get_temp_dir();
if (!is_dir($scratch) && !@mkdir($scratch, 0770) && !is_dir($scratch)) {
    $fail('No scratch folder at ' . $scratch . '.');
}
$snapshot = $scratch . DIRECTORY_SEPARATOR . 'pg-load-' . getmypid() . '.sqlite';
@unlink($snapshot);
$src = new PDO('sqlite:' . $source, null, null, [ // db-calls: allow the loader holds the source and the target at once; the source opens read-only
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
]);
$src->exec('PRAGMA busy_timeout = 10000'); // db-calls: allow source connection setting; dialect-ok: SQLite source of the one-time load
$src->exec('VACUUM INTO ' . $src->quote($snapshot)); // db-calls: allow consistent snapshot of a read-only source; dialect-ok: SQLite source of the one-time load
$src = null;
$sq = new PDO('sqlite:' . $snapshot, null, null, [ // db-calls: allow the loader reads its own snapshot
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$sq->exec('PRAGMA query_only = 1'); // db-calls: allow the snapshot is read, never written; dialect-ok: SQLite source of the one-time load
$integrity = (string) $sq->query('PRAGMA integrity_check')->fetchColumn(); // db-calls: allow snapshot check; dialect-ok: SQLite source of the one-time load
if ($integrity !== 'ok') {
    $fail('The snapshot fails integrity_check: ' . $integrity);
}
$srcTables = $sq->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations'")->fetchAll(PDO::FETCH_COLUMN); // db-calls: allow catalog read of the snapshot; dialect-ok: SQLite source of the one-time load
$unknown = array_diff($srcTables, LOAD_TABLES);
if ($unknown) {
    $fail('The source holds tables this loader does not know: ' . implode(', ', $unknown) . '. Add them to LOAD_TABLES in foreign-key order.');
}
$say('Source snapshot: ' . basename($snapshot) . ' (integrity ok, ' . count($srcTables) . ' tables)');

// ------------------------------------------------------------------ 2. target
try {
    $pg = db_pg_connect($dbName);
} catch (PDOException $e) {
    $fail("Could not connect to '$dbName': " . db_pg_error_kind($e));
}
$reached = (string) $pg->query('SELECT current_database()')->fetchColumn(); // db-calls: allow identity check on the loader's target
if ($reached !== $dbName) {
    $fail("Refusing: connected to '$reached', not '$dbName'.", 2);
}
$say("Target: PostgreSQL database '$dbName' ($target) as role " . $pg->query('SELECT current_user')->fetchColumn()); // db-calls: allow identity line on the loader's target

$q = function (string $sql, array $p = []) use ($pg): PDOStatement {
    $st = $pg->prepare($sql); // db-calls: allow the loader's own connection; table and column names come from LOAD_TABLES and the catalog, quoted
    $st->execute($p);
    return $st;
};
$quote = fn (string $n) => '"' . str_replace('"', '""', $n) . '"';

if (!$verifyOnly) {
    if ($target === 'test') {
        mig_pg_reset_test_database($pg);
        mig_apply($pg, 'pgsql', null, fn ($s) => $say('  ' . $s));
    } else {
        $have = array_keys(mig_pg_tables($pg));
        $missing = array_diff(LOAD_TABLES, $have);
        if ($missing) {
            $fail("Refusing: '$dbName' lacks tables " . implode(', ', $missing) . '. Build the schema first: php tools/migrate.php --pg live --owner-approved', 2);
        }
        $occupied = [];
        foreach (LOAD_TABLES as $t) {
            $n = (int) $q('SELECT COUNT(*) FROM ' . $quote($t))->fetchColumn();
            if ($n > 0) {
                $occupied[] = "$t=$n";
            }
        }
        if ($occupied && !isset($opts['replace'])) {
            $fail("Refusing: '$dbName' already holds rows (" . implode(' ', $occupied) . '). Pass --replace to delete them and load the source in their place.', 2);
        }
    }
}

// ------------------------------------------------------------------ 3. copy
$dateCol = fn (string $c) => (bool) preg_match('/_date$|^covers_|^period_/', $c);
if (!$verifyOnly) {
    $say('Loading ' . count(LOAD_TABLES) . ' tables');
    $pg->beginTransaction(); // db-calls: allow the whole load is one transaction: all of it or none
    try {
        // DELETE rather than TRUNCATE: TRUNCATE is its own privilege and the
        // application role does not hold it on a database the owner built.
        foreach (array_reverse(LOAD_TABLES) as $t) {
            $q('DELETE FROM ' . $quote($t));
        }
        foreach (LOAD_TABLES as $t) {
            if (!in_array($t, $srcTables, true)) {
                $say(sprintf('  %-16s %5s', $t, 'absent in the source'));
                continue;
            }
            $rows = $sq->query('SELECT * FROM ' . $quote($t) . ' ORDER BY ' . $quote(LOAD_KEYS[$t]))->fetchAll(); // db-calls: allow table names from LOAD_TABLES, quoted
            if (!$rows) {
                $say(sprintf('  %-16s %5d', $t, 0));
                continue;
            }
            $cols = array_keys($rows[0]);
            $ins = $pg->prepare('INSERT INTO ' . $quote($t) . ' (' . implode(', ', array_map($quote, $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'); // db-calls: allow column names from the source row, quoted; values bound
            $converted = 0;
            foreach ($rows as $r) {
                $vals = [];
                foreach ($cols as $c) {
                    $v = $r[$c];
                    if ($v === '' && $dateCol($c)) {
                        $v = null;
                        $converted++;
                    }
                    $vals[] = $v;
                }
                $ins->execute($vals);
            }
            $say(sprintf('  %-16s %5d', $t, count($rows)) . ($converted ? "  ($converted empty date value(s) stored as NULL)" : ''));
        }
        $pg->commit(); // db-calls: allow the whole load is one transaction
    } catch (Throwable $e) {
        if ($pg->inTransaction()) { // db-calls: allow roll back a failed load
            $pg->rollBack();         // db-calls: allow roll back a failed load
        }
        $fail('FAILED during the load, nothing kept: ' . $e->getMessage());
    }

    // -------------------------------------------------------------- 4. sequences
    $say('Sequences');
    foreach (LOAD_TABLES as $t) {
        $seq = $q('SELECT pg_get_serial_sequence(?, ?)', ['public.' . $quote($t), LOAD_KEYS[$t]])->fetchColumn();
        if (!$seq) {
            continue;                                   // settings: text key
        }
        $max = (int) $q('SELECT COALESCE(MAX(' . $quote(LOAD_KEYS[$t]) . '), 0) FROM ' . $quote($t))->fetchColumn();
        if ($max === 0) {
            continue;
        }
        // --no-setval forces the nextval() path, which the tests use to prove
        // the route a database owned by another role needs.
        if (!isset($opts['no-setval']) && $q("SELECT has_sequence_privilege(?, 'UPDATE')", [$seq])->fetchColumn()) {
            $q('SELECT setval(?::regclass, ?, true)', [$seq, $max]);
            $how = 'setval';
        } else {
            $s = $q('SELECT last_value, is_called FROM ' . $seq)->fetch(); // db-calls: allow the sequence name comes from pg_get_serial_sequence, already quoted
            $next = (int) $s['last_value'] + ($s['is_called'] ? 1 : 0);
            $steps = max(0, $max + 1 - $next);
            if ($steps > 0) {
                $q('SELECT max(nextval(?::regclass)) FROM generate_series(1, ?)', [$seq, $steps]);
            }
            $how = 'nextval x' . $steps;
        }
        $say(sprintf('  %-16s past %d (%s)', $t, $max, $how));
    }
}

// ------------------------------------------------------------------ 5. verify
$say('Verifying');
/** One value in a form both engines agree on. */
$canon = function ($v): string {
    if ($v === null) {
        return "\0NULL";
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    if (is_int($v)) {
        return (string) $v;
    }
    if (is_float($v)) {
        return rtrim(rtrim(sprintf('%.15F', $v), '0'), '.');
    }
    $s = (string) $v;
    if (preg_match('/^-?\d+\.\d+$/', $s)) {           // a REAL that came back as text
        return rtrim(rtrim(sprintf('%.15F', (float) $s), '0'), '.');
    }
    return $s;
};
$problems = 0;
$checked = 0;
foreach (LOAD_TABLES as $t) {
    if (!in_array($t, $srcTables, true)) {
        continue;
    }
    $key = LOAD_KEYS[$t];
    $a = $sq->query('SELECT * FROM ' . $quote($t) . ' ORDER BY ' . $quote($key))->fetchAll(); // db-calls: allow table names from LOAD_TABLES, quoted
    $b = $q('SELECT * FROM ' . $quote($t) . ' ORDER BY ' . $quote($key))->fetchAll();
    if (count($a) !== count($b)) {
        $say(sprintf('  %-16s COUNT MISMATCH sqlite=%d postgres=%d', $t, count($a), count($b)));
        $problems++;
        continue;
    }
    $byKey = [];
    foreach ($b as $r) {
        $byKey[(string) $r[$key]] = $r;
    }
    $bad = [];
    $hashA = hash_init('sha256');
    $hashB = hash_init('sha256');
    foreach ($a as $r) {
        $k = (string) $r[$key];
        $o = $byKey[$k] ?? null;
        if ($o === null) {
            $bad[] = "$key=$k missing";
            continue;
        }
        $lineA = [];
        $lineB = [];
        foreach ($r as $c => $v) {
            if ($v === '' && $dateCol($c)) {
                $v = null;                              // the one conversion the load makes
            }
            $x = $canon($v);
            $y = array_key_exists($c, $o) ? $canon($o[$c]) : "\0MISSING";
            $lineA[] = $c . '=' . $x;
            $lineB[] = $c . '=' . $y;
            if ($x !== $y && count($bad) < 5) {
                $bad[] = "$key=$k column $c";
            }
        }
        hash_update($hashA, implode("\x1f", $lineA) . "\x1e");
        hash_update($hashB, implode("\x1f", $lineB) . "\x1e");
        $checked++;
    }
    $ha = hash_final($hashA);
    $hb = hash_final($hashB);
    if ($bad || $ha !== $hb) {
        $say(sprintf('  %-16s DIFFERS: %s', $t, $bad ? implode('; ', $bad) : 'checksums differ'));
        $problems++;
        continue;
    }
    $seqNote = '';
    $seq = $q('SELECT pg_get_serial_sequence(?, ?)', ['public.' . $quote($t), $key])->fetchColumn();
    if ($seq && $a) {
        $max = (int) $q('SELECT MAX(' . $quote($key) . ') FROM ' . $quote($t))->fetchColumn();
        $s = $q('SELECT last_value, is_called FROM ' . $seq)->fetch(); // db-calls: allow the sequence name comes from pg_get_serial_sequence, already quoted
        $next = (int) $s['last_value'] + ($s['is_called'] ? 1 : 0);
        if ($next <= $max) {
            $say(sprintf('  %-16s SEQUENCE BEHIND: next id %d, highest id %d', $t, $next, $max));
            $problems++;
            continue;
        }
        $seqNote = ', next id ' . $next;
    }
    $say(sprintf('  %-16s ok  %4d rows field for field, sha256 %s%s', $t, count($a), substr($ha, 0, 16), $seqNote));
}

$sq = null;
if (!isset($opts['keep-snapshot'])) {
    @unlink($snapshot);
}
$say('');
if ($problems === 0) {
    if (isset($opts['clean-after']) && $target === 'test') {
        mig_pg_reset_test_database($pg);
        $say("Emptied '$dbName' again (--clean-after).");
    }
    $say("VERIFIED: $checked rows across " . count($srcTables) . " tables match the SQLite source in '$dbName'.");
    exit(0);
}
$say("FAILED: $problems table(s) did not verify. The PostgreSQL copy in '$dbName' is not trustworthy.");
exit(1);
