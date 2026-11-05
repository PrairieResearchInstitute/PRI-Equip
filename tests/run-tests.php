<?php
/**
 * tests/run-tests.php — The rules this application must not break.
 *
 *     php tests/run-tests.php
 *
 * Builds a throwaway database in the temporary directory from the same schema
 * install.php uses, exercises the rules that cost money or leak data when they
 * go wrong, and prints a line per check. It never opens data/lab.sqlite, so it
 * is safe to run against a live installation.
 *
 * These are not tests of every screen. They are the things that were expensive
 * to get right and would fail silently: a charge keeping the rate it was made
 * at, two people not booking the same hour, and one laboratory never seeing
 * another's instruments, accounts, charges or messages.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line: php tests/run-tests.php\n");
}

// A database of its own, thrown away at the end. Defined before anything that
// would otherwise open data/lab.sqlite.
/*
 * This harness builds a throwaway SQLite file and points LAB_DB_PATH at it.
 * A Postgres connection ignores LAB_DB_PATH entirely, so if .env said pgsql
 * these tests would run against the real database and write to it. Force
 * sqlite for the duration rather than trust whatever .env happens to say.
 *
 * Exercising the Postgres path needs its own throwaway database; see
 * the migration notes (reference/Migration-README.md on the owner's copy;
 * git history keeps them as Migration/README.md) for why that is not this
 * file's job.
 */
$pgTarget = null;
if (getenv('PACELAB_TEST_DRIVER') === 'pgsql') {
    /*
     * Opt-in Postgres run. The database name must end in _test: these tests
     * create, update and delete rows and drop every table before they start,
     * and the whole reason the harness normally pins itself to sqlite is that
     * a Postgres connection ignores LAB_DB_PATH and would otherwise reach the
     * live database. The name comes from PACELAB_TEST_PGDATABASE, then
     * PGTESTDATABASE (pg.env), then pacelab_test; the credentials from the
     * PG* names through includes/env.php. After connecting, the harness asks
     * the server which database it reached and refuses again if the answer
     * is not this name.
     */
    require_once __DIR__ . '/../includes/env.php';
    $pgTarget = lab_test_database();
    if (!str_ends_with($pgTarget, '_test')) {
        fwrite(STDERR, "Refusing to run: the test database is '$pgTarget', which does not end in _test.\n");
        exit(2);
    }
    define('LAB_FORCE_DRIVER', 'pgsql');   // outranks PACELAB_DRIVER and databaseDriver wherever they are set
    putenv('PACELAB_DRIVER=pgsql');
    putenv('PGDATABASE=' . $pgTarget);
    fwrite(STDOUT, "Running against Postgres database '$pgTarget'.\n");
} else {
    define('LAB_FORCE_DRIVER', 'sqlite');  // config.local.json databaseDriver pgsql must not move the tests
    putenv('PACELAB_DRIVER=sqlite');
}

// The throwaway database goes on the drive beside the data folder, not in
// %TEMP% on C: (owner standard B, D-007). The loader is required on its own
// here because LAB_DB_PATH has to be defined before includes/db.php runs.
require_once __DIR__ . '/../includes/local-config.php';
$scratch = lab_temp_dir();
if ($scratch !== null && !is_dir($scratch)) {
    @mkdir($scratch, 0777, true);
}
$tempRoot = ($scratch !== null && is_dir($scratch)) ? $scratch : sys_get_temp_dir();
$tempDb = $tempRoot . '/lab-tests-' . getmypid() . '.sqlite';
foreach (glob($tempRoot . '/lab-tests-*.sqlite*') ?: [] as $stale) {
    @unlink($stale);                    // leftovers from a previous run
}
define('LAB_DB_PATH', $tempDb);

require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/schema.php';

// The rules below are written for the password mode (typed names, admin_users).
// authMode in config.local.json or PACELAB_AUTH_MODE must not move them; the
// NetID section near the end switches modes itself (D-015).
auth_mode('password');

// ---------------------------------------------------------------------------
// The smallest harness that does the job
// ---------------------------------------------------------------------------

$passed = 0;
$failed = [];
$group  = '';

function heading(string $title): void
{
    $GLOBALS['group'] = $title;
    echo PHP_EOL, '  ', $title, PHP_EOL;
}

function ok(string $what, bool $condition, string $detail = ''): void
{
    if ($condition) {
        $GLOBALS['passed']++;
        echo '    pass  ', $what, PHP_EOL;
        return;
    }
    $GLOBALS['failed'][] = $GLOBALS['group'] . ' - ' . $what . ($detail !== '' ? ' (' . $detail . ')' : '');
    echo '    FAIL  ', $what, ($detail !== '' ? '   [' . $detail . ']' : ''), PHP_EOL;
}

function same(string $what, $expected, $actual): void
{
    ok($what, $expected === $actual,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

/** Run something the database should refuse, and say whether it did. */
function refuses(string $what, callable $fn): void
{
    try {
        $fn();
        ok($what, false, 'it was allowed');
    } catch (Throwable $e) {
        ok($what, true);
    }
}

/**
 * A drive (letter and colon) that is not mounted on this computer: the first
 * free one, scanning Z down to C. Checks that need a lettered path build it
 * from this, so no drive letter is written in the tests.
 */
function free_drive(): string
{
    for ($c = ord('Z'); $c >= ord('C'); $c--) {
        if (!is_dir(chr($c) . ':' . DIRECTORY_SEPARATOR)) {
            return chr($c) . ':';
        }
    }
    throw new RuntimeException('every drive letter is mounted');
}

// ---------------------------------------------------------------------------
// Two laboratories with something in them
// ---------------------------------------------------------------------------

$pdo = db();
require_once __DIR__ . '/../includes/db_migrations.php';
if (db_driver() === 'pgsql') {
    /*
     * Ask the server which database this connection reached, and refuse
     * unless it is the test database named above. The name check before
     * connecting guards the setting; this one guards everything between the
     * setting and the socket.
     */
    $reached = (string) db_value('SELECT current_database()');
    if ($reached !== $pgTarget) {
        fwrite(STDERR, "Refusing to run: connected to '$reached', not the test database '$pgTarget'.\n");
        exit(2);
    }
    /*
     * Build the test database from nothing on every run: drop every table
     * (the application role owns them; mig_pg_reset_test_database() refuses
     * a database not ending in _test and any table another role owns), then
     * apply migrations/*.pgsql.sql through the same runner tools/migrate.php
     * and install.php use. The suite therefore exercises exactly the schema a
     * new PostgreSQL installation gets, every identity starts at 1 as on a
     * fresh SQLite file, and no row from anywhere else survives into a run.
     * The earlier arrangement (a TEMPLATE copy of the live database, emptied
     * row by row) tested production's schema rather than the migrations.
     */
    mig_pg_reset_test_database($pdo);
    mig_apply($pdo, 'pgsql');
} else {
    foreach (schema_statements() as $sql) {
        $pdo->exec($sql);
    }
}
foreach (seed_units() as [$code, $name]) {
    db_run('INSERT INTO units (code, name, active) VALUES (?, ?, 1)', [$code, $name]);
}
foreach (seed_picklists() as [$list, $code, $label, $sort, $protected]) {
    db_insert_ignore(
        'picklists',
        ['list_key', 'code', 'label', 'sort_order', 'active', 'protected'],
        [$list, $code, $label, $sort, 1, $protected]
    );
}
$seeded = seed_settings('Test Installation');
$seeded['app_name'] = 'Test Application';
foreach ($seeded as $key => $value) {
    db_insert_ignore('settings', ['key', 'value'], [$key, $value]);
}

function make_lab(string $code, string $name): int
{
    db_run('INSERT INTO labs (code, name, description, lab_group_name, lab_group_email,
              equipment_contact_name, equipment_contact_email, equipment_contact_cc,
              active, created_at)
            VALUES (?, ?, \'\', ?, ?, ?, ?, \'\', 1, ?)',
        [$code, $name, 'the ' . $name . ' group', strtolower($code) . '-group@example.edu',
         'Contact ' . $code, strtolower($code) . '-contact@example.edu', date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

function add_member(int $labId, string $person): void
{
    db_run('INSERT INTO lab_members (lab_id, person_key, display_name, created_at)
            VALUES (?, ?, ?, ?)', [$labId, person_key($person), $person, date('Y-m-d H:i:s')]);
}

function make_equipment(int $labId, string $name, float $rate, string $unit, string $tag): int
{
    db_run('INSERT INTO equipment (lab_id, property_tag, equip_class, name, manufacturer, model,
              location, rate, rate_unit, rate_effective_date, receiving_subaccount,
              contact_person, status, active, created_at)
            VALUES (?, ?, \'instrument\', ?, \'Maker\', \'Model\', \'Room 1\', ?, ?, ?,
                    \'1-303631-375002-375150-A51\', \'Somebody\', \'available\', 1, ?)',
        [$labId, clean_property_tag($tag), $name, $rate, $unit, date('Y-01-01'), date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

function make_grant(int $labId, string $cfopa, string $from, string $to): int
{
    $cfopa = cfopa_normalize($cfopa);
    db_run('INSERT INTO grants (lab_id, cfopa, cfopa_base, activity_code, title, display_label,
              principal_investigator, start_date, end_date, active, created_at)
            VALUES (?, ?, ?, ?, \'Award title\', ?, \'A PI\', ?, ?, 1, ?)',
        [$labId, $cfopa, cfopa_base($cfopa), cfopa_activity($cfopa), substr($cfopa, 2, 6),
         date('Y-m-d', strtotime($from)), date('Y-m-d', strtotime($to)), date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

/** Record a charge the way the entry screen does, snapshotting the rate. */
function charge(int $equipmentId, int $grantId, string $date, int $count, string $who = 'Ruiz'): int
{
    $item = db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$equipmentId]);
    $rate = (float) $item['rate'];
    db_run('INSERT INTO usage_records (equipment_id, grant_id, operator_name, use_date, sample_count,
              rate_charged, rate_unit_charged, receiving_subaccount, total_charge,
              batch_identifier, notes, exported, voided, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'\', \'\', 0, 0, ?)',
        [$equipmentId, $grantId, $who, $date, $count, $rate, $item['rate_unit'],
         $item['receiving_subaccount'], round($rate * $count, 2), date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

/** Book a span the way the calendar does: the server decides, not the caller. */
function book(int $equipmentId, string $start, string $end, string $who): ?int
{
    if (reservation_conflict($equipmentId, $start, $end)) {
        return null;
    }
    db_run('INSERT INTO reservations (equipment_id, reserved_by, start_datetime, end_datetime,
              purpose, created_at)
            VALUES (?, ?, ?, ?, \'\', ?)', [$equipmentId, $who, $start, $end, date('Y-m-d H:i:s')]);
    return (int) db()->lastInsertId();
}

/** Look at the application as this person, in this laboratory. */
function acting_as(string $person, int $labId = 0, bool $admin = false): void
{
    $_SESSION['user_name'] = $person;
    if ($admin) {
        $_SESSION['admin_user_id']   = 1;
        $_SESSION['admin_last_seen'] = time();
    } else {
        unset($_SESSION['admin_user_id'], $_SESSION['admin_last_seen']);
    }
    unset($_SESSION['lab_id']);
    if ($labId > 0) {
        $_SESSION['lab_id'] = $labId;
    }
}

$labA = make_lab('AAA', 'Alpha Lab');
$labB = make_lab('BBB', 'Beta Lab');
add_member($labA, 'Ruiz');
add_member($labA, 'Okafor');
add_member($labB, 'Nakamura');
add_member($labB, 'Ruiz');                  // one person, both laboratories

db_run('INSERT INTO admin_users (username, password_hash, display_name, active, created_at)
        VALUES (?, ?, ?, 1, ?)',
    ['root', password_hash('a-long-enough-password', PASSWORD_DEFAULT), 'Root', date('Y-m-d H:i:s')]);

$icpA  = make_equipment($labA, 'ICP-MS',    18.50, 'per sample', 'P10E00001');
$gcA   = make_equipment($labA, 'GC-MS',     42.00, 'per hour',   'P10E00002');
$cytoB = make_equipment($labB, 'Cytometer', 28.00, 'per sample', 'P10E00003');

$shared = '1-473723-375002-191100-A00';
$grantA = make_grant($labA, $shared,                      '-2 years', '+1 year');
$grantB = make_grant($labB, $shared,                      '-2 years', '+1 year');  // same award, other lab
$gone   = make_grant($labA, '1-345678-375002-375139-A00', '-4 years', '-40 days'); // lapsed
$future = make_grant($labA, '1-456789-375002-375139-A00', '+30 days', '+3 years'); // not started

echo PHP_EOL, 'Shared Laboratory Equipment - regression tests', PHP_EOL;
echo str_repeat('=', 64), PHP_EOL;

// ---------------------------------------------------------------------------
heading('Money: a charge remembers the rate it was made at');

acting_as('Ruiz', $labA);
$early = charge($icpA, $grantA, date('Y-m-d', strtotime('-30 days')), 10);
same('the charge is made at the rate of the day', 185.0,
    (float) db_value('SELECT total_charge FROM usage_records WHERE usage_id = ?', [$early]));

db_run('UPDATE equipment SET rate = 22.75 WHERE equipment_id = ?', [$icpA]);
same('raising the rate does not rewrite the old charge', 18.5,
    (float) db_value('SELECT rate_charged FROM usage_records WHERE usage_id = ?', [$early]));
same('nor its total', 185.0,
    (float) db_value('SELECT total_charge FROM usage_records WHERE usage_id = ?', [$early]));

$late = charge($icpA, $grantA, date('Y-m-d'), 10);
same('a new charge uses the new rate', 227.5,
    (float) db_value('SELECT total_charge FROM usage_records WHERE usage_id = ?', [$late]));
same('so the billing report carries both rates', 2,
    count(db_all('SELECT DISTINCT rate_charged FROM usage_records WHERE equipment_id = ?', [$icpA])));
same('the unit is snapshotted too', 'per sample',
    (string) db_value('SELECT rate_unit_charged FROM usage_records WHERE usage_id = ?', [$early]));
same('and the receiving account', '1-303631-375002-375150-A51',
    (string) db_value('SELECT receiving_subaccount FROM usage_records WHERE usage_id = ?', [$early]));

// ---------------------------------------------------------------------------
heading('Accounts: CFOPA is five segments and belongs to a laboratory');

same('a four-segment code gains the parent activity',
    '1-100026-375002-375139-A00', cfopa_normalize('1-100026-375002-375139'));
same('a five-segment code is left alone',
    '1-100026-375002-375139-A51', cfopa_normalize('1-100026-375002-375139-A51'));
same('the base is the first four segments',
    '1-100026-375002-375139', cfopa_base('1-100026-375002-375139-A51'));
same('the activity segment reads back', 'A51', cfopa_activity('1-100026-375002-375139-A51'));
ok('a well formed code is recognised', cfopa_is_well_formed('1-303631-375002-375150-A51'));
ok('a short code is not', !cfopa_is_well_formed('303631-375002'));
ok('a code with letters in the fund is not', !cfopa_is_well_formed('1-3X3631-375002-375150-A51'));
same('the fund type comes from the fund segment', 'Federal', cfopa_fund_type('1-503631-375002-375150-A00'));

same('two laboratories may hold the same award', 2,
    (int) db_value('SELECT COUNT(*) FROM grants WHERE cfopa = ?', [$shared]));
refuses('the same award twice in one laboratory is refused',
    function () use ($labA, $shared) { make_grant($labA, $shared, '-1 year', '+1 year'); });

// ---------------------------------------------------------------------------
heading('Bookings: the server decides every conflict');

$day = date('Y-m-d', strtotime('+3 days'));
ok('a free slot is booked',
    book($icpA, $day . ' 10:00:00', $day . ' 12:00:00', 'Ruiz') !== null);
ok('a slot that overlaps it is refused',
    book($icpA, $day . ' 11:00:00', $day . ' 13:00:00', 'Okafor') === null);
ok('a slot wholly inside it is refused',
    book($icpA, $day . ' 10:30:00', $day . ' 11:00:00', 'Okafor') === null);
ok('a slot that swallows it is refused',
    book($icpA, $day . ' 09:00:00', $day . ' 14:00:00', 'Okafor') === null);
ok('a slot that only touches the end is allowed',
    book($icpA, $day . ' 12:00:00', $day . ' 13:00:00', 'Okafor') !== null);
ok('a slot that only touches the start is allowed',
    book($icpA, $day . ' 09:00:00', $day . ' 10:00:00', 'Okafor') !== null);
ok('the same hour on another instrument is free',
    book($gcA, $day . ' 10:00:00', $day . ' 12:00:00', 'Okafor') !== null);
ok('editing a booking does not collide with itself',
    reservation_conflict($icpA, $day . ' 10:00:00', $day . ' 12:00:00',
        (int) db_value('SELECT reservation_id FROM reservations WHERE equipment_id = ?
                         AND start_datetime = ?', [$icpA, $day . ' 10:00:00'])) === null);

// ---------------------------------------------------------------------------
heading('An instrument out of service takes no new work');

db_run('UPDATE equipment SET status = ?, status_note = ?, status_since = ? WHERE equipment_id = ?',
    ['down', 'The turbo pump failed.', date('Y-m-d'), $gcA]);
$downItem = db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$gcA]);
ok('a down instrument cannot be used', !equipment_is_usable($downItem));
ok('and knows it is down', equipment_is_down($downItem));
ok('the refusal repeats the reason given',
    strpos((string) equipment_unusable_reason($downItem), 'The turbo pump failed.') !== false);

db_run('UPDATE equipment SET status = ? WHERE equipment_id = ?', ['service_due', $gcA]);
ok('service due is a warning, not a bar',
    equipment_is_usable(db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$gcA])));

db_run('UPDATE equipment SET status = ?, active = 0 WHERE equipment_id = ?', ['retired', $gcA]);
$retired = db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$gcA]);
ok('a retired instrument cannot be used', !equipment_is_usable($retired));
ok('and says so in its own words',
    strpos((string) equipment_unusable_reason($retired), 'retired') !== false);
acting_as('Ruiz', $labA);
same('it leaves the active list', 1, count(lab_equipment(true)));
same('but stays on the books', 2, count(lab_equipment(false)));
same('and keeps its charge history', 0, (int) db_value(
    'SELECT COUNT(*) FROM usage_records WHERE equipment_id = ?', [$gcA]));
db_run('UPDATE equipment SET status = ?, active = 1, status_note = \'\' WHERE equipment_id = ?',
    ['available', $gcA]);

// ---------------------------------------------------------------------------
heading('Laboratories cannot see into each other');

acting_as('Okafor', $labA);                 // Alpha only, not an administrator
same('the instrument list is this laboratory only', 2, count(lab_equipment(true)));
ok('an instrument id from the other laboratory finds nothing',
    equipment_by_id($cytoB) === null);
ok('one from this laboratory is found', equipment_by_id($icpA) !== null);
same('the account list is this laboratory only', 3, count(lab_grants(false)));
ok('a grant id from the other laboratory finds nothing', grant_by_id($grantB) === null);
ok('a grant from this laboratory is found', grant_by_id($grantA) !== null);

acting_as('Nakamura', $labB);
same('the other laboratory sees only its own instrument', 1, count(lab_equipment(true)));
ok('and cannot reach the first laboratory\'s by id', equipment_by_id($icpA) === null);
same('no charge crosses between them', 0, (int) db_value(
    'SELECT COUNT(*) FROM usage_records r
       JOIN equipment e ON e.equipment_id = r.equipment_id
      WHERE e.lab_id = ?', [$labB]));
ok('somebody may not step into a laboratory they are not in', !may_use_lab($labA));
same('and asking for it does not put them there', $labB, current_lab_id());

acting_as('Ruiz', $labA);
ok('somebody in both may enter either', may_use_lab($labA) && may_use_lab($labB));
acting_as('Stranger');
same('somebody in none has none', 0, count(labs_for_person()));
ok('and there is no laboratory in view', current_lab() === null);
acting_as('Root', $labB, true);
same('an administrator may work in any of them', 2, count(labs_for_person()));

// ---------------------------------------------------------------------------
heading('Messages belong to one laboratory');

acting_as('Root', $labA, true);
$msgA = log_email(compose_equipment_email(
    db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$icpA]), 'down'));
acting_as('Root', $labB, true);
$msgB = log_email(compose_lab_group_email(
    db_one('SELECT * FROM equipment WHERE equipment_id = ?', [$cytoB]), 'down'));

same('a message is stamped with its laboratory', $labA,
    (int) db_value('SELECT lab_id FROM email_log WHERE email_id = ?', [$msgA]));
same('one laboratory sees one message', 1,
    (int) db_value('SELECT COUNT(*) FROM email_log WHERE lab_id = ?', [$labA]));
same('the other sees its own', 1,
    (int) db_value('SELECT COUNT(*) FROM email_log WHERE lab_id = ?', [$labB]));
ok('a message names the laboratory it came from',
    strpos((string) db_value('SELECT body FROM email_log WHERE email_id = ?', [$msgB]), 'Beta Lab') !== false);
same('and goes to that laboratory\'s own group', 'bbb-group@example.edu',
    (string) db_value('SELECT to_address FROM email_log WHERE email_id = ?', [$msgB]));
same('the equipment message goes to that laboratory\'s equipment person',
    'aaa-contact@example.edu',
    (string) db_value('SELECT to_address FROM email_log WHERE email_id = ?', [$msgA]));
same('the log records it as written, not sent', 'composed',
    (string) db_value('SELECT status FROM email_log WHERE email_id = ?', [$msgA]));
mark_email_opened($msgA);
same('and as opened once the mail client was opened', 'opened',
    (string) db_value('SELECT status FROM email_log WHERE email_id = ?', [$msgA]));
ok('with the time it happened',
    (string) db_value('SELECT opened_at FROM email_log WHERE email_id = ?', [$msgA]) !== '');
same('a message with no address is not counted as sendable', 'no_recipient',
    (string) db_value('SELECT status FROM email_log WHERE email_id = ?',
        [log_email(['lab_id' => $labB, 'purpose' => 'test', 'to' => '', 'subject' => 'x', 'body' => 'y'])]));

// ---------------------------------------------------------------------------
heading('Tags and identity');

same('a tag is stored upper case', 'P10E35946', clean_property_tag('  p10e35946 '));
same('an empty tag stays empty', '', clean_property_tag('   '));
same('a person key is folded and trimmed', 'ruiz', person_key('  Ruiz  '));
same('a tag identifies one item across the whole institute', 1, (int) db_value(
    'SELECT COUNT(*) FROM equipment WHERE property_tag = ?', ['P10E00001']));
refuses('the same tag on a second instrument is refused, even in another laboratory',
    function () use ($labB) { make_equipment($labB, 'Clone', 1.0, 'per sample', 'P10E00001'); });
ok('two untagged instruments are allowed',
    make_equipment($labB, 'Untagged one', 1.0, 'per sample', '') > 0
    && make_equipment($labB, 'Untagged two', 1.0, 'per sample', '') > 0);

// ---------------------------------------------------------------------------
heading('Award dates decide what may be charged');

acting_as('Ruiz', $labA);
$offered = array_column(grants_for_date(date('Y-m-d')), 'grant_id');
ok('a current award is offered',           in_array($grantA, $offered, false));
ok('a lapsed award is not',                !in_array($gone, $offered, false));
ok('an award not yet started is not',      !in_array($future, $offered, false));
ok('the lapsed one is offered on a date it did cover',
    in_array($gone, array_column(grants_for_date(date('Y-m-d', strtotime('-1 year'))), 'grant_id'), false));
ok('the other laboratory\'s award is never offered here',
    !in_array($grantB, $offered, false));
db_run('UPDATE grants SET active = 0 WHERE grant_id = ?', [$grantA]);
ok('a closed award drops out even inside its dates',
    !in_array($grantA, array_column(grants_for_date(date('Y-m-d')), 'grant_id'), false));
db_run('UPDATE grants SET active = 1 WHERE grant_id = ?', [$grantA]);

// ---------------------------------------------------------------------------
heading('Voiding keeps the row and stops the money');

$victim = charge($icpA, $grantA, date('Y-m-d'), 4);
$before = (float) db_value('SELECT COALESCE(SUM(total_charge), 0) FROM usage_records WHERE voided = 0');
db_run('UPDATE usage_records SET voided = 1, void_reason = ? WHERE usage_id = ?', ['Rerun.', $victim]);
$after  = (float) db_value('SELECT COALESCE(SUM(total_charge), 0) FROM usage_records WHERE voided = 0');
ok('a voided charge stops counting', $after < $before);
ok('but the row is still there',
    db_one('SELECT * FROM usage_records WHERE usage_id = ?', [$victim]) !== null);
ok('with the reason attached',
    (string) db_value('SELECT void_reason FROM usage_records WHERE usage_id = ?', [$victim]) !== '');

// ---------------------------------------------------------------------------
heading('Export, lock, reopen');

$unexported = static function (int $labId): int {
    return (int) db_value(
        'SELECT COUNT(*) FROM usage_records r
           JOIN equipment e ON e.equipment_id = r.equipment_id
          WHERE e.lab_id = ? AND r.exported = 0 AND r.voided = 0', [$labId]);
};

$pending = $unexported($labA);
ok('there are charges waiting to be billed', $pending > 0);

db_run('INSERT INTO export_batches (lab_id, period_start, period_end, generated_at, generated_by,
          record_count, total_amount, reopened)
        VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
    [$labA, date('Y-m-01'), date('Y-m-t'), date('Y-m-d H:i:s'), 'Root', $pending, 0]);
$batch = (int) db()->lastInsertId();
db_run('UPDATE usage_records SET exported = 1, export_batch_id = ?
         WHERE exported = 0 AND voided = 0
           AND equipment_id IN (SELECT equipment_id FROM equipment WHERE lab_id = ?)',
    [$batch, $labA]);

same('exporting empties the waiting pool', 0, $unexported($labA));
same('and attaches every record to the batch', $pending, (int) db_value(
    'SELECT COUNT(*) FROM usage_records WHERE export_batch_id = ?', [$batch]));
$repeat = charge($icpA, $grantA, date('Y-m-d'), 1);
same('a charge made afterwards is not in that batch', 1, $unexported($labA));
ok('and is not attached to it',
    db_value('SELECT export_batch_id FROM usage_records WHERE usage_id = ?', [$repeat]) === null);

db_run('UPDATE usage_records SET exported = 0, export_batch_id = NULL WHERE export_batch_id = ?', [$batch]);
db_run('UPDATE export_batches SET reopened = 1 WHERE batch_id = ?', [$batch]);
same('reopening returns every record to the pool', $pending + 1, $unexported($labA));
ok('and the batch itself is kept, marked reopened',
    (int) db_value('SELECT reopened FROM export_batches WHERE batch_id = ?', [$batch]) === 1);
same('the other laboratory has no batches', 0,
    (int) db_value('SELECT COUNT(*) FROM export_batches WHERE lab_id = ?', [$labB]));

// ---------------------------------------------------------------------------
heading('Alerts, in the order they matter');

acting_as('Root', $labA, true);
// A week booked solid inside the utilisation window: heavily subscribed.
$from = date('Y-m-d 08:00:00', strtotime('-10 days'));
$to   = date('Y-m-d 08:00:00', strtotime('-3 days'));
book($icpA, $from, $to, 'Ruiz');
$use = equipment_utilisation($icpA);
ok('a solidly booked week reads as heavily used', $use['percent'] >= 75,
    'percent was ' . $use['percent']);
ok('an instrument nobody booked reads as idle', equipment_utilisation($gcA)['percent'] === 0);

db_run('UPDATE equipment SET status = ?, status_note = ?, status_since = ? WHERE equipment_id = ?',
    ['down', 'Pump failed.', date('Y-m-d'), $gcA]);
db_run('UPDATE equipment SET service_due_date = ? WHERE equipment_id = ?',
    [date('Y-m-d', strtotime('-5 days')), $icpA]);

$alerts = equipment_alerts();
ok('something needs attention', count($alerts) > 0);
same('the broken instrument is read first', ALERT_DOWN, (int) $alerts[0]['rank']);
$ranks = array_column($alerts, 'rank');
$sorted = $ranks;
sort($sorted);
same('the list is in order of urgency', $sorted, $ranks);
ok('the overdue service is in there', in_array(ALERT_SERVICE_OVERDUE, $ranks, true));
ok('every alert has somewhere to go',
    count(array_filter($alerts, static function (array $a) { return ($a['href'] ?? '') !== ''; })) === count($alerts));

acting_as('Nakamura', $labB);
$names = array_column(equipment_alerts(), 'name');
ok('the other laboratory is not told about this one\'s instruments',
    !in_array('GC-MS', $names, true) && !in_array('ICP-MS', $names, true));

// ---------------------------------------------------------------------------
heading('Saying who you are returns you where you were going');

same('the chooser is where somebody lands by default', 'home.php', safe_return_to(null));
same('an empty return goes there too', 'home.php', safe_return_to(''));
same('a screen asked for is the screen returned to', 'schedule.php', safe_return_to('schedule.php'));
same('somebody who opened use entry stays on use entry', 'index.php',
    safe_return_to('index.php', 'index.php'));
same('a query string survives', 'schedule.php?equipment_id=4',
    safe_return_to('schedule.php?equipment_id=4'));
// An open redirect is how a plausible link turns into somebody else's sign-in page.
same('another site is refused',      'home.php', safe_return_to('https://evil.example.com/steal'));
same('a protocol-relative one too',  'home.php', safe_return_to('//evil.example.com'));
same('javascript: is refused',       'home.php', safe_return_to('javascript:alert(1)'));
same('so is a walk up the tree',     'home.php', safe_return_to('../admin/settings.php'));
same('and a page that does not exist', 'home.php', safe_return_to('nonsense.php'));

// ---------------------------------------------------------------------------
heading('The browser tab says where you are');

/** The <title> a screen would render. */
function tab_title(string $heading): string
{
    ob_start();
    page_header($heading);
    $html = (string) ob_get_clean();
    return preg_match('/<title>(.*?)<\/title>/s', $html, $m) ? html_entity_decode(trim($m[1])) : '';
}

acting_as('Ruiz', $labA);
same('a screen is named, then the laboratory', 'Use entry · Alpha Lab', tab_title('Use entry'));
same('the laboratory dashboard names the application rather than repeating itself',
    'Alpha Lab · Test Application', tab_title('Alpha Lab'));
acting_as('Stranger');
same('with no laboratory yet, the application names itself',
    'Choose a laboratory · Test Application', tab_title('Choose a laboratory'));

// ---------------------------------------------------------------------------
heading('Where the data folder resolves');

// includes/local-config.php decides where the live database lives, and until
// the estate moved drives it had no check of its own. On Windows a backslash
// and a forward slash are both separators; on a Linux server only the forward
// slash is, so a dataFolder written as ..\..\data\x has to be normalised
// before it is split into segments. Remove that normalisation and this copy
// keeps working while a Linux server silently makes a folder whose name is the
// whole relative path.
$ds = DIRECTORY_SEPARATOR;

same('a plain name resolves inside the application folder',
    APP_ROOT . $ds . 'data', lab_resolve('data'));
same('a parent step lands one folder up',
    dirname(APP_ROOT) . $ds . 'sibling', lab_resolve('../sibling'));
same('two parent steps and a name resolve as written',
    dirname(dirname(APP_ROOT)) . $ds . 'data' . $ds . 'pacelab-equip' . $ds . 'data',
    lab_resolve('../../data/pacelab-equip/data'));
same('the backslash form resolves to the same folder as the forward slash form',
    lab_resolve('../../data/pacelab-equip/data'),
    lab_resolve('..\..\data\pacelab-equip\data'));
ok('no relative segment survives resolution',
    !in_array('..', explode($ds, lab_resolve('..\..\data\x')), true)
    && !in_array('.', explode($ds, lab_resolve('.\data')), true));
ok('an absolute dataFolder is recognised, so lab_data_dir() can refuse it',
    lab_path_is_absolute(free_drive() . '\lab') && lab_path_is_absolute('/srv/lab')
    && lab_path_is_absolute('\\server\share')
    && !lab_path_is_absolute('data') && !lab_path_is_absolute('..\..\data'));

// DEPLOY.md tells a hoster to move the database with config.php, which works
// by defining LAB_DB_PATH before includes/db.php runs. This harness does the
// same thing, so the guarantee is testable here: the loader must never
// override a path that is already defined.
ok('a path defined before the loader runs wins, which is how config.php moves the database',
    LAB_DB_PATH === $tempDb);

// Owner standard B: the application keeps nothing on the computer. The temp
// folder is the one that was easy to get wrong, because PHP's default session
// folder is on C: and a session file says who is signed in.
if (lab_data_folder_configured()) {
    $tmpDir = (string) lab_temp_dir();
    same('the temp folder sits beside the data folder',
        dirname(lab_data_dir()) . DIRECTORY_SEPARATOR . 'tmp', $tmpDir);
    ok('and not inside it, so scratch is never in what gets backed up',
        strpos($tmpDir, lab_data_dir() . DIRECTORY_SEPARATOR) !== 0);
    ok('the suite runs its throwaway database there rather than in the system temp',
        strpos(LAB_DB_PATH, $tmpDir) === 0, LAB_DB_PATH);
} else {
    ok('with no data folder configured the temp folder is left to the server',
        lab_temp_dir() === null);
}

// The server launcher has to pass the folder to PHP: resolving it is not the
// same as using it, and nothing else would notice if the flags went missing.
$sup = (string) file_get_contents(APP_ROOT . '/tools/serve.ps1');
ok('the supervisor in tools/serve.ps1 points PHP sessions, uploads and temp at that folder',
    strpos($sup, 'session.save_path=') !== false
    && strpos($sup, 'upload_tmp_dir=') !== false
    && strpos($sup, 'sys_temp_dir=') !== false
    && strpos($sup, 'Get-LabTempDir') !== false);

// config.template.json is the file a hoster copies to config.local.json, and
// lab_config_save() seeds from it. Leaving it out of the release ships a
// loader nobody can configure.
$ship = (string) file_get_contents(APP_ROOT . '/tools/build-release.ps1');
$cut  = function (string $text, string $start): string {
    $i = strpos($text, $start);
    if ($i === false) { return ''; }
    $j = strpos($text, "

", $i);
    return $j === false ? '' : substr($text, $i, $j - $i);
};
$shipList  = $cut($ship, '$files = @(');
$shipNever = $cut($ship, '$forbidden = @(');

ok('the ship list and the forbidden list were both found',
    $shipList !== '' && $shipNever !== '');
ok('config.template.json ships, so the loader in the zip can be configured',
    strpos($shipList, "'config.template.json'") !== false
    && strpos($shipNever, "'config.template.json'") === false);
ok('config.local.json never ships, because it names one machine',
    strpos($shipNever, "'config.local.json'") !== false
    && strpos($shipList, "'config.local.json'") === false);

if (lab_data_folder_configured()) {
    same('the data folder in use is the resolved dataFolder',
        lab_resolve(trim((string) lab_config()['dataFolder'])), lab_data_dir());
} else {
    same('with no dataFolder the data stays inside the application folder',
        APP_ROOT . $ds . 'data', lab_data_dir());
}

// ---------------------------------------------------------------------------
heading('Editing the code from inside the application');

require_once __DIR__ . '/../includes/studio.php';

// The containment check is the whole security of this feature. It has to be
// proved, not asserted: it decides whether a URL can reach outside the
// application folder and rewrite something on the server.
$root = studio_root();

ok('a real source file resolves',            studio_resolve('includes/functions.php') !== null);
ok('so does one in a subfolder',             studio_resolve('admin/users.php') !== null);
ok('walking up with .. is refused',          studio_resolve('../../../windows/win.ini') === null);
ok('so is a disguised walk',                 studio_resolve('includes/../../secrets.php') === null);
// A real file outside the folder, found from %SystemRoot% so no drive letter is written here.
$outside = str_replace('\\', '/', (string) (getenv('SystemRoot') ?: '/etc')) . '/win.ini';
ok('an absolute path outside is refused',    studio_resolve($outside) === null);
ok('a unix absolute path is refused',        studio_resolve('/etc/passwd') === null);
ok('a null byte is refused',                 studio_resolve("includes/db.php\0.txt") === null);
ok('the database is not editable',           studio_resolve('data/lab.sqlite') === null);
ok('nor anything else under data',           studio_resolve('data/.htaccess') === null);
ok('nor the bundled PHP runtime',            studio_resolve('tools/php/php.exe') === null);
ok('nor config.local.json (the data folder)', studio_resolve('config.local.json') === null);
ok('an empty path is refused',               studio_resolve('') === null);
ok('a directory is refused',                 studio_resolve('includes') === null);
ok('a file that does not exist is refused',  studio_resolve('includes/nope.php') === null);

// Everything the tree offers must itself resolve, or the rail would list
// files the editor then refuses to open.
$tree = studio_tree();
ok('the tree lists the application source', count($tree) > 20);
$unresolvable = [];
foreach ($tree as $rel) {
    if (studio_resolve($rel) === null) {
        $unresolvable[] = $rel;
    }
}
ok('everything it lists can be opened', $unresolvable === [], implode(', ', array_slice($unresolvable, 0, 5)));
$leaked = array_filter($tree, function ($p) {
    return strpos($p, 'data/') === 0 || strpos($p, 'tools/php/') === 0 || strpos($p, 'dist/') === 0;
});
ok('it lists nothing under data, dist or the runtime', $leaked === []);

// A file that will not parse must cost a message, not the application.
$good = studio_check('x.php', "<?php\nfunction a() { return 1; }\n");
ok('valid PHP passes the check', $good['ok'] === true);
$bad = studio_check('x.php', "<?php\nfunction a( { return 1;\n");
ok('a syntax error is caught', $bad['ok'] === false);
ok('and the message says where', strpos($bad['message'], 'line') !== false);
ok('a missing brace is caught', studio_check('x.php', "<?php if (true) {\n")['ok'] === false);
same('CSS is not parsed as PHP', true, studio_check('x.css', 'body { color: red }')['ok']);

// Saving: refuse broken code, keep the old copy, and put it back on request.
$scratch = 'studio-selftest.txt';
$full    = $root . '/' . $scratch;
file_put_contents($full, "one\n");
[$okSave, $msg] = studio_save($scratch, "two\n");
ok('a good save is written', $okSave && file_get_contents($full) === "two\n");
ok('and the previous copy is kept', count(studio_backups($scratch)) >= 1);

$php = 'studio-selftest.php';
$phpFull = $root . '/' . $php;
file_put_contents($phpFull, "<?php\nreturn 1;\n");
[$okBad, $msgBad] = studio_save($php, "<?php\nfunction ( {\n");
ok('broken PHP is refused', $okBad === false);
ok('and the file on disk is untouched',
   file_get_contents($phpFull) === "<?php\nreturn 1;\n");

$copies = studio_backups($scratch);
if ($copies) {
    studio_restore($scratch, $copies[0]['name']);
    ok('restoring puts the old content back', file_get_contents($full) === "one\n");
} else {
    ok('restoring puts the old content back', false, 'no backup to restore');
}

ok('a save outside the application is refused',
   studio_save('../escaped.php', '<?php')[0] === false);

@unlink($full);
@unlink($phpFull);
foreach (array_merge(studio_backups($scratch), studio_backups($php)) as $b) {
    @unlink(studio_backup_dir() . '/' . $b['name']);
}

// The grant itself. An administrator is not automatically allowed in.
db_run('INSERT INTO admin_users (username, password_hash, display_name, active, created_at, may_edit_code)
        VALUES (?, ?, ?, 1, ?, 0)',
    ['plain', password_hash('a-long-enough-password', PASSWORD_DEFAULT), 'Plain Admin', date('Y-m-d H:i:s')]);
$plainId = (int) db()->lastInsertId();
same('a new administrator may not edit code', 0,
    (int) db_value('SELECT may_edit_code FROM admin_users WHERE user_id = ?', [$plainId]));
db_run('UPDATE admin_users SET may_edit_code = 1 WHERE user_id = ?', [$plainId]);
same('the grant can be given', 1,
    (int) db_value('SELECT may_edit_code FROM admin_users WHERE user_id = ?', [$plainId]));

// ---------------------------------------------------------------------------
heading('The interface keeps the shape the spec gives it');

/*
 * Two rules from docs/design.md that fail silently when they break.
 *
 * The button variants lost to the base rule for months: ".button-danger"
 * alone is one class, "button.button" and "a.button" are two, so every
 * secondary, danger and small button rendered as a solid navy primary and
 * nothing errored. Written as ".button.button-danger" the variant wins
 * (PCE-01).
 *
 * The native confirm() is worse than ugly: a browser that suppresses dialogs
 * turns "are you sure?" into a silent yes, and the voided charge or cancelled
 * booking is already gone. The application asks through PriDialog, with
 * confirm_page() for a browser with JavaScript off (PCE-35, Q-27).
 */
$css = (string) file_get_contents(__DIR__ . '/../assets/style.css');

$weakVariants = [];
foreach (['button-secondary', 'button-danger', 'button-small'] as $variant) {
    // A rule that opens on the bare variant, rather than on .button.variant.
    if (preg_match('/(^|,|\})\s*\.' . $variant . '[\s,:{]/m', $css)) {
        $weakVariants[] = '.' . $variant;
    }
}
ok('every button variant outranks the base rule', $weakVariants === [],
    implode(', ', $weakVariants) . ' must be written .button.<variant>');

$nativeDialogs = [];
$frontEnd = array_merge(
    glob(__DIR__ . '/../*.php') ?: [],
    glob(__DIR__ . '/../includes/*.php') ?: [],
    glob(__DIR__ . '/../admin/*.php') ?: [],
    glob(__DIR__ . '/../assets/*.js') ?: []
);
foreach ($frontEnd as $file) {
    $name = basename($file);
    if ($name === 'dialog.js') {
        continue;               // the file that explains why the others may not
    }
    $text = (string) file_get_contents($file);
    foreach (['confirm', 'prompt', 'alert'] as $call) {
        // window.confirm(...) or a bare confirm(...), but not our own
        // confirm_page(), needs_confirmation() or PriDialog.confirm().
        if (preg_match('/(?<![\w.>$])(?:window\s*\.\s*)?' . $call . '\s*\(/', $text)) {
            $nativeDialogs[] = $name . ': ' . $call . '()';
        }
    }
}
ok('nothing asks through a native browser dialog', $nativeDialogs === [],
    implode(', ', $nativeDialogs));

// ---------------------------------------------------------------------------
heading('The code runs on the server it is going to');

$sources = array_merge(
    glob(__DIR__ . '/../*.php') ?: [],
    glob(__DIR__ . '/../includes/*.php') ?: [],
    glob(__DIR__ . '/../admin/*.php') ?: []
);
// The campus server runs a current PHP, so the danger is no longer using
// something too new. It is using something a newer PHP has taken away: every
// one of these still runs today while printing a deprecation notice into the
// page, and stops running in a release after that.
$removed = [
    'utf8_encode'     => 'deprecated in 8.2',
    'utf8_decode'     => 'deprecated in 8.2',
    'create_function' => 'removed in 8.0',
    'each'            => 'removed in 8.0',
    'money_format'    => 'removed in 8.0',
    'strftime'        => 'deprecated in 8.1',
    'gmstrftime'      => 'deprecated in 8.1',
    'date_sunrise'    => 'deprecated in 8.1',
    'mhash'           => 'removed in 8.0',
];
$offenders = [];
foreach ($sources as $file) {
    $text = (string) file_get_contents($file);
    foreach ($removed as $fn => $when) {
        if (preg_match('/(?<![\w$>\'"])' . $fn . '\s*\(/', $text)) {
            $offenders[] = basename($file) . ': ' . $fn . '() ' . $when;
        }
    }
    // Deprecated in 8.2, and the kind of thing that slips into a heredoc.
    if (preg_match('/\$\{[a-zA-Z_]\w*\}/', $text)) {
        $offenders[] = basename($file) . ': ${var} interpolation, deprecated in 8.2';
    }
    // Deprecated in 8.4: a default of null without the parameter being nullable.
    if (preg_match('/function\s+\w+\s*\([^)]*?(?<!\?)\b(string|int|float|bool|array|iterable|object)\s+\$\w+\s*=\s*null/s', $text)) {
        $offenders[] = basename($file) . ': implicitly nullable parameter, deprecated in 8.4';
    }
}
ok('nothing calls what a newer PHP has taken away', $offenders === [],
    implode('; ', $offenders));

// Everything reaching htmlspecialchars() has been cast first. Passing null to
// an internal parameter has been deprecated since 8.1, and h() is the one
// funnel every screen puts its values through.
$hBody = (string) file_get_contents(__DIR__ . '/../includes/functions.php');
ok('the escaper casts before it escapes',
    preg_match('/function h\(\$value\): string\s*\{\s*return htmlspecialchars\(\(string\) \$value/', $hBody) === 1);
same('so it survives a null', '', h(null));
same('and a number', '42', h(42));
same('and escapes what matters', '&lt;script&gt;&amp;&quot;', h('<script>&"'));

// A value reaching SQL any way other than as a bound parameter. Two shapes are
// allowed and nothing else: a variable holding SQL being assembled ($sql), and
// a run of question marks for an IN list ($placeholders), which is checked
// below to be exactly that and never to carry a value.
$allowed     = ['sql', 'placeholders'];
$notPrepared = [];
foreach ($sources as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
        // Interpolation: a double-quoted SQL string with a variable inside it.
        if (preg_match('/"[^"]*\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b[^"]*\$\w/i', $line)) {
            $notPrepared[] = basename($file) . ':' . ($n + 1) . ' interpolated';
            continue;
        }
        // Concatenation: a variable glued onto a line that carries SQL.
        if (preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b/i', $line)
            && preg_match('/\'\s*\.\s*\$(\w+)/', $line, $m)
            && !in_array($m[1], $allowed, true)) {
            $notPrepared[] = basename($file) . ':' . ($n + 1) . ' concatenated $' . $m[1];
        }
    }
}
ok('no screen glues a value into an SQL string', $notPrepared === [],
    implode(', ', $notPrepared));

// Every named placeholder must be bound in the file that writes it.
//
// This is the check that would have caught the Reservations screen. SQLite
// binds a missing named parameter as NULL rather than raising, so
// "WHERE lab_id = :lab" with no :lab silently matches nothing: no error, no
// warning, just an empty table for as long as nobody notices. The dashboard's
// Today panel had the same fault in two queries.
$unbound = [];
foreach ($sources as $file) {
    $text = (string) file_get_contents($file);
    preg_match_all('/[ (,]:([a-z][a-zA-Z_]*)/', $text, $m);
    foreach (array_unique($m[1]) as $name) {
        $q = preg_quote($name, '/');
        $bound = preg_match('/[\'"]' . $q . '[\'"]\s*=>/', $text)
              || preg_match('/\[[\'"]' . $q . '[\'"]\]\s*=/', $text);
        if (!$bound) {
            $unbound[] = basename($file) . ': :' . $name;
        }
    }
}
ok('every named placeholder is bound where it is used', $unbound === [],
    implode(', ', $unbound));

$badPlaceholders = [];

foreach ($sources as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
        if (strpos($line, '$placeholders =') === false) {
            continue;
        }
        // The only honest way to build one: a count of question marks.
        if (!preg_match('/implode\(\s*\',\'\s*,\s*array_fill\(\s*0\s*,[^,]+,\s*\'\?\'\s*\)\s*\)/', $line)) {
            $badPlaceholders[] = basename($file) . ':' . ($n + 1);
        }
    }
}
ok('every IN list is question marks, never values', $badPlaceholders === [],
    implode(', ', $badPlaceholders));

// ---------------------------------------------------------------------------
heading('The data follows the drive, whatever its letter (drive-invariance)');

// Everything here happens in a folder of its own under the test scratch
// folder, removed at the end. The "other letter" is the first one with no
// drive behind it, computed here so no drive letter is written in the tests.
$dv = $tempRoot . DIRECTORY_SEPARATOR . 'drive-inv-' . getmypid();
@mkdir($dv . DIRECTORY_SEPARATOR . 'backups', 0777, true);
$unmounted = '';
for ($c = ord('Z'); $c >= ord('D'); $c--) {
    if (!is_dir(chr($c) . ':' . DIRECTORY_SEPARATOR)) {
        $unmounted = chr($c);
        break;
    }
}
$norm = static fn (string $p): string => strtolower(rtrim(str_replace('\\', '/', $p), '/'));

ok('a relative backup folder resolves against the application folder',
    lab_backup_target('../x/backups', 'test') === lab_resolve('../x/backups'));

// R2: a backup folder written while the drive had another letter is found
// under the drive's letter today. Possible only where paths carry letters and
// the scratch folder shares the application's drive or estate folder.
$backupsReal = (string) realpath($dv . DIRECTORY_SEPARATOR . 'backups');
$estateDir = dirname(dirname(APP_ROOT));
$reachable = $unmounted !== '' && preg_match('#^[A-Za-z]:#', $backupsReal)
    && (strncasecmp($backupsReal, APP_ROOT, 2) === 0
        || stripos($norm($backupsReal), $norm($estateDir) . '/') === 0);
if ($reachable) {
    $stale = $unmounted . substr($backupsReal, 1);
    try {
        $found = lab_backup_target($stale, 'test');
    } catch (Throwable $e) {
        $found = $e->getMessage();
    }
    ok('a backup folder stored under another drive letter is found under today\'s',
        $norm($found) === $norm($backupsReal), $stale . ' gave ' . $found);
} else {
    ok('a backup folder stored under another drive letter is found under today\'s (no drive letters here, nothing to re-root)', true);
}
refuses('a missing absolute backup folder is refused, never created',
    fn () => lab_backup_target(($unmounted !== '' ? $unmounted . ':' : '') . '/no-such-' . getmypid() . '/backups', 'test'));

// R4: the database never opens empty where the real one should have been.
$gone = $dv . '/gone/data/lab.sqlite';
$msg = lab_data_dir_problem($gone);
ok('a data folder whose parent is missing is refused with a message',
    is_string($msg) && str_contains($msg, 'Data folder not found') && !is_dir($dv . '/gone'), (string) $msg);
@mkdir($dv . '/installed', 0777, true);
file_put_contents($dv . '/installed/.installed', 'x');
$msg = lab_data_dir_problem($dv . '/installed/lab.sqlite');
ok('an installed data folder without lab.sqlite is refused, not reopened empty',
    is_string($msg) && str_contains($msg, 'Database not found'), (string) $msg);
@mkdir($dv . '/first', 0777, true);
ok('a first run creates only the leaf folder, under a parent that exists',
    lab_data_dir_problem($dv . '/first/data/lab.sqlite') === null && is_dir($dv . '/first/data'));
ok('the check alone creates nothing',
    lab_data_dir_problem($dv . '/first/other/lab.sqlite', false) === null && !is_dir($dv . '/first/other'));

$rm = static function (string $d) use (&$rm): void {
    foreach (glob($d . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $f) {
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($d);
};
$rm($dv);

heading('PostgreSQL: settings, the driver switch and the migrations (platform D-027)');

// Everything in a folder of its own, removed at the end. No value written
// here is a real setting: hosts and users are made-up words.
$pgd = $tempRoot . DIRECTORY_SEPARATOR . 'pg-settings-' . getmypid();
@mkdir($pgd, 0777, true);
file_put_contents($pgd . '/a.env', "\xEF\xBB\xBF# a comment\nPGHOST=host-a\nexport PGPORT=6001\nPGUSER=\"user-a\"\nUNRELATED=ignored\nPGDATABASE=\n");
file_put_contents($pgd . '/b.env', "PGHOST=host-b\nPGDATABASE=db-b\nPGTESTDATABASE=db_b_test\n");
$parsed = lab_env_parse($pgd . '/a.env');
same('an environment file yields only the PG* names, quotes and export removed, empty values skipped',
    ['PGHOST' => 'host-a', 'PGPORT' => '6001', 'PGUSER' => 'user-a'], $parsed);
same('the first file that sets a name wins, later files fill the gaps',
    ['PGHOST' => 'host-a', 'PGPORT' => '6001', 'PGUSER' => 'user-a', 'PGDATABASE' => 'db-b', 'PGTESTDATABASE' => 'db_b_test'],
    lab_env_collect([$pgd . '/a.env', $pgd . '/missing.env', $pgd . '/b.env']));

$files = array_column(lab_env_files(), 1);
ok('.env beside index.php is read last', end($files) === APP_ROOT . DIRECTORY_SEPARATOR . '.env');
ok('pg.env is read from beside the data folder when one is configured, never from a fixed path',
    lab_data_folder_configured()
        ? in_array(dirname(lab_data_dir()) . DIRECTORY_SEPARATOR . 'pg.env', $files, true)
        : count(array_filter($files, fn ($f) => str_ends_with($f, 'pg.env'))) === 0);
$was = getenv('PACELAB_ENV_FILE');
putenv('PACELAB_ENV_FILE=' . $pgd . '/b.env');
ok('PACELAB_ENV_FILE is read first when set', (lab_env_files()[0][1] ?? '') === $pgd . '/b.env');
$was === false ? putenv('PACELAB_ENV_FILE') : putenv('PACELAB_ENV_FILE=' . $was);
$sources = json_encode(lab_env_sources());
ok('the source report names files and names, never a value',
    !str_contains((string) $sources, 'host-a') && !str_contains((string) $sources, 'host-b'));

$wasT = getenv('PACELAB_TEST_PGDATABASE');
putenv('PACELAB_TEST_PGDATABASE=other_test');
same('the test database name comes from PACELAB_TEST_PGDATABASE before PGTESTDATABASE', 'other_test', lab_test_database());
$wasT === false ? putenv('PACELAB_TEST_PGDATABASE') : putenv('PACELAB_TEST_PGDATABASE=' . $wasT);

same('the driver defaults to sqlite with nothing set', 'sqlite', db_driver_from(null, null, '', null));
same('config.local.json databaseDriver pgsql switches the drive copy', 'pgsql', db_driver_from(null, null, 'pgsql', null));
same('a PACELAB_DRIVER line in .env cannot undo the switch in config.local.json', 'pgsql', db_driver_from(null, null, 'pgsql', 'sqlite'));
same('PACELAB_DRIVER from .env still decides when config.local.json is silent', 'pgsql', db_driver_from(null, null, '', 'pgsql'));
same('a PACELAB_DRIVER the server really sets outranks config.local.json', 'sqlite', db_driver_from(null, 'sqlite', 'pgsql', null));
same('the test harness outranks everything', 'sqlite', db_driver_from('sqlite', 'pgsql', 'pgsql', 'pgsql'));
same('anything but pgsql means sqlite', 'sqlite', db_driver_from(null, 'mysql', '', null));
same('this run uses the driver the harness chose', LAB_FORCE_DRIVER, db_driver());
refuses('config.local.json refuses a databaseDriver other than sqlite or pgsql',
    fn () => lab_config_save(['databaseDriver' => 'mysql']));

// The harness itself refuses a database whose name does not end in _test,
// before it connects. The child gets a port nothing listens on, so even a
// broken guard could not reach a server.
$childEnv = array_merge(getenv(), [
    'PACELAB_TEST_DRIVER' => 'pgsql', 'PACELAB_TEST_PGDATABASE' => 'pacelab',
    'PGHOST' => '127.0.0.1', 'PGPORT' => '1', 'PACELAB_ENV_FILE' => $pgd . '/missing.env',
]);
$proc = proc_open([PHP_BINARY, '-n', __FILE__], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT, $childEnv);
$childOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$childCode = proc_close($proc);
ok('the harness refuses a test database whose name does not end in _test, with exit code 2',
    $childCode === 2 && str_contains($childOut, 'Refusing to run'), 'exit ' . $childCode . ': ' . trim($childOut));

// Two SQLite files: one from schema.php (what install.php builds), one from
// the numbered migrations. They must agree table for table, column for column.
$fromSchema = $pgd . '/schema.sqlite';
$fromMig = $pgd . '/migrations.sqlite';
$sa = new PDO('sqlite:' . $fromSchema, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach (schema_statements() as $sql) {
    $sa->exec($sql);
}
$sb = new PDO('sqlite:' . $fromMig, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$applied = mig_apply($sb, 'sqlite');
same('the SQLite migrations apply to an empty file', ['0001', '0002', '0003'], $applied);
same('a second run applies nothing', [], mig_apply($sb, 'sqlite'));
$sqliteShape = static function (PDO $p): array {
    $out = [];
    $tables = $p->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_migrations' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); // dialect-ok: compares two SQLite scratch files
    foreach ($tables as $t) {
        foreach ($p->query('PRAGMA table_info("' . $t . '")') as $c) { // dialect-ok: compares two SQLite scratch files
            $out[$t][$c['name']] = strtoupper((string) $c['type']) . ($c['notnull'] ? ' NOT NULL' : '') . ($c['pk'] ? ' PK' : '');
        }
        ksort($out[$t]);
    }
    $out['(indexes)'] = $p->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); // dialect-ok: compares two SQLite scratch files
    return $out;
};
$shapeA = $sqliteShape($sa);
$shapeB = $sqliteShape($sb);
// One known difference on SQLite (owner, 2026-10-11, Q-07): the live database
// gained lab_id on equipment and export_batches through ALTER TABLE, so the
// column is nullable in the SQLite migrations, while schema.php declares it NOT
// NULL. Migration 0002 makes it required on PostgreSQL only; the owner left the
// SQLite file alone. Listed here so the check fails the day the difference grows
// or disappears unannounced.
$knownDrift = ['equipment.lab_id', 'export_batches.lab_id'];
$drift = [];
$diff = [];
foreach (array_unique(array_merge(array_keys($shapeA), array_keys($shapeB))) as $t) {
    if ($t === '(indexes)') {
        if ($shapeA[$t] !== $shapeB[$t]) {
            $diff[] = 'indexes: ' . implode(' ', array_diff($shapeA[$t], $shapeB[$t])) . ' / ' . implode(' ', array_diff($shapeB[$t], $shapeA[$t]));
        }
        continue;
    }
    foreach (array_unique(array_merge(array_keys($shapeA[$t] ?? []), array_keys($shapeB[$t] ?? []))) as $c) {
        $x = $shapeA[$t][$c] ?? 'missing';
        $y = $shapeB[$t][$c] ?? 'missing';
        if ($x === $y) {
            continue;
        }
        if (str_replace(' NOT NULL', '', $x) === str_replace(' NOT NULL', '', $y)) {
            $drift[] = "$t.$c";
        } else {
            $diff[] = "$t.$c ($x / $y)";
        }
    }
}
ok('schema.php and the SQLite migrations build the same tables, columns, types and indexes', $diff === [], implode(', ', $diff));
same('and differ in nullability only where open question Q-07 says', $knownDrift, $drift);

// A migration file that changes after it was applied stops the runner.
$migCopy = $pgd . '/migrations';
@mkdir($migCopy, 0777, true);
foreach (glob(mig_dir() . '/*.sql') as $f) {
    copy($f, $migCopy . '/' . basename($f));
}
file_put_contents($migCopy . '/0001_baseline.sqlite.sql', "\n-- edited after it ran\n", FILE_APPEND);
$st = mig_status($sb, 'sqlite', $migCopy);
ok('an applied migration that was edited afterwards is reported', count($st['problems']) === 1, implode('; ', $st['problems']));
refuses('and the runner refuses to run past it', fn () => mig_apply($sb, 'sqlite', $migCopy));
$sb = null;

if (db_driver() === 'pgsql') {
    // The PostgreSQL test database was built from the migrations at the top
    // of this run. Its columns must match schema.php's, type for type.
    $st = mig_status(db(), 'pgsql');
    ok('the PostgreSQL test database records every migration and has none pending',
        $st['pending'] === [] && $st['problems'] === [] && count($st['applied']) === count($st['found']));
    $typeMap = ['INTEGER' => 'integer', 'REAL' => 'double precision', 'TEXT' => 'text', 'DATE' => 'date'];
    $pgCols = [];
    foreach (db_all("SELECT table_name, column_name, data_type, is_nullable FROM information_schema.columns
                     WHERE table_schema = 'public' AND table_name <> 'schema_migrations'") as $r) {
        $pgCols[$r['table_name']][$r['column_name']] = $r['data_type'] . ($r['is_nullable'] === 'NO' ? ' NOT NULL' : '');
    }
    $mismatch = [];
    foreach ($sa->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) { // dialect-ok: reads the SQLite scratch file built from schema.php
        foreach ($sa->query('PRAGMA table_info("' . $t . '")') as $c) { // dialect-ok: reads the SQLite scratch file built from schema.php
            $want = ($typeMap[strtoupper((string) $c['type'])] ?? '?') . (($c['notnull'] || $c['pk']) ? ' NOT NULL' : '');
            $have = $pgCols[$t][$c['name']] ?? 'missing';
            $nullOnly = str_replace(' NOT NULL', '', $have) === str_replace(' NOT NULL', '', $want);
            if ($have !== $want && !($nullOnly && in_array("$t.{$c['name']}", $knownDrift, true))) {
                $mismatch[] = "$t.{$c['name']} ($have vs $want)";
            }
            unset($pgCols[$t][$c['name']]);
        }
        if (empty($pgCols[$t])) {
            unset($pgCols[$t]);
        }
    }
    foreach ($pgCols as $t => $cols) {
        foreach (array_keys($cols) as $c) {
            $mismatch[] = "$t.$c only in PostgreSQL";
        }
    }
    ok('the PostgreSQL migrations build every schema.php column with the same type and nullability (apart from Q-07)',
        $mismatch === [], implode(', ', $mismatch));
    $pgIdx = db_all("SELECT indexname FROM pg_indexes WHERE schemaname = 'public' AND left(indexname, 4) = 'idx_' ORDER BY indexname");
    same('and the same named indexes', array_values(array_filter($shapeA['(indexes)'], fn ($i) => str_starts_with($i, 'idx_'))),
        array_column($pgIdx, 'indexname'));
}
$sa = null;
$rm($pgd);

// Backups follow the driver. On PostgreSQL the SQLite file still exists and a
// VACUUM INTO copy of it would succeed nightly while saving nothing current.
$backupPs = (string) file_get_contents(APP_ROOT . '/tools/backup.ps1');
ok('tools/backup.ps1 hands a PostgreSQL installation to tools/pg-backup.php',
    str_contains($backupPs, 'pg-backup.php') && str_contains($backupPs, "-eq 'pgsql'"));
ok('and stops, rather than guess SQLite, when it cannot read the driver',
    (bool) preg_match('/brace -lt 0\) \{ throw /', $backupPs));
ok('the supervisor counts a PostgreSQL dump as today\'s backup',
    str_contains((string) file_get_contents(APP_ROOT . '/tools/serve.ps1'), "'.pgdump'"));

// PostgreSQL lives on the drive, never on the computer (platform D-028, D-013):
// the estate cluster on 127.0.0.1:5433, its client programs from the estate's
// runtime, and the launchers start and stop it.
$envPhp = (string) file_get_contents(APP_ROOT . '/includes/env.php');
ok('the PostgreSQL DSN defaults to the drive\'s cluster on port 5433',
    str_contains($envPhp, "getenv('PGPORT') ?: '5433'"));
$pgBackup = (string) file_get_contents(APP_ROOT . '/tools/pg-backup.php');
ok('tools/pg-backup.php takes pg_dump and pg_restore from the estate runtime and never from the computer\'s Program Files',
    str_contains($pgBackup, 'lab_estate_pg_bin()') && !preg_match('/ProgramFiles|Program Files/i', $pgBackup)
    && str_contains($pgBackup, "'5433'"));
$estateDir = lab_estate_dir();
ok('lab_estate_dir() names the folder holding .estate-live, or null outside the estate',
    $estateDir === null || is_file($estateDir . DIRECTORY_SEPARATOR . '.estate-live'), (string) $estateDir);
$epgPs = (string) file_get_contents(APP_ROOT . '/tools/estate-pg.ps1');
$epgWrong = [];
foreach (['estate-pg.js', '.estate-live', '$PgPort = 5433', "'127.0.0.1'", 'runtime\postgresql\17\bin', 'data\postgres',
          '-m fast', '-w -t 60', 'PG_VERSION', 'PGTZ', '--other-sessions'] as $needle) {
    if (!str_contains($epgPs, $needle)) {
        $epgWrong[] = "lacks $needle";
    }
}
if (preg_match('/5432|Program Files|ProgramFiles|postgresql-x64|\b[A-Za-z]:\\\\/i', $epgPs)) {
    $epgWrong[] = 'names the computer\'s PostgreSQL or a drive letter';
}
ok('tools/estate-pg.ps1 mirrors estate-pg.js (port 5433, the drive\'s data folder, a fast clean stop) and names no drive letter',
    $epgWrong === [], implode(', ', $epgWrong));
$serve = (string) @file_get_contents(APP_ROOT . '/tools/serve.ps1');
ok('tools/serve.ps1 starts the drive\'s cluster through estate-pg.ps1 and stops it after the server',
    str_contains($serve, "'estate-pg.ps1'") && str_contains($serve, "Invoke-EstatePg 'start'")
    && str_contains($serve, "Invoke-EstatePg 'stop'")
    && strpos($serve, "Invoke-EstatePg 'stop'") > strpos($serve, 'Stop-Process -Id $p.ProcessId'));
ok('nothing but tools/estate-pg.ps1 runs pg_ctl or estate-pg.js, and neither refuses to say why under an administrator account',
    !preg_match('/pg_ctl|estate-pg\.js/i', $serve)
    && str_contains($serve, 'administrator window') && str_contains($epgPs, 'administrator window'));

// One way to start the application (owner ruling 2026-11-03, skill
// drez-local-php-runner): SETUP.bat and the two launchers built from
// launcher\ and launcher-stop\, no other .bat, .cmd or .vbs at the root, and
// tools/serve.ps1 the one owner of the server.
$rootScripts = array_values(array_filter(scandir(APP_ROOT) ?: [],
    fn ($f) => preg_match('/\.(bat|cmd|vbs)$/i', $f) && strcasecmp($f, 'SETUP.bat') !== 0));
ok('the application folder holds no .bat, .cmd or .vbs other than SETUP.bat',
    $rootScripts === [], implode(', ', $rootScripts));
ok('tools/serve.ps1 exists, and the old launcher scripts it replaced are gone',
    $serve !== '' && !array_filter(['ensure-running.ps1', 'start-server.ps1', 'stop.ps1', 'supervisor.ps1'],
        fn ($f) => is_file(APP_ROOT . '/tools/' . $f)));
$launchIni = (string) @file_get_contents(APP_ROOT . '/launcher/launcher.ini');
$stopIni = (string) @file_get_contents(APP_ROOT . '/launcher-stop/launcher.ini');
ok('launcher.ini runs "{app}\tools\serve.ps1" start, and launcher-stop runs its stop',
    str_contains($launchIni, 'exe=PaCELab Equip.exe')
    && str_contains($launchIni, 'run=powershell -NoProfile -ExecutionPolicy Bypass -File "{app}\tools\serve.ps1" start')
    && str_contains($launchIni, 'require=tools\php\php.exe|') && str_contains($launchIni, 'require=config.local.json|')
    && str_contains($stopIni, '-File "{app}\tools\serve.ps1" stop'));
ok('tools/serve.ps1 binds 127.0.0.1, never localhost, and quotes the folder it hands to Start-Process',
    str_contains($serve, "'-S', \"127.0.0.1:\$Port\"") && !preg_match('/localhost:/i', $serve)
    && str_contains($serve, "'-t', ('\"' + \$root + '\"')") && str_contains($serve, "'-File', ('\"' + \$PSCommandPath + '\"')"));
// A folder check that compares spellings as text calls the estate's fixed
// folder and the drive-letter path two copies (incident 2026-11-03). Both the
// server check and the process matches go through Test-LabSameFolder.
$pathsPs = (string) file_get_contents(APP_ROOT . '/tools/paths.ps1');
preg_match('/function Compare-LabServerRoot.*?\n}/s', $pathsPs, $cmp);
ok('the folder-identity check compares file IDs, never the spelling of two paths',
    str_contains($pathsPs, 'GetFileInformationByHandle') && isset($cmp[0])
    && str_contains($cmp[0], 'Test-LabSameFolder') && !preg_match('/Equals|-eq|-like|OrdinalIgnoreCase|GetFullPath/i', $cmp[0])
    && substr_count($serve, 'Test-LabSameFolder') >= 2 && !preg_match('/-like\s+"\*\$root\*"/', $serve));
// Everything from the drive, nothing from the computer (owner, 2026-10-20 and
// 2026-11-03): no script reads APPDATA, LOCALAPPDATA or the Startup folder,
// falls back to a PHP installed on the computer, or puts scratch in the
// computer's temp folder. A logon shortcut is the owner's step (AGENTS.md).
$computerReads = '/\$env:(LOCAL)?APPDATA|%(LOCAL)?APPDATA%|process\.env\.(LOCAL)?APPDATA|getenv\(\s*[\'"](LOCAL)?APPDATA'
    . '|GetFolderPath\s*\(|shell:startup|Programs\\\\Startup|php83|GetTempPath|os\.tmpdir/i';
$computerHits = [];
foreach (array_merge(glob(APP_ROOT . '/tools/*.{ps1,js,php}', GLOB_BRACE) ?: [], glob(APP_ROOT . '/tests/*.ps1') ?: [],
                     glob(APP_ROOT . '/includes/*.php') ?: [], glob(APP_ROOT . '/*.php') ?: [], [APP_ROOT . '/SETUP.bat']) as $f) {
    if (is_file($f) && preg_match($computerReads, (string) file_get_contents($f), $hit)) {
        $computerHits[] = basename($f) . ' (' . $hit[0] . ')';
    }
}
ok('no script reads APPDATA, LOCALAPPDATA or the Startup folder, or uses a PHP or temp folder of the computer',
    $computerHits === [], implode(', ', $computerHits));
$showOut =shell_exec(escapeshellarg(PHP_BINARY) . ' ' . (php_ini_loaded_file() ? '-c ' . escapeshellarg((string) php_ini_loaded_file()) . ' ' : '')
    . '-d ' . escapeshellarg('extension_dir=' . ini_get('extension_dir')) . ' ' . escapeshellarg(APP_ROOT . '/tools/config.php') . ' show --json');
$show = json_decode(substr((string) $showOut, (int) strpos((string) $showOut, '{')), true);
ok('tools/config.php show reports the effective driver for the scripts that follow it',
    is_array($show) && in_array($show['driver'] ?? '', ['sqlite', 'pgsql'], true), (string) $showOut);

// Every include and every migration ships: install.php on PostgreSQL applies
// the migrations from the server's own copy.
$shipText = (string) file_get_contents(APP_ROOT . '/tools/build-release.ps1');
$unshipped = [];
foreach (array_merge(glob(APP_ROOT . '/includes/*.php') ?: [], glob(APP_ROOT . '/migrations/*.sql') ?: []) as $f) {
    $rel = basename(dirname($f)) . '\\' . basename($f);
    if (!str_contains($shipText, "'" . $rel . "'")) {
        $unshipped[] = $rel;
    }
}
ok('every include and every numbered migration is on the ship list', $unshipped === [], implode(', ', $unshipped));

// The built-in server's router (tools/router.php) refuses what Apache and IIS
// refuse through .htaccess and web.config. Without it, php -S hands out /.env.
require_once APP_ROOT . '/tools/router.php';
$mustDeny = ['/.env', '/.ENV', '/%2eenv', '/.git/config', '/data/lab.sqlite', '/DATA./lab.sqlite',
             '/tools/php/php.ini', '/migrations/0001_baseline.pgsql.sql', '/config.local.json',
             '/tests/run-tests.php', '/SETUP.bat', '/launcher/launcher.ini', '/admin/../.env', '/MIGRAT~1/x.sql', '/x.pgdump'];
$mustAllow = ['/', '/index.php', '/report.php?from=2026-01-01', '/admin/login.php', '/assets/style.css',
              '/assets/calendar.js', '/health.php?db=1', '/api.php'];
$wrong = array_merge(
    array_map(fn ($u) => "$u allowed", array_filter($mustDeny, fn ($u) => !lab_router_denies($u))),
    array_map(fn ($u) => "$u refused", array_filter($mustAllow, fn ($u) => lab_router_denies($u)))
);
ok('the built-in server refuses dotfiles, data, tools, tests and migrations, and serves the pages and assets',
    $wrong === [], implode(', ', $wrong));
ok('and the supervisor in tools/serve.ps1 starts it with that router',
    str_contains((string) file_get_contents(APP_ROOT . '/tools/serve.ps1'), "'router.php'"));

// ---------------------------------------------------------------------------
heading('NetID identity: the campus proxy says who, role grants say what (D-015)');

// Which mode, from where.
same('with nothing set the mode is password, as before', 'password', auth_mode_from(null, null, null, null));
same('the harness constant outranks everything', 'local', auth_mode_from('local', 'proxy', 'password', null));
same('a server\'s own environment outranks config.local.json', 'proxy', auth_mode_from(null, 'proxy', 'local', 'password'));
same('config.local.json outranks an environment file', 'local', auth_mode_from(null, null, 'local', 'proxy'));
same('a value that is not a mode fails closed to proxy, never open', 'proxy', auth_mode_from(null, 'pasword', null, null));
refuses('config.local.json refuses an authMode that is not a mode', fn () => lab_config_save(['authMode' => 'open']));
ok('the container image runs in the proxy mode unless its env file says otherwise',
    (bool) preg_match('/^ENV PACELAB_AUTH_MODE=proxy$/m', (string) file_get_contents(APP_ROOT . '/Dockerfile')));

// What the proxy says, read without touching the database.
$proxySet = ['identity_header' => 'X-Forwarded-Email', 'identity_domains' => 'illinois.edu',
             'groups_header' => 'X-Forwarded-Groups', 'trusted_proxies' => '', 'unlisted_role' => 'member'];
$who = auth_proxy_identity(['HTTP_X_FORWARDED_EMAIL' => ' configure-me@example.edu ',
    'HTTP_X_FORWARDED_GROUPS' => 'PRI-PaCE Staff, pri-pace-admins,,pri-pace-admins'], $proxySet);
same('a university address becomes its NetID, lower case', 'eruiz2', $who['netid'] ?? null);
same('the groups arrive lower case, trimmed, once each', ['pri-pace staff', 'pri-pace-admins'], $who['groups'] ?? null);
same('no identity header is 401', 401, auth_proxy_identity([], $proxySet)['status'] ?? null);
same('an address outside the university is 403', 403,
    auth_proxy_identity(['HTTP_X_FORWARDED_EMAIL' => 'someone@example.org'], $proxySet)['status'] ?? null);
same('an identity that is not a NetID is 403', 403,
    auth_proxy_identity(['HTTP_X_FORWARDED_EMAIL' => '<b>x</b>@illinois.edu'], $proxySet)['status'] ?? null);
same('a proxy that passes the bare NetID works through PACELAB_IDENTITY_HEADER', 'jdoe2',
    auth_proxy_identity(['HTTP_X_FORWARDED_USER' => 'jdoe2'], ['identity_header' => 'X-Forwarded-User'] + $proxySet)['netid'] ?? null);
same('PACELAB_GROUPS_HEADER=none ignores any groups header', [],
    auth_proxy_identity(['HTTP_X_FORWARDED_EMAIL' => 'configure-me@example.edu', 'HTTP_X_FORWARDED_GROUPS' => 'x'],
        ['groups_header' => 'none'] + $proxySet)['groups'] ?? null);
$trusted = ['trusted_proxies' => '10.20.0.0/16, 192.168.5.0/25'] + $proxySet;
ok('with PACELAB_TRUSTED_PROXIES a request from the proxy is read',
    isset(auth_proxy_identity(['REMOTE_ADDR' => '10.20.30.40', 'HTTP_X_FORWARDED_EMAIL' => 'configure-me@example.edu'], $trusted)['netid']));
same('and a request from anywhere else is 403 even with the header', 403,
    auth_proxy_identity(['REMOTE_ADDR' => '10.21.0.1', 'HTTP_X_FORWARDED_EMAIL' => 'configure-me@example.edu'], $trusted)['status'] ?? null);
ok('the address ranges are compared bit by bit',
    auth_ip_allowed('192.168.5.127', '192.168.5.0/25') && !auth_ip_allowed('192.168.5.128', '192.168.5.0/25')
    && auth_ip_allowed('fd00::1', 'fd00::/8') && !auth_ip_allowed('fe80::1', 'fd00::/8') && !auth_ip_allowed('garbage', '10.0.0.0/8'));

// The development sign-in: this computer only.
$loop = ['REMOTE_ADDR' => '127.0.0.1'];
same('the development sign-in runs in the local mode, over loopback, on the built-in server', null,
    auth_dev_login_problem($loop, 'local', 'cli-server'));
ok('and over IPv6 loopback', auth_dev_login_problem(['REMOTE_ADDR' => '::1'], 'local', 'cli-server') === null);
ok('it is refused in the proxy mode', auth_dev_login_problem($loop, 'proxy', 'cli-server') !== null);
ok('and in the password mode', auth_dev_login_problem($loop, 'password', 'cli-server') !== null);
ok('and on any request that came through a proxy',
    auth_dev_login_problem($loop + ['HTTP_X_FORWARDED_FOR' => '10.1.1.1'], 'local', 'cli-server') !== null
    && auth_dev_login_problem($loop + ['HTTP_X_FORWARDED_EMAIL' => 'configure-me@example.edu'], 'local', 'cli-server') !== null);
ok('and under Apache, which is what Radiant runs', auth_dev_login_problem($loop, 'local', 'apache2handler') !== null);
ok('and from another computer', auth_dev_login_problem(['REMOTE_ADDR' => '192.168.1.20'], 'local', 'cli-server') !== null);

// Role grants in the database, through the proxy mode.
$asProxy = function (string $email, string $groups = ''): void {
    unset($_SERVER['HTTP_X_FORWARDED_EMAIL'], $_SERVER['HTTP_X_FORWARDED_GROUPS'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    if ($email !== '') {
        $_SERVER['HTTP_X_FORWARDED_EMAIL'] = $email;
    }
    if ($groups !== '') {
        $_SERVER['HTTP_X_FORWARDED_GROUPS'] = $groups;
    }
    unset($_SESSION['lab_id']);
    auth_principal(true);
};
auth_mode('proxy');
ok('migration 0003 built role_grants here', roles_table_ready());
$_SESSION['admin_user_id'] = 1;             // a password-mode session left behind
$_SESSION['admin_last_seen'] = time();
$asProxy('configure-me@example.edu');
same('the NetID is the identity', 'nobody1', current_user_name());
ok('nobody types a name any more', !identity_is_self_declared());
ok('a password-mode session does not make a NetID an administrator', !is_admin());
ok('a password sign-in is refused', str_contains((string) admin_login('root', 'a-long-enough-password'), 'NetID'));
set_current_user_name('Somebody Else');
same('and a typed name cannot replace the NetID', 'nobody1', current_user_name());
$asProxy('');
ok('without the proxy header nobody is signed in', auth_principal() === null && auth_problem()[1] === 401 && !is_admin());

$gAdmin = role_grant('netid', 'padmin', 'admin', null, 'seeded', ROLE_CONSOLE);
$asProxy('configure-me@example.edu');
ok('an admin grant on the NetID opens the administrative panel', is_admin());
same('and stamps records with the NetID', 'padmin', admin_display_name());
ok('but not the Studio', !auth_may_edit_code() && !can_edit_code());
refuses('nobody grants code editing to themselves',
    fn () => role_grant('netid', 'padmin', 'code_editor', null, '', 'padmin'));
$gCode = role_grant('netid', 'padmin', 'code_editor', null, '', 'otheradmin');
auth_principal(true);
ok('another administrator can grant it, and then the Studio opens', auth_may_edit_code() && can_edit_code());
refuses('the same grant twice is refused', fn () => role_grant('netid', 'padmin', 'admin', null, '', ROLE_CONSOLE));
refuses('only a member grant names a laboratory', fn () => role_grant('netid', 'padmin', 'admin', $labA, '', ROLE_CONSOLE));
refuses('a role that does not exist is refused', fn () => role_grant('netid', 'x1', 'superuser', null, '', ROLE_CONSOLE));
refuses('and the database refuses one written past the code',
    fn () => db_run('INSERT INTO role_grants (principal_kind, principal, role, granted_at) VALUES (?, ?, ?, ?)',
        ['netid', 'x1', 'superuser', date('Y-m-d H:i:s')]));
refuses('as it refuses a principal in capitals',
    fn () => db_run('INSERT INTO role_grants (principal_kind, principal, role, granted_at) VALUES (?, ?, ?, ?)',
        ['netid', 'X1', 'admin', date('Y-m-d H:i:s')]));

$gGroup = role_grant('group', 'pri-pace-admins', 'admin', null, '', ROLE_CONSOLE);
$asProxy('configure-me@example.edu', 'Other Group, PRI-PACE-ADMINS');
ok('an admin grant on a campus group makes its members administrators', is_admin());
$asProxy('configure-me@example.edu');
ok('the same NetID without the group is not one', !is_admin());
refuses('nobody grants code editing to a group they belong to',
    fn () => role_grant('group', 'pri-pace-admins', 'code_editor', null, '', 'gadmin', ['pri-pace-admins']));

role_grant('group', 'alpha-lab', 'member', $labA, '', ROLE_CONSOLE);
$asProxy('configure-me@example.edu', 'alpha-lab');
same('a member grant on a group puts its people in that laboratory', [$labA],
    array_map('intval', array_column(labs_for_person(), 'lab_id')));
same('and that laboratory is in view', $labA, current_lab_id());
ok('they still cannot step into the other laboratory', !may_use_lab($labB));
ok('or reach its instrument by id', equipment_by_id($cytoB) === null && equipment_by_id($icpA) !== null);
ok('or its grant', grant_by_id($grantB) === null && grant_by_id($grantA) !== null);
ok('and a member is not an administrator', !is_admin());
$asProxy('configure-me@example.edu');
ok('lab_members keyed by NetID still decides membership', may_use_lab($labA) && may_use_lab($labB));
$asProxy('configure-me@example.edu');
same('a NetID in no laboratory sees none', 0, count(labs_for_person()));
$p = auth_principal();
ok('PACELAB_UNLISTED_ROLE=member lets that NetID in to see nothing, as a typed name could',
    !auth_unlisted_refused($p, 'member'));
ok('PACELAB_UNLISTED_ROLE=none turns it away', auth_unlisted_refused($p, 'none'));
$asProxy('configure-me@example.edu', 'alpha-lab');
ok('but not someone a group grant names', !auth_unlisted_refused(auth_principal(), 'none'));
$asProxy('configure-me@example.edu');
ok('nor someone listed in a laboratory', !auth_unlisted_refused(auth_principal(), 'none'));

// Revoking keeps the row and the panel.
role_revoke($gGroup, 'padmin');
$asProxy('configure-me@example.edu', 'pri-pace-admins');
ok('a revoked group grant stops working', !is_admin());
ok('and the row stays, stamped', role_grant_by_id($gGroup)['revoked_by'] === 'padmin');
refuses('nobody revokes a grant naming themselves from a browser', fn () => role_revoke($gAdmin, 'padmin'));
role_revoke($gCode, 'otheradmin');
refuses('nobody revokes the last grant that opens the panel', fn () => role_revoke($gAdmin, 'otheradmin'));
same('which still holds', 1, roles_admin_grant_count());

// The development sign-in, end to end.
define('LAB_TEST_SAPI', 'cli-server');
auth_mode('local');
unset($_SESSION['dev_netid'], $_SESSION['dev_last_seen']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
unset($_SERVER['HTTP_X_FORWARDED_EMAIL'], $_SERVER['HTTP_X_FORWARDED_GROUPS'], $_SERVER['HTTP_X_FORWARDED_FOR']);
auth_principal(true);
ok('the local mode starts signed out', auth_principal() === null && auth_problem()[1] === 401);
same('the development sign-in takes a NetID', null, dev_login('PAdmin'));
ok('and then the grants apply exactly as behind the proxy', current_user_name() === 'padmin' && is_admin());
$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.1.1.1';
auth_principal(true);
ok('the same session through a proxy is refused', auth_principal() === null && !is_admin() && auth_problem()[1] === 403);
ok('and cannot sign in again', dev_login('padmin') !== null);
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SESSION['dev_last_seen'] = time() - ADMIN_IDLE_TIMEOUT - 5;
auth_principal(true);
ok('thirty idle minutes end the development session too', auth_principal() === null && empty($_SESSION['dev_netid']));

// The owner's command, on a scratch SQLite file.
$rolesDb = $tempRoot . DIRECTORY_SEPARATOR . 'roles-cli-' . getmypid() . '.sqlite';
@unlink($rolesDb);
$rp = new PDO('sqlite:' . $rolesDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (schema_statements() as $sql) {
    $rp->exec($sql);
}
$rp->exec("INSERT INTO labs (code, name, created_at) VALUES ('AAA', 'Alpha Lab', '2026-10-12 00:00:00')"); // db-calls: allow literal fixture on the scratch file
$rp->exec("INSERT INTO lab_members (lab_id, person_key, display_name, created_at) VALUES (1, 'ruiz', 'Elena Ruiz', '2026-10-12 00:00:00')"); // db-calls: allow literal fixture on the scratch file
$rp = null;
$rolesCli = function (array $args) use ($rolesDb): array {
    $cmd = [PHP_BINARY];
    if (php_ini_loaded_file()) {
        array_push($cmd, '-c', php_ini_loaded_file(), '-d', 'extension_dir=' . ini_get('extension_dir'));
    }
    $cmd = array_merge($cmd, [APP_ROOT . '/tools/roles.php'], $args);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, APP_ROOT);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out];
};
[$c1, $o1] = $rolesCli(['--db', $rolesDb, 'grant', 'group:PRI-PaCE-Admins', 'admin', '--note', 'seed']);
[$c2, $o2] = $rolesCli(['--db', $rolesDb, 'grant', 'eruiz2', 'member', '--lab', 'aaa']);
[$c3, $o3] = $rolesCli(['--db', $rolesDb, 'list']);
ok('tools/roles.php seeds a group grant and a laboratory member grant',
    $c1 === 0 && $c2 === 0 && $c3 === 0 && str_contains($o3, 'group:pri-pace-admins') && str_contains($o3, 'AAA'), trim($o1 . $o2 . $o3));
[$c4, $o4] = $rolesCli(['--db', $rolesDb, 'revoke', '1']);
[$c5, $o5] = $rolesCli(['--db', $rolesDb, 'list']);
ok('revokes from the console, and the list drops it', $c4 === 0 && !str_contains($o5, 'pri-pace-admins'), trim($o4 . $o5));
[$c6, $o6] = $rolesCli(['--db', $rolesDb, 'rekey', 'AAA', 'ruiz', 'eruiz2']);
[$c7, $o7] = $rolesCli(['--db', $rolesDb, 'members']);
ok('rekey turns a typed member name into a NetID', $c6 === 0 && str_contains($o7, 'eruiz2') && !str_contains($o7, ' ruiz '), trim($o6 . $o7));
[$c8, $o8] = $rolesCli(['--db', $rolesDb, 'grant', 'Not A NetID', 'admin']);
ok('a principal that is neither a NetID nor a group is refused', $c8 === 1 && str_contains($o8, 'Refused'), trim($o8));
[$c9, $o9] = $rolesCli(['--pg', 'live', 'grant', 'someone', 'admin']);
ok('writing grants in the live database stops without --owner-approved, before connecting',
    $c9 !== 0 && (str_contains($o9, 'Stop and ask') || str_contains($o9, 'No PostgreSQL credentials')), trim($o9));
@unlink($rolesDb);

// A fresh seed yields exactly one superadmin, the owner (D-016).
$rp = new PDO('sqlite:' . $rolesDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach (schema_statements() as $sql) {
    $rp->exec($sql);
}
$rp = null;
[$s1, $so1] = $rolesCli(['--db', $rolesDb, 'seed']);
[$s2, $so2] = $rolesCli(['--db', $rolesDb, 'seed']);
$rp = new PDO('sqlite:' . $rolesDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$seeded = $rp->query('SELECT principal_kind, principal, role, lab_id, revoked_at FROM role_grants')->fetchAll(PDO::FETCH_ASSOC);
$rp = null;
ok('a fresh seed yields exactly one grant: dreslik as code_editor, the superadmin',
    $s1 === 0 && count($seeded) === 1 && $seeded[0]['principal_kind'] === 'netid' && $seeded[0]['principal'] === 'dreslik'
    && $seeded[0]['role'] === 'code_editor' && $seeded[0]['lab_id'] === null && $seeded[0]['revoked_at'] === null,
    trim($so1) . ' ' . json_encode($seeded));
ok('seeding twice changes nothing and says so', $s2 === 0 && str_contains($so2, 'Already in force'), trim($so2));
ok('the owner constants name the NetID and email the owner gave',
    ROLE_OWNER_NETID === 'dreslik' && ROLE_OWNER_EMAIL === 'configure-me@example.edu' && ROLE_OWNER_ROLE === 'code_editor');
@unlink($rolesDb);

// Back to the password mode for anything after this section.
unset($_SESSION['dev_netid'], $_SESSION['dev_last_seen'], $_SESSION['admin_user_id'], $_SESSION['admin_last_seen']);
auth_mode('password');
ok('the password mode comes back unchanged', identity_is_self_declared() && auth_principal() === null);

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------

echo PHP_EOL, str_repeat('=', 64), PHP_EOL;

if ($failed) {
    echo count($failed), ' FAILED, ', $passed, ' passed', PHP_EOL, PHP_EOL;
    foreach ($failed as $line) {
        echo '  - ', $line, PHP_EOL;
    }
    echo PHP_EOL;
} else {
    echo 'All ', $passed, ' checks passed.', PHP_EOL, PHP_EOL;
}

// Windows keeps the file locked while the handle is open, so this may not
// succeed; the next run clears whatever is left behind.
@unlink($tempDb);
@unlink($tempDb . '-wal');
@unlink($tempDb . '-shm');

exit($failed ? 1 : 0);
