<?php
/**
 * local-config.php: the portable-mode loader (platform D-013, D-014).
 *
 * config.local.json sits beside index.php, is git-ignored, and belongs to one
 * copy of the application. Its dataFolder names, relative to the application
 * folder, the folder that holds what the running application reads and
 * writes: lab.sqlite, the installer lock .installed, the logs and the
 * studio backups. On the drive it reads ..\..\data\pacelab-equip\data.
 *
 * With no config.local.json, or an empty dataFolder, every path stays where
 * it always was: data/ inside the application folder.
 *
 * Standalone on purpose: check.php includes it without db.php, and
 * tools/config.php uses it from the command line. lab_config_save() is the
 * only writer of config.local.json.
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

/** Keys config.local.json may hold; save() refuses anything else. */
const LAB_CONFIG_KEYS = ['tier', 'dataFolder', 'backupFolder', 'databaseDriver', 'authMode', 'databaseUrl', 'apiBaseUrl', 'secrets'];

function lab_config_file(): string
{
    return APP_ROOT . DIRECTORY_SEPARATOR . 'config.local.json';
}

/** config.local.json as an array; an empty array when the file is absent. */
function lab_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $file = lab_config_file();
    if (!is_readable($file)) {
        return $cfg = [];
    }
    $text = (string) file_get_contents($file);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $data = json_decode($text, true);
    if (!is_array($data)) {
        throw new RuntimeException('config.local.json is not valid JSON. Run SETUP.bat again or restore it from config.template.json.');
    }
    return $cfg = $data;
}

/** True when a path names a drive letter, a UNC share or a root. */
function lab_path_is_absolute(string $p): bool
{
    return (bool) preg_match('#^([A-Za-z]:[\\\\/]|[\\\\/])#', $p);
}

/** Resolve a path relative to the application folder, without requiring it to exist. */
function lab_resolve(string $rel): string
{
    $parts = [];
    $full = str_replace('\\', '/', APP_ROOT . '/' . $rel);
    $prefix = '';
    if (preg_match('#^([A-Za-z]:)/#', $full, $m)) {
        $prefix = $m[1];
        $full = substr($full, 2);
    } elseif (str_starts_with($full, '//')) {
        $prefix = '/';
    }
    foreach (explode('/', $full) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }
    return str_replace('/', DIRECTORY_SEPARATOR, $prefix . '/' . implode('/', $parts));
}

/** True when config.local.json names a data folder. */
function lab_data_folder_configured(): bool
{
    $v = lab_config()['dataFolder'] ?? '';
    return is_string($v) && trim($v) !== '';
}

/** The folder that holds lab.sqlite, .installed and the logs. */
function lab_data_dir(): string
{
    if (!lab_data_folder_configured()) {
        return APP_ROOT . DIRECTORY_SEPARATOR . 'data';
    }
    $rel = trim((string) lab_config()['dataFolder']);
    if (lab_path_is_absolute($rel)) {
        throw new RuntimeException('config.local.json dataFolder must be relative to the application folder, not ' . $rel);
    }
    return lab_resolve($rel);
}

/**
 * Where tools/backup.ps1 writes its copies. data\backup-path.txt in the data
 * folder wins, then backupFolder, then <dataFolder>\backups when a data folder
 * is configured, then the old sibling folder beside the application. Both
 * named values go through lab_backup_target(), so a relative value resolves
 * against the application folder and a stale absolute one is never used.
 */
function lab_backup_dir(): string
{
    $pathFile = lab_data_dir() . DIRECTORY_SEPARATOR . 'backup-path.txt';
    if (is_readable($pathFile)) {
        $p = trim((string) file_get_contents($pathFile));
        if ($p !== '') {
            return lab_backup_target($p, $pathFile);
        }
    }
    $b = lab_config()['backupFolder'] ?? '';
    if (is_string($b) && trim($b) !== '') {
        return lab_backup_target(trim($b), 'backupFolder in config.local.json');
    }
    if (lab_data_folder_configured()) {
        return lab_data_dir() . DIRECTORY_SEPARATOR . 'backups';
    }
    return dirname(APP_ROOT) . DIRECTORY_SEPARATOR . 'PaCELab Equip Backups';
}

/**
 * A backup folder named in backup-path.txt or backupFolder (drive-invariance
 * rules R1 to R3). A relative value resolves against the application folder,
 * so it follows the drive whatever letter it has today. An absolute value that
 * exists stands: it may be a folder on another volume chosen for this
 * computer. An absolute value that does not exist is re-rooted through
 * lab_reroot() (the drive letter changed); when that finds nothing either,
 * the backup refuses with a message rather than create folders on whatever
 * volume holds that letter now.
 */
function lab_backup_target(string $p, string $source): string
{
    if (!lab_path_is_absolute($p)) {
        return lab_resolve($p);
    }
    if (is_dir($p)) {
        return $p;
    }
    $moved = lab_reroot($p);
    if ($moved !== null) {
        return $moved;
    }
    throw new RuntimeException('Backup folder not found at ' . $p . ' (named in ' . $source
        . '). Connect the drive that holds it, or write the path relative to the application folder, and start again.');
}

/**
 * The live estate folder: the nearest folder above the application that holds
 * the marker file .estate-live. Null on a copy outside the estate (a server,
 * a frozen copy). No drive letter: the walk starts from APP_ROOT.
 */
function lab_estate_dir(): ?string
{
    $d = APP_ROOT;
    while (true) {
        if (is_file($d . DIRECTORY_SEPARATOR . '.estate-live')) {
            return $d;
        }
        $up = dirname($d);
        if ($up === $d) {
            return null;
        }
        $d = $up;
    }
}

/**
 * The bin folder of the estate's PostgreSQL runtime on the drive
 * (<estate>\runtime\postgresql\17\bin, platform D-028): pg_dump, pg_restore,
 * psql, pg_ctl. Null when this copy is outside the estate or the runtime is
 * missing.
 */
function lab_estate_pg_bin(): ?string
{
    $estate = lab_estate_dir();
    if ($estate === null) {
        return null;
    }
    $bin = implode(DIRECTORY_SEPARATOR, [$estate, 'runtime', 'postgresql', '17', 'bin']);
    return is_dir($bin) ? $bin : null;
}

/**
 * The same folder under this copy's own drive, for an absolute path written
 * while the drive had another letter: first the tail after the estate folder
 * (the folder two above the application, App_to_Migrate on the drive), then
 * the same path under the application's own drive root. Null when neither
 * exists; a miss is not remembered.
 */
function lab_reroot(string $abs): ?string
{
    $norm = str_replace('\\', '/', $abs);
    $root = str_replace('\\', '/', APP_ROOT);
    $estate = dirname(dirname($root));
    $name = basename($estate);
    $candidates = [];
    if ($name !== '' && preg_match('#(^|/)' . preg_quote($name, '#') . '(/.*)?$#i', $norm, $m)) {
        $candidates[] = $estate . ($m[2] ?? '');
    }
    if (preg_match('#^[A-Za-z]:(/.*)$#', $norm, $m) && preg_match('#^([A-Za-z]:)/#', $root, $r)) {
        $candidates[] = $r[1] . $m[1];
    }
    foreach ($candidates as $c) {
        $c = str_replace('/', DIRECTORY_SEPARATOR, $c);
        if (is_dir($c)) {
            return $c;
        }
    }
    return null;
}

/**
 * Drive-invariance rule R4: never open an empty database silently. Returns a
 * message when the folder that must hold lab.sqlite is missing, or holds the
 * installer lock .installed without lab.sqlite (the application has run there
 * before, so the database is gone or on a drive that is not connected); null
 * when the database may open. A missing leaf folder counts as a genuine first
 * run only when its parent exists, which proves the drive and its layout are
 * present; $create then makes that one folder, never a chain of them.
 */
function lab_data_dir_problem(string $dbPath, bool $create = true): ?string
{
    $dir = dirname($dbPath);
    if (is_dir($dir)) {
        if (!file_exists($dbPath) && file_exists($dir . DIRECTORY_SEPARATOR . '.installed')) {
            return 'Database not found at ' . $dbPath . ', although the application was installed there. '
                . 'Connect the drive that holds App_to_Migrate and start again.';
        }
        return null;
    }
    if (!is_dir(dirname($dir))) {
        return 'Data folder not found at ' . $dir . '. Connect the drive that holds App_to_Migrate and start again.';
    }
    if ($create && !@mkdir($dir, 0770) && !is_dir($dir)) {
        return 'Could not create the data folder at ' . $dir . '.';
    }
    return null;
}

/**
 * Scratch space for the tools: the PHP session folder, uploaded-file staging,
 * the restore drill's copy and the gate's copy of the database. It sits beside
 * the data folder rather than inside it, so scratch never lands in the folder
 * that gets backed up. Null when no data folder is configured, because then a
 * tmp folder would be inside the web root and the server's own temp is the
 * right answer (owner standard B, D-007).
 */
function lab_temp_dir(): ?string
{
    if (!lab_data_folder_configured()) {
        return null;
    }
    return dirname(lab_data_dir()) . DIRECTORY_SEPARATOR . 'tmp';
}

/**
 * Deny web access to a data folder: .htaccess for Apache, web.config for IIS.
 * Writes each one only when it is missing, the same text install.php writes.
 * Neither helps on nginx; there the deny belongs in the site configuration.
 */
function lab_protect_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess',
            "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (!file_exists($dir . '/web.config')) {
        @file_put_contents($dir . '/web.config',
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<configuration>\n  <system.webServer>\n    <security>\n"
            . "      <authorization>\n        <deny users=\"*\" />\n      </authorization>\n"
            . "    </security>\n  </system.webServer>\n</configuration>\n");
    }
}

/**
 * The only writer of config.local.json. Creates it from config.template.json
 * when missing, refuses unknown keys, secrets and absolute data folders, and
 * writes through a temporary file so a crash never leaves half a file.
 */
function lab_config_save(array $changes): array
{
    foreach ($changes as $k => $v) {
        if (!in_array($k, LAB_CONFIG_KEYS, true)) {
            throw new InvalidArgumentException('Unknown setting ' . $k . '; config.template.json lists the settings.');
        }
        if ($k === 'secrets') {
            throw new InvalidArgumentException('Secrets do not go through save(); the server reads them from its environment.');
        }
        if ($k === 'dataFolder' && is_string($v) && lab_path_is_absolute(trim($v))) {
            throw new InvalidArgumentException('dataFolder must be relative to the application folder.');
        }
        if ($k === 'databaseDriver' && !in_array($v, ['', 'sqlite', 'pgsql'], true)) {
            throw new InvalidArgumentException('databaseDriver must be sqlite or pgsql (empty means sqlite).');
        }
        // Identity (D-015): password is the application's own accounts, local the
        // development sign-in on this computer, proxy the university's
        // authenticating proxy on Radiant. includes/auth.php reads it.
        if ($k === 'authMode' && !in_array($v, ['', 'password', 'local', 'proxy'], true)) {
            throw new InvalidArgumentException('authMode must be password, local or proxy (empty means password).');
        }
    }
    $file = lab_config_file();
    $current = [];
    if (is_readable($file)) {
        $current = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($file)), true);
        if (!is_array($current)) {
            throw new RuntimeException('config.local.json is not valid JSON; refusing to overwrite it.');
        }
    } else {
        $tpl = APP_ROOT . DIRECTORY_SEPARATOR . 'config.template.json';
        $current = is_readable($tpl) ? (json_decode((string) file_get_contents($tpl), true) ?: []) : [];
    }
    $next = array_merge($current, $changes);
    // json_decode turns {} into [], which would write "secrets": [].
    if (array_key_exists('secrets', $next) && $next['secrets'] === []) {
        $next['secrets'] = new stdClass();
    }
    $json = json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $json) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write ' . $file);
    }
    return $next;
}
