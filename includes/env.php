<?php
/**
 * env.php: where the PostgreSQL connection settings come from (platform D-027).
 *
 * The application reads the standard libpq names and nothing else:
 *     PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD   the connection
 *     PGTESTDATABASE                                   the throwaway test database
 *     PACELAB_DRIVER                                   sqlite or pgsql (optional)
 *
 * They are taken from the first place that sets each one:
 *   1. the real environment (a server's service file, compose env_file);
 *   2. the file PACELAB_ENV_FILE names, when set (a worktree or a script that
 *      must use another copy's credentials without copying them);
 *   3. pg.env beside the data folder: <estate>\data\pacelab-equip\pg.env on
 *      the drive, found from config.local.json dataFolder, never from a drive
 *      letter. platform\scripts\pg-provision.ps1 writes it;
 *   4. .env beside index.php, the older place (keys standard exception 4).
 *
 * A value already set wins over every later source, so a server that sets
 * PGPASSWORD properly is never overridden by a stale file. Nothing here prints,
 * logs or returns a value; lab_env_sources() reports only which files exist.
 *
 * Standalone, like local-config.php: the test harness needs it before db.php
 * runs, because LAB_DB_PATH must be defined first.
 */

declare(strict_types=1);

require_once __DIR__ . '/local-config.php';

/** The names this application reads from an environment file. Others are ignored. */
const LAB_ENV_NAMES = [
    'PACELAB_DRIVER', 'PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD',
    'PGTESTDATABASE', 'PACELAB_TEST_DRIVER', 'PACELAB_TEST_PGDATABASE', 'PACELAB_PG_BIN',
    // Identity (D-015, includes/auth.php). None of these is a secret.
    'PACELAB_AUTH_MODE', 'PACELAB_IDENTITY_HEADER', 'PACELAB_IDENTITY_DOMAINS',
    'PACELAB_GROUPS_HEADER', 'PACELAB_TRUSTED_PROXIES', 'PACELAB_UNLISTED_ROLE',
];

/**
 * The environment files, in the order they are read. Each entry is
 * [label, path]; a path may not exist.
 */
function lab_env_files(): array
{
    $files = [];
    $explicit = getenv('PACELAB_ENV_FILE');
    if (is_string($explicit) && trim($explicit) !== '') {
        $files[] = ['PACELAB_ENV_FILE', trim($explicit)];
    }
    if (lab_data_folder_configured()) {
        $files[] = ['pg.env beside the data folder', dirname(lab_data_dir()) . DIRECTORY_SEPARATOR . 'pg.env'];
    }
    $files[] = ['.env beside index.php', APP_ROOT . DIRECTORY_SEPARATOR . '.env'];
    return $files;
}

/** Parse one KEY=VALUE file into an array of the names in LAB_ENV_NAMES. */
function lab_env_parse(string $path): array
{
    $out = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $out;
    }
    foreach ($lines as $line) {
        $line = trim(preg_replace('/^\xEF\xBB\xBF/', '', $line));
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if (str_starts_with($k, 'export ')) {
            $k = trim(substr($k, 7));
        }
        $v = trim($v);
        if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if (in_array($k, LAB_ENV_NAMES, true) && $v !== '') {
            $out[$k] = $v;
        }
    }
    return $out;
}

/**
 * Read the environment files into the process environment, once. A name the
 * real environment (or an earlier file) already set is left alone.
 */
function lab_load_env(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    foreach (lab_env_collect(array_column(lab_env_files(), 1)) as $k => $v) {
        if (getenv($k) === false) {
            putenv($k . '=' . $v);
            lab_env_file_names($k);
        }
    }
}

/**
 * The names lab_load_env() took from a file rather than the real environment.
 * db_driver() needs the difference: config.local.json databaseDriver outranks
 * a PACELAB_DRIVER line in .env (the owner's switch must not be silently
 * undone by an old file) but not a PACELAB_DRIVER the server really sets.
 */
function lab_env_file_names(?string $add = null): array
{
    static $names = [];
    if ($add !== null) {
        $names[$add] = true;
    }
    return array_keys($names);
}

/**
 * The names the given files supply, the first file that sets a name winning.
 * Missing files are skipped. Pure apart from reading the files, so the tests
 * can prove the order without touching the process environment.
 */
function lab_env_collect(array $paths): array
{
    $out = [];
    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $out += lab_env_parse($path);
    }
    return $out;
}

/**
 * Which environment files exist, and which names each would supply, without
 * a single value. For tools/pg-status.php and the gate.
 */
function lab_env_sources(): array
{
    $out = [];
    foreach (lab_env_files() as [$label, $path]) {
        $out[] = [
            'source' => $label,
            'path'   => $path,
            'exists' => is_readable($path),
            'names'  => is_readable($path) ? array_keys(lab_env_parse($path)) : [],
        ];
    }
    return $out;
}

/** The test database name: PACELAB_TEST_PGDATABASE, then PGTESTDATABASE, then pacelab_test. */
function lab_test_database(): string
{
    lab_load_env();
    foreach (['PACELAB_TEST_PGDATABASE', 'PGTESTDATABASE'] as $k) {
        $v = getenv($k);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
    }
    return 'pacelab_test';
}

/**
 * The PDO DSN for PostgreSQL from the PG* names. $database overrides
 * PGDATABASE (the test harness and the tools pass the test database here).
 */
function lab_pg_dsn(?string $database = null): string
{
    lab_load_env();
    return sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv('PGHOST') ?: '127.0.0.1',
        getenv('PGPORT') ?: '5433',
        $database ?? (getenv('PGDATABASE') ?: 'pacelab')
    );
}

/** True when PGUSER and PGPASSWORD are both available from some source. */
function lab_pg_credentials_present(): bool
{
    lab_load_env();
    return (getenv('PGUSER') ?: '') !== '' && (getenv('PGPASSWORD') ?: '') !== '';
}
