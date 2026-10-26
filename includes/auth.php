<?php
/**
 * auth.php: who is at the keyboard, and what they may do.
 *
 * ---------------------------------------------------------------------------
 * THREE MODES (D-015), chosen by auth_mode():
 *
 *   password  The application's own accounts, as before: laboratory users
 *             type their last name, administrators sign in with a username
 *             and password from admin_users. The default, so a copy nobody
 *             has switched behaves exactly as it always did.
 *
 *   proxy     Radiant. The university's authenticating proxy has verified the
 *             person and passes their address (X-Forwarded-Email) and, when
 *             configured, their campus groups (X-Forwarded-Groups). The NetID
 *             is the identity; role_grants (includes/roles.php) decides what
 *             it may do. No password table, no sign-in form: PRI's rule.
 *             Trustworthy only while the application is reachable through the
 *             proxy alone; PACELAB_TRUSTED_PROXIES narrows it further.
 *
 *   local     This computer only: a development sign-in that asks for a NetID
 *             and then behaves exactly like the proxy mode, roles and all.
 *             Refused unless the request comes over loopback to PHP's
 *             built-in server with no proxy header on it, so it cannot work
 *             on Radiant even if the setting were copied there.
 *
 * Every screen still asks the same questions it always asked:
 * current_user_name(), is_admin(), require_admin(), admin_display_name() and
 * auth_may_edit_code(). Only the answers come from a different place, so the
 * permission rules in the pages did not change.
 *
 * Settings, from the environment or an environment file (includes/env.php),
 * or authMode in config.local.json for the mode:
 *   PACELAB_AUTH_MODE         password | local | proxy
 *   PACELAB_IDENTITY_HEADER   default X-Forwarded-Email
 *   PACELAB_IDENTITY_DOMAINS  default illinois.edu (comma list); an address
 *                             in another domain is refused
 *   PACELAB_GROUPS_HEADER     default X-Forwarded-Groups; "none" turns it off
 *   PACELAB_TRUSTED_PROXIES   optional comma list of addresses or CIDR ranges
 *                             the proxy connects from
 *   PACELAB_UNLISTED_ROLE     member (default) | none: what a NetID with no
 *                             grant and no laboratory may do
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/roles.php';

const AUTH_MODES = ['password', 'local', 'proxy'];

/** Start the session once, with cookie settings that suit a campus host. */
function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // A backup or maintenance script run from the command line pulls in these
    // same files and has no session to start; so does any page that has already
    // begun sending output. Both would only produce warnings, so say no here.
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    session_name('LABEQUIP');
    session_start();
}

// ---------------------------------------------------------------------------
// The mode and its settings
// ---------------------------------------------------------------------------

/**
 * The mode in force. In order: LAB_FORCE_AUTH_MODE (a constant only the test
 * harness defines), PACELAB_AUTH_MODE in the real environment (a container's
 * env file; the image sets proxy), authMode in config.local.json (this
 * computer: php tools/config.php set authMode local), PACELAB_AUTH_MODE from
 * an environment file, then password. The test harness switches modes with
 * $testMode, which only the command line honours.
 */
function auth_mode(?string $testMode = null): string
{
    static $mode = null;
    if ($testMode !== null && PHP_SAPI === 'cli' && in_array($testMode, AUTH_MODES, true)) {
        $mode = $testMode;
        auth_principal(true);
    }
    if ($mode === null) {
        lab_load_env();
        $env = getenv('PACELAB_AUTH_MODE');
        $fromFile = in_array('PACELAB_AUTH_MODE', lab_env_file_names(), true);
        $mode = auth_mode_from(
            defined('LAB_FORCE_AUTH_MODE') ? (string) LAB_FORCE_AUTH_MODE : null,
            $fromFile ? null : ($env === false ? null : $env),
            (string) (lab_config()['authMode'] ?? ''),
            $fromFile ? (string) $env : null
        );
    }
    return $mode;
}

/**
 * The mode rule without the process state, so the tests can prove the order.
 * The first source that says anything decides; a value that is not a mode
 * fails closed to proxy, never open to a sign-in form.
 */
function auth_mode_from(?string $forced, ?string $realEnv, ?string $config, ?string $fileEnv): string
{
    foreach ([$forced, $realEnv, $config, $fileEnv] as $choice) {
        if ($choice !== null && trim($choice) !== '') {
            $c = strtolower(trim($choice));
            return in_array($c, AUTH_MODES, true) ? $c : 'proxy';
        }
    }
    return 'password';
}

/** True in the local and proxy modes, where a NetID is the identity. */
function auth_uses_netid(): bool
{
    return auth_mode() !== 'password';
}

/** One identity setting, from the environment or an environment file, with its default. */
function auth_setting(string $name, string $default): string
{
    lab_load_env();
    $v = getenv($name);
    return (is_string($v) && trim($v) !== '') ? trim($v) : $default;
}

/** The identity settings as one array, the shape auth_proxy_identity() takes. */
function auth_settings(): array
{
    return [
        'identity_header'  => auth_setting('PACELAB_IDENTITY_HEADER', 'X-Forwarded-Email'),
        'identity_domains' => auth_setting('PACELAB_IDENTITY_DOMAINS', 'illinois.edu'),
        'groups_header'    => auth_setting('PACELAB_GROUPS_HEADER', 'X-Forwarded-Groups'),
        'trusted_proxies'  => auth_setting('PACELAB_TRUSTED_PROXIES', ''),
        'unlisted_role'    => auth_setting('PACELAB_UNLISTED_ROLE', 'member'),
    ];
}

/** "X-Forwarded-Email" as PHP files it in $_SERVER: HTTP_X_FORWARDED_EMAIL. */
function auth_header_key(string $header): string
{
    return 'HTTP_' . strtoupper(str_replace('-', '_', trim($header)));
}

/** True when $ip is one of the comma-listed addresses or inside one of its CIDR ranges. */
function auth_ip_allowed(string $ip, string $list): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach (array_filter(array_map('trim', explode(',', $list))) as $entry) {
        [$net, $bits] = array_pad(explode('/', $entry, 2), 2, null);
        $netBin = @inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($bin)) {
            continue;
        }
        $bits = $bits === null ? strlen($bin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            continue;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
            return true;
        }
    }
    return false;
}

/**
 * Who the proxy says this is. Pure: takes $_SERVER and auth_settings().
 * Returns ['netid' => ..., 'groups' => [...]] or ['problem' => sentence, 'status' => 401|403].
 */
function auth_proxy_identity(array $server, array $settings): array
{
    $trusted = trim((string) ($settings['trusted_proxies'] ?? ''));
    if ($trusted !== '' && !auth_ip_allowed((string) ($server['REMOTE_ADDR'] ?? ''), $trusted)) {
        return ['problem' => 'This request did not come through the campus login proxy.', 'status' => 403];
    }
    $raw = strtolower(trim((string) ($server[auth_header_key((string) $settings['identity_header'])] ?? '')));
    if ($raw === '') {
        return ['problem' => 'Not signed in. This application must be reached through the campus login proxy.', 'status' => 401];
    }
    $netid = $raw;
    if (str_contains($raw, '@')) {
        [$local, $domain] = explode('@', $raw, 2);
        $domains = array_filter(array_map(static fn ($d) => strtolower(trim($d)), explode(',', (string) $settings['identity_domains'])));
        if (!in_array($domain, $domains, true)) {
            return ['problem' => 'Signed in as ' . $raw . ', which is not a university NetID address.', 'status' => 403];
        }
        $netid = $local;
    }
    if (!role_valid_netid($netid)) {
        return ['problem' => 'The campus login proxy passed an identity this application cannot read.', 'status' => 403];
    }
    $groups = [];
    $gh = (string) ($settings['groups_header'] ?? '');
    if ($gh !== '' && strtolower($gh) !== 'none') {
        foreach (explode(',', (string) ($server[auth_header_key($gh)] ?? '')) as $g) {
            $g = strtolower(trim($g));
            if (role_valid_group($g)) {
                $groups[] = $g;
            }
        }
    }
    return ['netid' => $netid, 'groups' => array_values(array_unique($groups))];
}

/** The server API in use; config.php or the test harness may pin it with LAB_TEST_SAPI. */
function auth_sapi(): string
{
    return defined('LAB_TEST_SAPI') ? (string) LAB_TEST_SAPI : PHP_SAPI;
}

/**
 * Why the development sign-in is refused here, or null when it may proceed.
 * Pure. It runs only in the local mode, only under PHP's built-in server
 * (the supervisor on this computer; Radiant runs Apache), only over
 * loopback, and never on a request that carries any proxy header.
 */
function auth_dev_login_problem(array $server, string $mode, string $sapi): ?string
{
    if ($mode !== 'local') {
        return 'The development sign-in exists only in the local mode on the owner\'s computer.';
    }
    foreach (['HTTP_X_FORWARDED_EMAIL', 'HTTP_X_FORWARDED_USER', 'HTTP_X_FORWARDED_FOR',
              'HTTP_X_FORWARDED_GROUPS', 'HTTP_X_FORWARDED_PREFERRED_USERNAME', 'HTTP_X_AUTH_REQUEST_EMAIL',
              'HTTP_FORWARDED', 'HTTP_X_REAL_IP'] as $h) {
        if (trim((string) ($server[$h] ?? '')) !== '') {
            return 'This request came through a proxy, so the development sign-in is refused. Set PACELAB_AUTH_MODE=proxy.';
        }
    }
    if ($sapi !== 'cli-server') {
        return 'The development sign-in runs only on PHP\'s built-in server on this computer.';
    }
    $ip = (string) ($server['REMOTE_ADDR'] ?? '');
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        return 'The development sign-in answers only this computer (loopback), not ' . ($ip ?: 'an unknown address') . '.';
    }
    return null;
}

// ---------------------------------------------------------------------------
// The person, in the NetID modes
// ---------------------------------------------------------------------------

/**
 * The signed-in person in the local and proxy modes:
 * ['netid', 'groups', 'roles', 'labs'], or null with the reason in
 * auth_problem(). Worked out once per request; $reload is for the tests.
 */
function auth_principal(bool $reload = false): ?array
{
    static $cache = null;
    static $done = false;
    if ($done && !$reload) {
        return $cache;
    }
    $done = true;
    $cache = null;
    auth_problem('', 0);

    $mode = auth_mode();
    if ($mode === 'password') {
        return null;
    }
    if ($mode === 'proxy') {
        $who = auth_proxy_identity($_SERVER, auth_settings());
        if (isset($who['problem'])) {
            auth_problem($who['problem'], $who['status']);
            return null;
        }
    } else {
        session_boot();
        $netid = (string) ($_SESSION['dev_netid'] ?? '');
        $problem = auth_dev_login_problem($_SERVER, $mode, auth_sapi());
        if ($problem !== null && $netid !== '') {
            auth_problem($problem, 403);
            return null;
        }
        $last = (int) ($_SESSION['dev_last_seen'] ?? 0);
        if ($netid !== '' && $last > 0 && (time() - $last) > ADMIN_IDLE_TIMEOUT) {
            unset($_SESSION['dev_netid'], $_SESSION['dev_last_seen']);
            $netid = '';
        }
        if ($netid === '') {
            auth_problem('Not signed in.', 401);
            return null;
        }
        $_SESSION['dev_last_seen'] = time();
        $who = ['netid' => $netid, 'groups' => []];
    }
    if (!roles_table_ready()) {
        auth_problem('Role grants are not set up in this database yet: apply migration 0003 (tools/migrate.php).', 503);
        $who['roles'] = [];
        $who['labs'] = [];
        return $cache = $who;
    }
    $held = roles_for($who['netid'], $who['groups']);
    return $cache = $who + $held;
}

/** The reason auth_principal() returned null, and the HTTP status that goes with it. */
function auth_problem(?string $set = null, int $status = 0): array
{
    static $problem = ['', 0];
    if ($set !== null) {
        $problem = [$set, $status];
    }
    return $problem;
}

/** True when the person in the NetID modes holds the role. code_editor implies admin. */
function auth_has_role(string $role): bool
{
    $p = auth_principal();
    if ($p === null) {
        return false;
    }
    if ($role === 'admin') {
        return (bool) array_intersect(['admin', 'code_editor'], $p['roles']);
    }
    return in_array($role, $p['roles'], true);
}

/**
 * Laboratories the person belongs to through a member grant (their NetID or a
 * group), on top of lab_members. Empty in the password mode. With $netid the
 * question is about that NetID alone, without groups.
 */
function auth_granted_lab_ids(?string $netid = null): array
{
    if (!auth_uses_netid()) {
        return [];
    }
    if ($netid !== null) {
        return roles_table_ready() ? roles_for($netid)['labs'] : [];
    }
    $p = auth_principal();
    return $p ? $p['labs'] : [];
}

/**
 * Stop a request the NetID modes cannot serve. Called by require_installed()
 * and api.php. In the proxy mode a request without an identity gets 401; with
 * PACELAB_UNLISTED_ROLE=none, a NetID with no grant and no laboratory gets
 * 403. The local mode sends an unsigned visitor to the development sign-in.
 * The password mode returns at once.
 */
function auth_require_identity(bool $json = false): void
{
    $mode = auth_mode();
    if ($mode === 'password') {
        return;
    }
    $p = auth_principal();
    if ($p === null) {
        [$why, $status] = auth_problem();
        if ($mode === 'local' && $status === 401 && !$json) {
            $here = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
            if ($here !== 'login.php') {
                header('Location: ' . base_url() . 'admin/login.php');
                exit;
            }
            return;
        }
        auth_stop($status ?: 401, $why ?: 'Not signed in.', $json);
    }
    [$why, $status] = auth_problem();
    if ($status === 503) {
        auth_stop(503, $why, $json);
    }
    if (auth_unlisted_refused($p, auth_settings()['unlisted_role'])) {
        auth_stop(403, 'Signed in as ' . $p['netid'] . ', who has not been given access to this application. '
            . 'Ask the laboratory manager to add your NetID or your group.', $json);
    }
}

/**
 * True when PACELAB_UNLISTED_ROLE=none turns this person away: no grant of
 * any kind and no row in lab_members. With member (the default) a NetID the
 * proxy let through may sign in and sees no laboratory until one is assigned,
 * as a typed name always could.
 */
function auth_unlisted_refused(array $p, string $unlisted): bool
{
    if ($unlisted !== 'none' || $p['roles'] || $p['labs']) {
        return false;
    }
    return !db_one('SELECT 1 FROM lab_members WHERE person_key = ?', [$p['netid']]);
}

/** Answer with a status and one sentence, as a page or as JSON, and stop. */
function auth_stop(int $status, string $message, bool $json = false): never
{
    http_response_code($status);
    if ($json) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => $message]);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        $words = '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        // functions.php defines the shell and pulls this file in, so it is
        // there on every real request. A tool that loads auth.php on its own
        // still gets the sentence rather than a fatal error.
        echo function_exists('error_shell')
            ? error_shell('PRI Shared Equipment', $words)
            : '<!doctype html><meta charset="utf-8"><title>PRI Shared Equipment</title>' . $words;
    }
    exit;
}

// ---------------------------------------------------------------------------
// Laboratory user identity
// ---------------------------------------------------------------------------

/**
 * The person at the keyboard: the typed name in the password mode, the NetID
 * otherwise. lab_members.person_key holds whichever the mode uses.
 */
function current_user_name(): string
{
    if (auth_uses_netid()) {
        $p = auth_principal();
        return $p ? $p['netid'] : '';
    }
    session_boot();
    return trim((string) ($_SESSION['user_name'] ?? ''));
}

/**
 * True while users type their own name (the password mode). False when the
 * proxy or the development sign-in supplies the NetID, which hides the name
 * prompt and the operator field.
 */
function identity_is_self_declared(): bool
{
    return !auth_uses_netid();
}

/** Record the self-declared name. The password mode only; elsewhere the NetID stands. */
function set_current_user_name(string $name): void
{
    if (auth_uses_netid()) {
        return;
    }
    session_boot();
    $_SESSION['user_name'] = trim(preg_replace('/\s+/', ' ', $name));
}

/** True when we know who is using the application. */
function have_user_name(): bool
{
    return current_user_name() !== '';
}

// ---------------------------------------------------------------------------
// Administrator
// ---------------------------------------------------------------------------

const ADMIN_IDLE_TIMEOUT = 1800; // thirty minutes, per the security requirements

/**
 * The signed-in administrator, or null. The password mode returns the
 * admin_users row; the NetID modes return the same keys for a NetID that
 * holds admin or code_editor (user_id null, username the NetID).
 */
function admin_user(): ?array
{
    if (auth_uses_netid()) {
        if (!auth_has_role('admin')) {
            return null;
        }
        $netid = auth_principal()['netid'];
        return [
            'user_id'       => null,
            'username'      => $netid,
            'display_name'  => $netid,
            'active'        => 1,
            'may_edit_code' => auth_has_role('code_editor') ? 1 : 0,
        ];
    }

    session_boot();
    if (empty($_SESSION['admin_user_id'])) {
        return null;
    }

    // Idle timeout. Any gap longer than the limit ends the administrative session.
    $last = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($last > 0 && (time() - $last) > ADMIN_IDLE_TIMEOUT) {
        admin_logout();
        return null;
    }
    $_SESSION['admin_last_seen'] = time();

    $admin = db_one(
        'SELECT user_id, username, display_name, active FROM admin_users WHERE user_id = ?',
        [$_SESSION['admin_user_id']]
    );
    if (!$admin || (int) $admin['active'] !== 1) {
        admin_logout();
        return null;
    }
    return $admin;
}

/** True when an administrator is signed in. */
function is_admin(): bool
{
    return admin_user() !== null;
}

/**
 * Stop anyone who is not an administrator. The password mode and the local
 * mode send an unsigned visitor to the sign-in page; the proxy mode has no
 * sign-in page and answers 401 or 403.
 */
function require_admin(): array
{
    $admin = admin_user();
    if ($admin !== null) {
        return $admin;
    }
    $mode = auth_mode();
    if ($mode === 'proxy' || ($mode === 'local' && auth_principal() !== null)) {
        $p = auth_principal();
        if ($p === null) {
            [$why, $status] = auth_problem();
            auth_stop($status ?: 401, $why ?: 'Not signed in.');
        }
        auth_stop(403, 'Signed in as ' . $p['netid'] . ', who does not hold the administrator role here. '
            . 'An administrator can grant it under Administration, Administrators.');
    }
    $target = $_SERVER['REQUEST_URI'] ?? 'index.php';
    header('Location: login.php?next=' . urlencode($target));
    exit;
}

/** The display name of the signed-in administrator, for stamping records. */
function admin_display_name(): string
{
    $admin = admin_user();
    return $admin ? (string) ($admin['display_name'] ?: $admin['username']) : '';
}

/**
 * May the signed-in administrator edit the code? A separate grant in every
 * mode: may_edit_code in the password mode, the code_editor role otherwise.
 */
function auth_may_edit_code(): bool
{
    $admin = admin_user();
    if (!$admin) {
        return false;
    }
    if (auth_uses_netid()) {
        return auth_has_role('code_editor');
    }
    return (int) db_value('SELECT may_edit_code FROM admin_users WHERE user_id = ?',
                          [$admin['user_id']]) === 1;
}

/** The client address used for throttling. */
function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * How long the caller must wait before another sign-in attempt, in seconds.
 * Zero means the attempt may proceed.
 */
function login_lockout_seconds(string $username): int
{
    $since = date('Y-m-d H:i:s', time() - 900); // fifteen minute window

    $byIp = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = 0 AND attempted_at > ?',
        [client_ip(), $since]
    );
    $byUser = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND succeeded = 0 AND attempted_at > ?',
        [$username, $since]
    );

    if ($byIp < 8 && $byUser < 5) {
        return 0;
    }

    $newest = db_value(
        'SELECT MAX(attempted_at) FROM login_attempts WHERE succeeded = 0 AND attempted_at > ? AND (ip = ? OR username = ?)',
        [$since, client_ip(), $username]
    );
    $remaining = 900 - (time() - strtotime((string) $newest));
    return max(1, $remaining);
}

/** Note an attempt so throttling has something to count. */
function record_login_attempt(string $username, bool $succeeded): void
{
    db_run(
        'INSERT INTO login_attempts (ip, username, succeeded, attempted_at) VALUES (?, ?, ?, ?)',
        [client_ip(), $username, $succeeded ? 1 : 0, date('Y-m-d H:i:s')]
    );
    // Keep the table small; anything older than a day has no bearing on throttling.
    db_run('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}

/**
 * Verify credentials and open an administrative session. The password mode
 * only: the NetID modes have no password to check.
 * Returns an error message, or null on success.
 */
function admin_login(string $username, string $password): ?string
{
    if (auth_uses_netid()) {
        return 'Passwords are not used here: the campus login (NetID) says who you are.';
    }
    session_boot();

    $wait = login_lockout_seconds($username);
    if ($wait > 0) {
        return 'Too many failed sign-in attempts. Try again in ' . ceil($wait / 60) . ' minute(s).';
    }

    $admin = db_one('SELECT * FROM admin_users WHERE username = ? AND active = 1', [$username]);

    // Verify against a dummy hash when the account is unknown, so a missing
    // account and a wrong password take the same amount of time to answer.
    $hash = $admin['password_hash'] ?? '$2y$10$usesomesillystringforsalt00000000000000000000000000000000';
    $ok = password_verify($password, $hash) && $admin !== null;

    record_login_attempt($username, $ok);

    if (!$ok) {
        return 'That username and password do not match an active administrator account.';
    }

    if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE admin_users SET password_hash = ? WHERE user_id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $admin['user_id']]);
    }

    session_regenerate_id(true);
    $_SESSION['admin_user_id']   = (int) $admin['user_id'];
    $_SESSION['admin_last_seen'] = time();
    db_run('UPDATE admin_users SET last_login = ? WHERE user_id = ?',
        [date('Y-m-d H:i:s'), $admin['user_id']]);

    return null;
}

/**
 * The development sign-in (local mode, this computer only): take a NetID and
 * behave as the proxy would for it. Returns an error message, or null.
 */
function dev_login(string $netid): ?string
{
    $problem = auth_dev_login_problem($_SERVER, auth_mode(), auth_sapi());
    if ($problem !== null) {
        return $problem;
    }
    $netid = strtolower(trim($netid));
    if (!role_valid_netid($netid)) {
        return 'Type a NetID: letters, digits, dot, underscore or hyphen.';
    }
    session_boot();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['dev_netid']     = $netid;
    $_SESSION['dev_last_seen'] = time();
    auth_principal(true);
    return null;
}

/** Close the administrative session, leaving the laboratory identity alone in the password mode. */
function admin_logout(): void
{
    session_boot();
    unset($_SESSION['admin_user_id'], $_SESSION['admin_last_seen'],
          $_SESSION['dev_netid'], $_SESSION['dev_last_seen']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}
