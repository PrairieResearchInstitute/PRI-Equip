<?php
/**
 * tools/config.php: the command line for config.local.json.
 *
 *   php tools/config.php show [--json]       the resolved paths and the effective driver, read only
 *   php tools/config.php init                create config.local.json from the template when missing
 *   php tools/config.php set <key> <value>   change one setting through lab_config_save()
 *   php tools/config.php set-if-empty <key> <value>   the same, only when the key is empty
 *
 * SETUP.bat calls init and then set dataFolder. Nobody edits the JSON by hand.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/includes/local-config.php';

$cmd = $argv[1] ?? 'show';

try {
    if ($cmd === 'init') {
        if (is_readable(lab_config_file())) {
            echo "config.local.json exists; left as it is.\n";
        } else {
            lab_config_save([]);
            echo "Created config.local.json from config.template.json.\n";
        }
        exit(0);
    }
    if ($cmd === 'set') {
        if (!isset($argv[2], $argv[3])) {
            fwrite(STDERR, "Usage: php tools/config.php set <key> <value>\n");
            exit(2);
        }
        lab_config_save([$argv[2] => $argv[3]]);
        echo 'Saved ' . $argv[2] . ' = ' . $argv[3] . "\n";
        exit(0);
    }
    if ($cmd === 'set-if-empty') {
        if (!isset($argv[2], $argv[3])) {
            fwrite(STDERR, "Usage: php tools/config.php set-if-empty <key> <value>\n");
            exit(2);
        }
        $have = lab_config()[$argv[2]] ?? '';
        if (is_string($have) && trim($have) !== '') {
            echo $argv[2] . ' already set to ' . $have . "; left as it is.\n";
            exit(0);
        }
        lab_config_save([$argv[2] => $argv[3]]);
        echo 'Saved ' . $argv[2] . ' = ' . $argv[3] . "\n";
        exit(0);
    }
    if ($cmd === 'show') {
        // The effective driver, so tools/backup.ps1 and the gate follow it.
        // db.php opens nothing at include time.
        require_once APP_ROOT . '/includes/db.php';
        require_once APP_ROOT . '/includes/auth.php';   // the identity mode (D-015); opens nothing either
        $dir = lab_data_dir();
        $info = [
            'configFile'   => lab_config_file(),
            'configExists' => is_readable(lab_config_file()),
            'dataFolder'   => lab_config()['dataFolder'] ?? '',
            'dataDir'      => $dir,
            'database'     => $dir . DIRECTORY_SEPARATOR . 'lab.sqlite',
            'installLock'  => $dir . DIRECTORY_SEPARATOR . '.installed',
            'serverLog'    => $dir . DIRECTORY_SEPARATOR . 'server.log',
            'backupDir'    => lab_backup_dir(),
            'tempDir'      => (string) lab_temp_dir(),
            'driver'       => db_driver(),
            'authMode'     => auth_mode(),
            'pgEnvFile'    => lab_data_folder_configured() ? dirname($dir) . DIRECTORY_SEPARATOR . 'pg.env' : '',
        ];
        if (in_array('--json', $argv, true)) {
            echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        } else {
            foreach ($info as $k => $v) {
                echo str_pad($k, 14), is_bool($v) ? ($v ? 'yes' : 'no') : $v, "\n";
            }
        }
        exit(0);
    }
    fwrite(STDERR, "Unknown command $cmd. Use show, init or set.\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'config: ' . $e->getMessage() . "\n");
    exit(1);
}
