<?php
// health.php: from the drez-containerize PHP template (decision D-003).
//
// GET /health.php        process check: no database, no session, no config.
// GET /health.php?db=1   also runs SELECT 1 through the app's connection file;
//                        503 when the database does not answer.
// Never prints an error message, a path to a config file or a connection string.
header('Content-Type: application/json');
header('Cache-Control: no-store');

$out = ['app' => 'pacelab-equip', 'root' => __DIR__, 'php' => PHP_VERSION, 'ok' => true];

if (isset($_GET['db'])) {
    try {
        require_once __DIR__ . '/includes/db.php';
        // db() itself ends the request with HTTP 500 when the database cannot
        // be opened, which the container health check also reads as down.
        db_value('SELECT 1');
        $out['db'] = 'ok';
    } catch (Throwable $e) {
        error_log('health.php: database probe failed: ' . get_class($e));
        http_response_code(503);
        $out['ok'] = false;
        $out['db'] = 'unreachable';
    }
}

echo json_encode($out);
