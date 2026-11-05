<?php
/**
 * tools/router.php: the router for PHP's built-in server (the supervisor in tools/serve.ps1).
 *
 * The built-in server hands out any file under its document root that is not
 * a PHP script, dotfiles included: a request for /.env returned the file
 * (checked 2026-10-10 on a scratch copy holding a made-up value). On this
 * computer the document root is the application folder, which holds .env with
 * the PostgreSQL password, the launchers, the tests and the migrations.
 * Apache and IIS read the .htaccess and web.config files instead; this router
 * gives the built-in server the same refusals.
 *
 * Refused with 404: any path segment starting with a dot (.env, .git, ..),
 * any segment holding a tilde (Windows short names), the folders the web has
 * no business in, and files whose type is never a page or an asset. Every
 * other request is left to the built-in server exactly as before.
 */

declare(strict_types=1);

/** True when the built-in server must refuse this request URI. */
function lab_router_denies(string $uri): bool
{
    $path = str_replace('\\', '/', rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?? '/')));
    $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
    foreach ($segments as $seg) {
        if ($seg[0] === '.' || str_contains($seg, '~')) {
            return true;
        }
    }
    // Windows ignores trailing dots and spaces in a name, so "data." is "data".
    $first = strtolower(rtrim($segments[0] ?? '', '. '));
    $closed = ['data', 'tools', 'tests', 'migrations', 'migration', 'reference', 'docs', 'dist', 'scripts'];
    if (in_array($first, $closed, true)) {
        return true;
    }
    $last = strtolower(rtrim(end($segments) ?: '', '. '));
    if (preg_match('/\.(env|bat|cmd|ps1|vbs|sqlite|sqlite3|db|log|md|sql|yml|yaml|txt|docx|ini|lock|dump|pgdump)$/', $last)) {
        return true;
    }
    return in_array($last, ['config.local.json', 'config.local.json.tmp', 'config.template.json',
                            'hardcoded-allow.json', 'dockerfile', 'compose.yaml', 'pg.env'], true);
}

if (PHP_SAPI === 'cli-server') {
    if (lab_router_denies((string) ($_SERVER['REQUEST_URI'] ?? '/'))) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found.\n";
        return true;
    }
    return false;                       // the built-in server serves it as before
}
