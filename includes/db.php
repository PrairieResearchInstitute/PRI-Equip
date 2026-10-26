<?php
/**
 * db.php — Database connection.
 *
 * Every query in the application runs through the PDO handle returned by db()
 * and uses prepared statements. Nothing concatenates a value into SQL.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// The database file normally sits in data/ next to the application. A host that
// allows storage outside the web root is the safer arrangement: create
// config.php beside index.php containing
//     <?php define('LAB_DB_PATH', '/home/account/private/lab.sqlite');
// and this file will use that path instead. See the README.
if (file_exists(APP_ROOT . '/config.php')) {
    require_once APP_ROOT . '/config.php';
}
// config.local.json (the portable-mode loader) may move the data folder out of
// the application folder: on the drive it names ..\..\data\pacelab-equip\data.
// Without it the database stays in data/ as before.
require_once __DIR__ . '/local-config.php';
require_once __DIR__ . '/env.php';
if (!defined('LAB_DB_PATH')) {
    define('LAB_DB_PATH', lab_data_folder_configured()
        ? lab_data_dir() . DIRECTORY_SEPARATOR . 'lab.sqlite'
        : APP_ROOT . '/data/lab.sqlite');
}

/**
 * The PostgreSQL settings (PG* names) come from the environment, pg.env beside
 * the data folder or .env beside index.php; includes/env.php reads them.
 * Kept under its old name because older notes and tools call it.
 */
function load_dot_env(): void
{
    lab_load_env();
}

/**
 * Which driver this installation uses: 'sqlite' (the default) or 'pgsql'.
 *
 * In order: LAB_FORCE_DRIVER, a constant only the test harness defines;
 * PACELAB_DRIVER set in the real environment (a server's service file);
 * databaseDriver in config.local.json, which is how the drive copy is switched
 * (php tools/config.php set databaseDriver pgsql); PACELAB_DRIVER from an
 * environment file (.env, pg.env). Anything other than pgsql means SQLite, so
 * an installation that has not been migrated behaves exactly as before. Read
 * once so a page cannot end up talking to two different databases.
 */
function db_driver(): string
{
    static $driver = null;
    if ($driver === null) {
        lab_load_env();
        $env = getenv('PACELAB_DRIVER');
        $driver = db_driver_from(
            defined('LAB_FORCE_DRIVER') ? (string) LAB_FORCE_DRIVER : null,
            in_array('PACELAB_DRIVER', lab_env_file_names(), true) ? null : ($env === false ? null : $env),
            (string) (lab_config()['databaseDriver'] ?? ''),
            in_array('PACELAB_DRIVER', lab_env_file_names(), true) ? (string) $env : null
        );
    }
    return $driver;
}

/** The driver rule itself, without the process state, so the tests can prove the order. */
function db_driver_from(?string $forced, ?string $realEnv, ?string $config, ?string $fileEnv): string
{
    foreach ([$forced, $realEnv, $config, $fileEnv] as $choice) {
        if ($choice !== null && trim($choice) !== '') {
            return strtolower(trim($choice)) === 'pgsql' ? 'pgsql' : 'sqlite';
        }
    }
    return 'sqlite';
}

/**
 * A PostgreSQL connection from the PG* names. $database overrides PGDATABASE
 * (the tests and tools pass the test database here). Throws on failure; a
 * caller must never show the exception text to a browser, because it can name
 * the host and the user.
 */
function db_pg_connect(?string $database = null): PDO
{
    lab_load_env();
    return new PDO(lab_pg_dsn($database), getenv('PGUSER') ?: '', getenv('PGPASSWORD') ?: '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/**
 * A connection failure as a category a person can act on, without the host,
 * the user or any other detail from the driver's message. For the command-line
 * tools; a browser never sees even this.
 */
function db_pg_error_kind(Throwable $e): string
{
    $m = strtolower($e->getMessage());
    $kinds = [
        'password authentication failed' => 'the password was refused (PGPASSWORD does not match the role)',
        'no password supplied'           => 'no password was supplied (PGPASSWORD is empty)',
        'role "'                         => 'the role does not exist (PGUSER)',
        'database "'                     => 'the database does not exist',
        'connection refused'             => 'nothing answered at PGHOST:PGPORT (is PostgreSQL on the drive running? START HERE.bat starts it)',
        'could not translate host'       => 'PGHOST does not resolve',
        'timeout'                        => 'the connection timed out',
        'permission denied'              => 'the role may not connect to this database',
        'could not find driver'          => 'PHP has no pdo_pgsql driver (enable extension=pdo_pgsql)',
        'no pg_hba.conf entry'           => 'the server refuses this host, user or database in pg_hba.conf',
    ];
    foreach ($kinds as $needle => $kind) {
        if (str_contains($m, $needle)) {
            return $kind;
        }
    }
    return 'unrecognised error (' . get_class($e) . ', SQLSTATE ' . (string) $e->getCode() . ')';
}

/**
 * Stop the request with a data-folder message (drive-invariance R4): HTTP 500
 * and the message for a browser, the message on STDERR and exit code 1 for a
 * command-line tool.
 */
function db_refuse(string $message): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

/**
 * The shared PDO handle. Opens the database on first call.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Never open an empty database in a folder that should have held the real
    // one (drive-invariance R4): lab_data_dir_problem() refuses a missing data
    // folder whose parent is missing too, and an installed folder that lost
    // lab.sqlite; on a genuine first run it creates the one leaf folder.
    $dir = dirname(LAB_DB_PATH);
    if (db_driver() === 'sqlite') {
        $problem = lab_data_dir_problem(LAB_DB_PATH);
        if ($problem !== null) {
            db_refuse($problem);
        }
    }
    // A configured data folder carries no tracked .htaccess, so write the web
    // denials beside the database when they are missing (install.php writes
    // the same two files). Only for the configured folder: a test database or
    // a config.php path is left alone.
    if (lab_data_folder_configured() && realpath($dir) === realpath(lab_data_dir())) {
        lab_protect_dir($dir);
    }

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    /*
     * Postgres is used when db_driver() says so, and only then. The default
     * stays sqlite, so an installation that has not been migrated behaves
     * exactly as before and nobody has to do anything.
     *
     * Credentials come from the PG* names (includes/env.php), never from a
     * committed file.
     */
    if (db_driver() === 'pgsql') {
        try {
            $pdo = db_pg_connect();
        } catch (PDOException $e) {
            // The message is deliberately vague: a connection error can
            // otherwise print the host and user to the browser.
            error_log('db(): PostgreSQL connection failed: ' . get_class($e));
            db_refuse('The database could not be reached. Check that PostgreSQL on the drive is running (START HERE.bat starts it) and the PG* settings (pg.env beside the data folder, or .env).');
        }
        return $pdo;
    }

    try {
        $pdo = new PDO('sqlite:' . LAB_DB_PATH, null, null, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        exit('The database could not be opened. Run check.php to test the server, then install.php to create the database.');
    }

    $pdo->exec('PRAGMA foreign_keys = ON'); // dialect-ok: SQLite branch of the connection file; the pgsql branch has its own statement
    // Several laboratory users post at once; a writer should wait its turn
    // rather than fail outright.
    $pdo->exec('PRAGMA busy_timeout = 5000'); // dialect-ok: SQLite branch of the connection file; the pgsql branch has its own statement
    @$pdo->exec('PRAGMA journal_mode = WAL'); // dialect-ok: SQLite branch of the connection file; the pgsql branch has its own statement

    return $pdo;
}

/*
 * Transactions and generated keys go through these, so pages never touch the
 * PDO handle itself (drez-db-access, decision D-015). Each is one call on the
 * shared handle; behaviour is the same as calling PDO directly.
 */

/** Start a transaction on the shared handle. */
function db_begin(): void
{
    db()->beginTransaction();
}

/** Commit the open transaction. */
function db_commit(): void
{
    db()->commit();
}

/** Roll back the open transaction. */
function db_rollback(): void
{
    db()->rollBack();
}

/** True while a transaction is open. */
function db_in_transaction(): bool
{
    return db()->inTransaction();
}

/** The key the last INSERT generated (lastval() on PostgreSQL). */
function db_last_id(): string
{
    return (string) db()->lastInsertId();
}

/** Run SQL that takes no parameters, such as a schema statement. */
function db_exec(string $sql): void
{
    db()->exec($sql);
}

/** Run a prepared statement and return the statement. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Every matching row. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/** The first matching row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** The first column of the first matching row, or null. */
function db_value(string $sql, array $params = [])
{
    $value = db_run($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Insert a row, doing nothing if it is already there.
 *
 * SQLite spells this INSERT OR IGNORE and Postgres spells it
 * ON CONFLICT DO NOTHING. Both are supported, so seeding code does not have
 * to know which database it is talking to.
 */
function db_insert_ignore(string $table, array $columns, array $values): void
{
    $cols = implode(', ', array_map(static fn ($c) => '"' . $c . '"', $columns));
    $marks = implode(', ', array_fill(0, count($columns), '?'));
    if (db_driver() === 'pgsql') {
        db_run("INSERT INTO \"$table\" ($cols) VALUES ($marks) ON CONFLICT DO NOTHING", $values);
        return;
    }
    db_run("INSERT OR IGNORE INTO \"$table\" ($cols) VALUES ($marks)", $values); // dialect-ok: SQLite branch of the connection file; the pgsql branch has its own statement
}

/** True when the schema has been created. */
function db_installed(): bool
{
    // Only meaningful for SQLite, where the database is a file. A Postgres
    // installation has no such file, and this guard previously made
    // db_installed() always false there - which sends every visitor to the
    // installer on a database that is already populated.
    if (db_driver() === 'sqlite' && !file_exists(LAB_DB_PATH)) {
        // A missing database is a first run only when its folder is in
        // place; otherwise say so rather than send the visitor to the
        // installer on an empty folder (drive-invariance R4).
        $problem = lab_data_dir_problem(LAB_DB_PATH, false);
        if ($problem !== null) {
            db_refuse($problem);
        }
        return false;
    }
    try {
        if (db_driver() === 'pgsql') {
            return (bool) db_value(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_name = 'equipment'"
            );
        }
        return (bool) db_value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'equipment'"); // dialect-ok: SQLite branch of the connection file; the pgsql branch has its own statement
    } catch (Throwable $e) {
        return false;
    }
}
