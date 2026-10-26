<?php
/**
 * tools/pg-backup.php: backups and the restore drill on PostgreSQL.
 *
 *   php tools/pg-backup.php                   pg_dump -Fc of PGDATABASE into the backup folder,
 *                                             listed, restored into the test database and counted
 *   php tools/pg-backup.php --restore-drill   restore the newest dump into the test database and
 *                                             compare its row counts with the live database
 *   --dest <folder>   write the dump, and the log line, somewhere other than the backup
 *                     folder and the data folder (rehearsals that must not touch live folders)
 *   --keep-days N     prune dumps older than N days (default 60, as tools/backup.ps1)
 *
 * tools/backup.ps1 calls this when the application runs on PostgreSQL, so the
 * supervisor's daily backup and BACK UP NOW.bat follow the driver. A backup is
 * kept only after it has been restored and counted; a dump nobody restored is
 * a guess (pri-radiant-migration, drez-verified-backups).
 *
 * The restore target is always the test database (its name must end in
 * _test): it is emptied first, so the drill never touches the live one.
 * pg_dump and pg_restore come from the estate's runtime on the drive
 * (<estate>\runtime\postgresql\17\bin, platform D-028), then PACELAB_PG_BIN,
 * then PATH on a server that is not Windows. The password reaches them through their
 * environment, never the command line. The log line goes to backup.log in the
 * data folder, beside the SQLite backup lines.
 *
 * Exit codes: 0 kept (or drill passed), 1 rejected or failed, 2 usage.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/db.php';
require APP_ROOT . '/includes/db_migrations.php';

$opts = getopt('', ['restore-drill', 'dest:', 'keep-days:']);
$keepDays = (int) ($opts['keep-days'] ?? 60);
$log = isset($opts['dest'])
    ? rtrim((string) $opts['dest'], '\/') . DIRECTORY_SEPARATOR . 'backup.log'
    : lab_data_dir() . DIRECTORY_SEPARATOR . 'backup.log';
$note = function (string $m) use ($log): void {
    $line = date('Y-m-d H:i:s') . '  ' . $m;
    if (is_dir(dirname($log))) {
        @file_put_contents($log, $line . PHP_EOL, FILE_APPEND);
    }
    echo '  ', $m, PHP_EOL;
};
$fail = function (string $m, int $code = 1) use ($note): never { $note($m); exit($code); };

if (!lab_pg_credentials_present()) {
    $fail('PostgreSQL backup: no credentials (PGUSER and PGPASSWORD from pg.env beside the data folder, or .env).', 2);
}

/** Find a PostgreSQL client program. */
function pg_tool(string $name): string
{
    $exe = PHP_OS_FAMILY === 'Windows' ? $name . '.exe' : $name;
    $dirs = [];
    // The estate's runtime on the drive comes first, so the client always
    // matches the drive's cluster, and a PostgreSQL installed on the computer
    // is never used (platform D-028, D-013).
    if (($estateBin = lab_estate_pg_bin()) !== null) {
        $dirs[] = $estateBin;
    }
    // A copy outside the estate (a hosted server) names its folder here.
    if (($b = getenv('PACELAB_PG_BIN')) !== false && $b !== '') {
        $dirs[] = $b;
    }
    // PATH only off Windows (a hosted server such as Radiant). On Windows a
    // PATH entry would reach a PostgreSQL installed on the computer.
    if (PHP_OS_FAMILY !== 'Windows') {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $p) {
            if ($p !== '') {
                $dirs[] = $p;
            }
        }
    }
    foreach ($dirs as $d) {
        $f = rtrim($d, '\\/') . DIRECTORY_SEPARATOR . $exe;
        if (is_file($f)) {
            return $f;
        }
    }
    throw new RuntimeException("$name not found in the estate's PostgreSQL runtime on the drive (runtime/postgresql/17/bin); outside the estate set PACELAB_PG_BIN to the folder holding it");
}

/** Run a PostgreSQL client with the PG* settings in its environment; returns [exit code, output]. */
function pg_run(array $cmd, string $database): array
{
    $env = getenv();
    foreach (['PGHOST', 'PGPORT', 'PGUSER', 'PGPASSWORD'] as $k) {
        if (($v = getenv($k)) !== false) {
            $env[$k] = $v;
        }
    }
    // The same defaults as lab_pg_dsn(): the drive's cluster on 127.0.0.1:5433.
    // Without them libpq would fall back to its built-in default port, used by a
    // PostgreSQL some computers have installed (platform D-028).
    $env['PGHOST'] = ($env['PGHOST'] ?? '') !== '' ? $env['PGHOST'] : '127.0.0.1';
    $env['PGPORT'] = ($env['PGPORT'] ?? '') !== '' ? $env['PGPORT'] : '5433';
    $env['PGDATABASE'] = $database;
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) {
        return [1, 'could not start ' . basename($cmd[0])];
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out];
}

/** Row counts per table in schema public, as "t=n t=n". */
function pg_counts(PDO $pdo): string
{
    $parts = [];
    foreach (array_keys(mig_pg_tables($pdo)) as $t) {
        if ($t === 'schema_migrations') {
            continue;
        }
        $parts[] = $t . '=' . $pdo->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $t) . '"')->fetchColumn(); // db-calls: allow table names come from pg_tables, quoted
    }
    return implode(' ', $parts);
}

/** Restore a dump into the emptied test database and return its counts. */
function restore_into_test(string $dump, callable $fail): string
{
    $test = lab_test_database();
    if (!str_ends_with($test, '_test')) {
        $fail("Refusing: the restore target '$test' does not end in _test.", 2);
    }
    $pdo = db_pg_connect($test);
    mig_pg_reset_test_database($pdo);
    [$code, $out] = pg_run([pg_tool('pg_restore'), '--no-owner', '--no-privileges', '--exit-on-error', '-d', $test, $dump], $test);
    if ($code !== 0) {
        $fail('RESTORE FAILED into ' . $test . ': ' . trim(substr($out, 0, 400)));
    }
    return pg_counts($pdo);
}

$liveName = getenv('PGDATABASE') ?: 'pacelab';
$sourceName = $liveName;
$dest = (string) ($opts['dest'] ?? lab_backup_dir());

try {
    if (isset($opts['restore-drill'])) {
        $newest = null;
        foreach (glob($dest . DIRECTORY_SEPARATOR . 'lab-*.pgdump') ?: [] as $f) {
            if ($newest === null || filemtime($f) > filemtime($newest)) {
                $newest = $f;
            }
        }
        if ($newest === null) {
            $fail('No PostgreSQL backup (lab-*.pgdump) in ' . $dest);
        }
        $note('restore drill using ' . basename($newest));
        $live = pg_counts(db_pg_connect($liveName));
        $back = restore_into_test($newest, $fail);
        $note('live    : ' . $live);
        $note('restored: ' . $back);
        if ($live === $back) {
            $note('RESTORE DRILL PASSED - the backup holds the same rows as the live database');
            exit(0);
        }
        $fail('RESTORE DRILL FAILED - the counts differ. Do not trust this backup.');
    }

    // --- take one -----------------------------------------------------------
    if (!is_dir($dest)) {
        // The folder itself, never a chain: a missing parent means the drive
        // is not connected or the path is stale (drive-invariance R3).
        if (!is_dir(dirname($dest)) || !@mkdir($dest, 0770)) {
            $fail('Backup folder not found at ' . $dest . ', and its parent folder is missing too.');
        }
    }
    $out = $dest . DIRECTORY_SEPARATOR . 'lab-' . date('Y-m-d-Hi') . '.pgdump';
    @unlink($out);
    [$code, $msg] = pg_run([pg_tool('pg_dump'), '--format=custom', '--no-owner', '--no-privileges', '--file=' . $out, $sourceName], $sourceName);
    if ($code !== 0 || !is_file($out)) {
        @unlink($out);
        $fail('BACKUP FAILED - pg_dump: ' . trim(substr($msg, 0, 400)));
    }
    [$code, $list] = pg_run([pg_tool('pg_restore'), '--list', $out], $sourceName);
    $dataItems = preg_match_all('/ TABLE DATA public /', $list);
    if ($code !== 0 || $dataItems === 0) {
        @unlink($out);
        $fail('BACKUP REJECTED - the archive does not list its table data (pg_restore --list exit ' . $code . ').');
    }
    $live = pg_counts(db_pg_connect($sourceName));
    $back = restore_into_test($out, $fail);
    if ($live !== $back) {
        @unlink($out);
        $fail('BACKUP REJECTED - counts differ after a restore. live=' . $live . ' restored=' . $back);
    }
    $kb = (int) round(filesize($out) / 1024);
    $note('backup ok: ' . $out . ' (' . $kb . ' KB, ' . $dataItems . ' tables of data, restored into ' . lab_test_database() . ' and counted) - ' . $live);

    // --- prune --------------------------------------------------------------
    $cut = time() - $keepDays * 86400;
    $pruned = 0;
    foreach (glob($dest . DIRECTORY_SEPARATOR . 'lab-*.pgdump') ?: [] as $f) {
        if (filemtime($f) < $cut && @unlink($f)) {
            $pruned++;
        }
    }
    if ($pruned) {
        $note("pruned $pruned PostgreSQL backup(s) older than $keepDays days");
    }
    exit(0);
} catch (Throwable $e) {
    $fail('PostgreSQL backup failed: ' . ($e instanceof PDOException ? db_pg_error_kind($e) : $e->getMessage()));
}
