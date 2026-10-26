<?php
/**
 * roles.php: the owner's command for role grants (D-015). Command line only.
 *
 * Seeds and changes role_grants (migration 0003) for the NetID modes, and
 * re-keys laboratory members from typed last names to NetIDs. Nothing is
 * deleted: revoke stamps the row.
 *
 *   php tools/roles.php <target> seed
 *        the owner (dreslik, configure-me@example.edu) as superadmin: one
 *        code_editor grant and nothing else; run once on a new database (D-016)
 *   php tools/roles.php <target> list
 *   php tools/roles.php <target> grant <netid | group:NAME> <member|admin|code_editor> [--lab CODE] [--note "..."]
 *   php tools/roles.php <target> revoke <grant number>
 *   php tools/roles.php <target> members
 *        every lab_members key, and whether it reads as a NetID
 *   php tools/roles.php <target> rekey <LAB CODE> <old key> <netid>
 *        a member typed as a last name becomes their NetID
 *
 * <target> is one of:
 *   --pg test          the test database (PACELAB_TEST_PGDATABASE, PGTESTDATABASE, pacelab_test)
 *   --pg live          PGDATABASE; anything but list and members needs --owner-approved
 *   --db <file>        a SQLite file (a copy, or a SQLite installation); never created
 *
 * PostgreSQL credentials are the PG* names read by includes/env.php (the
 * environment, pg.env beside the data folder, .env); nothing prints a value.
 * On Radiant these writes need only the application login (<app>_app): they
 * insert and update rows, never the schema.
 *
 * Exit codes: 0 done, 1 refused or failed, 2 usage.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$args = array_slice($_SERVER['argv'], 1);
$say  = fn (string $s) => fwrite(STDOUT, $s . PHP_EOL);
$fail = function (string $s, int $code = 1): never { fwrite(STDERR, $s . PHP_EOL); exit($code); };

/** Take "--name value" out of the argument list. */
$take = function (string $name) use (&$args): ?string {
    $i = array_search($name, $args, true);
    if ($i === false) {
        return null;
    }
    $v = $args[$i + 1] ?? null;
    array_splice($args, $i, $v === null ? 1 : 2);
    return $v;
};
$flag = function (string $name) use (&$args): bool {
    $i = array_search($name, $args, true);
    if ($i === false) {
        return false;
    }
    array_splice($args, $i, 1);
    return true;
};

$approved = $flag('--owner-approved');
$pg       = $take('--pg');
$file     = $take('--db');
$labCode  = $take('--lab');
$note     = $take('--note') ?? '';
$cmd      = $args[0] ?? '';

if (($pg === null) === ($file === null) || $cmd === '') {
    $fail("Usage: php tools/roles.php (--pg test | --pg live | --db <file>) seed | list | grant | revoke | members | rekey\n"
        . "See the header of tools/roles.php.", 2);
}
$writes = in_array($cmd, ['seed', 'grant', 'revoke', 'rekey'], true);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/env.php';

if ($pg !== null) {
    if (!in_array($pg, ['test', 'live'], true)) {
        $fail('--pg takes test or live.', 2);
    }
    if (!lab_pg_credentials_present()) {
        $fail('No PostgreSQL credentials: set PGUSER and PGPASSWORD (pg.env beside the data folder, or .env).', 2);
    }
    $name = $pg === 'test' ? lab_test_database() : (getenv('PGDATABASE') ?: 'pacelab');
    if ($pg === 'test' && !str_ends_with($name, '_test')) {
        $fail("Refusing: the test database '$name' does not end in _test.", 2);
    }
    if ($pg === 'live' && $writes && !$approved) {
        $fail("Stop and ask: $cmd writes role grants in '$name'. Rerun with --owner-approved once the owner has said yes.");
    }
    define('LAB_FORCE_DRIVER', 'pgsql');
    putenv('PACELAB_DRIVER=pgsql');
    putenv('PGDATABASE=' . $name);
    define('LAB_DB_PATH', APP_ROOT . '/data/unused.sqlite');
    $where = "PostgreSQL database '$name'";
} else {
    if (!is_file($file)) {
        $fail('No SQLite file at ' . $file . '. Refusing to create one.', 2);
    }
    define('LAB_FORCE_DRIVER', 'sqlite');
    putenv('PACELAB_DRIVER=sqlite');
    define('LAB_DB_PATH', (string) realpath($file));
    $where = 'SQLite file ' . basename($file);
}

require APP_ROOT . '/includes/roles.php';

try {
    if ($pg !== null && db_value('SELECT current_database()') !== $name) {
        $fail("Refusing: connected to another database than '$name'.");
    }
    if (!roles_table_ready() && $cmd !== 'members' && $cmd !== 'rekey') {
        $fail("$where has no role_grants table. Apply migration 0003 first: php tools/migrate.php " . ($pg !== null ? "--pg $pg" : '--db <file>'));
    }
    $say($where);

    switch ($cmd) {
        case 'seed':
            [$id, $made] = role_seed_owner(ROLE_CONSOLE);
            $say(($made ? "Grant $id: " : "Already in force, grant $id: ") . ROLE_OWNER_NETID . ' (' . ROLE_OWNER_EMAIL . ') is '
                . ROLE_OWNER_ROLE . ', the superadmin. Assign every other role with grant.');
            break;

        case 'list':
            $rows = roles_active();
            if (!$rows) {
                $say('No grants in force.');
            }
            foreach ($rows as $g) {
                $say(sprintf('%5d  %-32s %-12s %-10s %s  by %s%s', $g['grant_id'], role_principal_text($g), $g['role'],
                    $g['lab_code'] ?? '-', $g['granted_at'], $g['granted_by'], $g['note'] !== '' ? '  (' . $g['note'] . ')' : ''));
            }
            break;

        case 'grant':
            if (!isset($args[1], $args[2])) {
                $fail('Usage: grant <netid | group:NAME> <member|admin|code_editor> [--lab CODE] [--note "..."]', 2);
            }
            [$kind, $principal] = role_parse_principal($args[1]);
            $labId = null;
            if ($labCode !== null) {
                $lab = db_one('SELECT lab_id FROM labs WHERE lower(code) = ?', [strtolower($labCode)]);
                if (!$lab) {
                    $fail('No laboratory with code ' . $labCode . '.');
                }
                $labId = (int) $lab['lab_id'];
            }
            $id = role_grant($kind, $principal, $args[2], $labId, $note, ROLE_CONSOLE);
            $say("Grant $id: " . ($kind === 'group' ? 'group:' : '') . "$principal is $args[2]" . ($labCode ? " in $labCode" : '') . '.');
            break;

        case 'revoke':
            if (!isset($args[1]) || !ctype_digit($args[1])) {
                $fail('Usage: revoke <grant number>', 2);
            }
            $row = role_revoke((int) $args[1], ROLE_CONSOLE);
            $say('Grant ' . $row['grant_id'] . ' revoked at ' . $row['revoked_at'] . '; the row stays.');
            break;

        case 'members':
            foreach (db_all('SELECT l.code, m.person_key, m.display_name FROM lab_members m JOIN labs l ON l.lab_id = m.lab_id ORDER BY l.code, m.person_key') as $m) {
                $say(sprintf('%-10s %-24s %-28s %s', $m['code'], $m['person_key'], $m['display_name'],
                    role_valid_netid($m['person_key']) ? '' : 'not a NetID'));
            }
            $say('A typed last name and a NetID can look alike; check each against the campus directory before rekey.');
            break;

        case 'rekey':
            if (!isset($args[1], $args[2], $args[3])) {
                $fail('Usage: rekey <LAB CODE> <old key> <netid>', 2);
            }
            $lab = db_one('SELECT lab_id FROM labs WHERE lower(code) = ?', [strtolower($args[1])]);
            $old = strtolower(trim($args[2]));
            $new = strtolower(trim($args[3]));
            if (!$lab) {
                $fail('No laboratory with code ' . $args[1] . '.');
            }
            if (!role_valid_netid($new)) {
                $fail($new . ' is not a NetID.');
            }
            if (db_one('SELECT 1 FROM lab_members WHERE lab_id = ? AND person_key = ?', [$lab['lab_id'], $new])) {
                $fail($new . ' is already a member of ' . $args[1] . '.');
            }
            $n = db_run('UPDATE lab_members SET person_key = ? WHERE lab_id = ? AND person_key = ?', [$new, $lab['lab_id'], $old])->rowCount();
            if ($n !== 1) {
                $fail('No member ' . $old . ' in ' . $args[1] . '; nothing changed.');
            }
            $say("$args[1]: $old is now $new. Bookings and charges keep the name they were made under.");
            break;

        default:
            $fail('Unknown command ' . $cmd . '. Use seed, list, grant, revoke, members or rekey.', 2);
    }
} catch (InvalidArgumentException $e) {
    $fail('Refused: ' . $e->getMessage());
} catch (Throwable $e) {
    $fail('Failed: ' . get_class($e) . ($e instanceof PDOException ? ' (' . db_pg_error_kind($e) . ')' : ': ' . $e->getMessage()));
}
exit(0);
