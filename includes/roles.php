<?php
/**
 * roles.php: what a NetID or a campus group may do here (D-015).
 *
 * The university's authenticating proxy says who a person is; this file says
 * what that person may do, from the role_grants table (migration 0003). Three
 * roles, the ones the application already had:
 *
 *   member       may use the laboratory screens. A grant that names a lab_id
 *                also makes the NetID, or everyone in the group, a member of
 *                that laboratory, beside the people listed in lab_members.
 *   admin        the administrative panel (what an admin_users row gave).
 *   code_editor  the panel and the Studio (what may_edit_code gave). Granted
 *                separately and never by a person to themselves.
 *
 * A principal is a NetID ("jdoe2") or a group ("group:pri-pacelab-admins"),
 * always stored in lower case. Nothing is deleted: revoking a grant stamps
 * revoked_at and revoked_by, so an auditor can see who held what and when.
 *
 * Used by includes/auth.php (every request in the local and proxy modes),
 * admin/users.php (the Administrators screen in those modes) and
 * tools/roles.php (the owner's seeding command). Every query goes through the
 * db_* helpers on the shared handle.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ROLE_NAMES = ['member', 'admin', 'code_editor'];
const ROLE_KINDS = ['netid', 'group'];

/**
 * The owner, superadmin to start (owner decision 2026-10-12, D-016). A fresh
 * seed gives this NetID code_editor, the top role, and nobody else anything;
 * the owner assigns every other grant himself.
 */
const ROLE_OWNER_NETID = 'dreslik';
const ROLE_OWNER_EMAIL = 'configure-me@example.edu';
const ROLE_OWNER_ROLE  = 'code_editor';

/** Who did it, when the change comes from the command line rather than a person in a browser. */
const ROLE_CONSOLE = 'console';

/** Plain words for each role, for the screens and the command line. */
function role_label(string $role): string
{
    return [
        'member'      => 'Laboratory member',
        'admin'       => 'Administrator',
        'code_editor' => 'Administrator who may edit the code',
    ][$role] ?? $role;
}

/** A NetID: letters, digits, dot, underscore or hyphen, at most 64 characters. */
function role_valid_netid(string $netid): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $netid);
}

/** A group name as a proxy passes it: no commas (the header separator), no control characters. */
function role_valid_group(string $group): bool
{
    return $group !== '' && strlen($group) <= 256 && !preg_match('/[,\x00-\x1f\x7f]/', $group);
}

/**
 * Split "jdoe2" or "group:Some-Group" into [kind, principal], lower case.
 * Throws InvalidArgumentException on anything else.
 */
function role_parse_principal(string $raw): array
{
    $raw = strtolower(trim($raw));
    if (str_starts_with($raw, 'group:')) {
        $name = trim(substr($raw, 6));
        if (!role_valid_group($name)) {
            throw new InvalidArgumentException('A group name may not be empty or hold a comma.');
        }
        return ['group', $name];
    }
    if (str_starts_with($raw, 'netid:')) {
        $raw = trim(substr($raw, 6));
    }
    if (!role_valid_netid($raw)) {
        throw new InvalidArgumentException('"' . $raw . '" is not a NetID (letters, digits, dot, underscore or hyphen). Write a group as group:<name>.');
    }
    return ['netid', $raw];
}

/** The principal as people write it: the NetID, or group:<name>. */
function role_principal_text(array $row): string
{
    return ($row['principal_kind'] === 'group' ? 'group:' : '') . $row['principal'];
}

/**
 * True when migration 0003 has been applied to the database in use. The local
 * and proxy modes refuse to grant anything without it rather than guess.
 */
function roles_table_ready(): bool
{
    static $ready = null;
    if ($ready === true) {
        return true;
    }
    try {
        db_value('SELECT COUNT(*) FROM role_grants WHERE grant_id = 0');
        return $ready = true;
    } catch (Throwable $e) {
        // PostgreSQL aborts an open transaction on a failed statement.
        if (db_in_transaction()) {
            db_rollback();
        }
        return false;
    }
}

/** Every grant still in force, laboratory names alongside, newest first within a role. */
function roles_active(): array
{
    return db_all(
        'SELECT g.*, l.code AS lab_code, l.name AS lab_name
           FROM role_grants g
           LEFT JOIN labs l ON l.lab_id = g.lab_id
          WHERE g.revoked_at IS NULL
          ORDER BY g.role, g.principal_kind, g.principal, g.grant_id'
    );
}

/** The whole history, revoked grants included, for the audit list. */
function roles_history(int $limit = 200): array
{
    return db_all(
        'SELECT g.*, l.code AS lab_code
           FROM role_grants g
           LEFT JOIN labs l ON l.lab_id = g.lab_id
          ORDER BY g.grant_id DESC
          LIMIT ' . max(1, $limit) // db-calls: allow an integer the caller passes, clamped and cast, never input
    );
}

/** One grant by id, or null. */
function role_grant_by_id(int $grantId): ?array
{
    return db_one('SELECT * FROM role_grants WHERE grant_id = ?', [$grantId]);
}

/**
 * What a person holds: ['roles' => [...], 'labs' => [lab ids]] from every
 * grant in force that names their NetID or one of their groups.
 */
function roles_for(string $netid, array $groups = []): array
{
    $netid  = strtolower(trim($netid));
    $groups = array_values(array_unique(array_filter(array_map(
        static fn ($g) => strtolower(trim((string) $g)), $groups
    ), 'role_valid_group')));
    if ($netid === '' && !$groups) {
        return ['roles' => [], 'labs' => []];
    }
    $sql    = 'SELECT role, lab_id FROM role_grants WHERE revoked_at IS NULL AND ((principal_kind = ? AND principal = ?)';
    $params = ['netid', $netid];
    if ($groups) {
        $placeholders = implode(',', array_fill(0, count($groups), '?'));
        $sql .= ' OR (principal_kind = ? AND principal IN (' . $placeholders . '))';
        $params = array_merge($params, ['group'], $groups);
    }
    $roles = [];
    $labs  = [];
    foreach (db_all($sql . ')', $params) as $row) {
        $roles[$row['role']] = true;
        if ($row['role'] === 'member' && $row['lab_id'] !== null) {
            $labs[(int) $row['lab_id']] = true;
        }
    }
    return ['roles' => array_keys($roles), 'labs' => array_keys($labs)];
}

/** How many grants still give the administrative panel to somebody. */
function roles_admin_grant_count(): int
{
    return (int) db_value(
        'SELECT COUNT(*) FROM role_grants WHERE revoked_at IS NULL AND role IN (?, ?)',
        ['admin', 'code_editor']
    );
}

/**
 * Does this grant name the person making the change, directly or through one
 * of their groups? The Studio rule: nobody hands code editing to themselves.
 */
function role_names_actor(string $kind, string $principal, string $by, array $byGroups): bool
{
    if ($kind === 'netid') {
        return $principal === strtolower($by);
    }
    return in_array($principal, array_map('strtolower', $byGroups), true);
}

/**
 * Record a grant and return its id. $by is the NetID of the administrator
 * making it, or ROLE_CONSOLE from tools/roles.php; $byGroups their groups.
 * Throws InvalidArgumentException with a sentence fit for the screen.
 */
function role_grant(string $kind, string $principal, string $role, ?int $labId, string $note, string $by, array $byGroups = []): int
{
    $principal = strtolower(trim($principal));
    if (!in_array($kind, ROLE_KINDS, true)) {
        throw new InvalidArgumentException('A grant names a NetID or a group.');
    }
    if ($kind === 'netid' ? !role_valid_netid($principal) : !role_valid_group($principal)) {
        throw new InvalidArgumentException('"' . $principal . '" is not a valid ' . ($kind === 'netid' ? 'NetID' : 'group name') . '.');
    }
    if (!in_array($role, ROLE_NAMES, true)) {
        throw new InvalidArgumentException('The role must be member, admin or code_editor.');
    }
    if ($labId !== null && $role !== 'member') {
        throw new InvalidArgumentException('Only a member grant names a laboratory; administrators see every laboratory.');
    }
    if ($labId !== null && !db_one('SELECT lab_id FROM labs WHERE lab_id = ?', [$labId])) {
        throw new InvalidArgumentException('There is no laboratory with that id.');
    }
    if ($role === 'code_editor' && $by !== ROLE_CONSOLE && role_names_actor($kind, $principal, $by, $byGroups)) {
        throw new InvalidArgumentException('You cannot grant code editing to yourself or to a group you belong to. '
            . 'Ask another administrator, or run tools/roles.php on the server.');
    }
    $dup = $labId === null
        ? db_one('SELECT grant_id FROM role_grants WHERE revoked_at IS NULL AND principal_kind = ? AND principal = ? AND role = ? AND lab_id IS NULL',
            [$kind, $principal, $role])
        : db_one('SELECT grant_id FROM role_grants WHERE revoked_at IS NULL AND principal_kind = ? AND principal = ? AND role = ? AND lab_id = ?',
            [$kind, $principal, $role, $labId]);
    if ($dup) {
        throw new InvalidArgumentException(($kind === 'group' ? 'group:' : '') . $principal . ' already holds that grant (number ' . $dup['grant_id'] . ').');
    }
    db_run(
        'INSERT INTO role_grants (principal_kind, principal, role, lab_id, note, granted_by, granted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$kind, $principal, $role, $labId, trim($note), $by, date('Y-m-d H:i:s')]
    );
    return (int) db_last_id();
}

/**
 * Revoke a grant: the row stays, stamped. From a browser nobody revokes a
 * grant that names themselves, and nobody revokes the last grant that opens
 * the administrative panel, because both would lock the panel with no way
 * back in short of the command line.
 */
function role_revoke(int $grantId, string $by, array $byGroups = []): array
{
    $row = role_grant_by_id($grantId);
    if (!$row) {
        throw new InvalidArgumentException('There is no grant number ' . $grantId . '.');
    }
    if ($row['revoked_at'] !== null) {
        throw new InvalidArgumentException('Grant ' . $grantId . ' was already revoked on ' . $row['revoked_at'] . '.');
    }
    if ($by !== ROLE_CONSOLE) {
        if (role_names_actor((string) $row['principal_kind'], (string) $row['principal'], $by, $byGroups)
            && $row['role'] !== 'member') {
            throw new InvalidArgumentException('That grant names you. Ask another administrator to revoke it.');
        }
        if (in_array($row['role'], ['admin', 'code_editor'], true) && roles_admin_grant_count() <= 1) {
            throw new InvalidArgumentException('That is the last grant that opens the administrative panel. Grant it to somebody else first.');
        }
    }
    db_run('UPDATE role_grants SET revoked_at = ?, revoked_by = ? WHERE grant_id = ? AND revoked_at IS NULL',
        [date('Y-m-d H:i:s'), $by, $grantId]);
    return role_grant_by_id($grantId);
}

/**
 * Seed the owner as superadmin: one code_editor grant for ROLE_OWNER_NETID.
 * Safe to run twice: a grant already in force is reported, not doubled.
 * Returns [grant id, true when this call created it].
 */
function role_seed_owner(string $by = ROLE_CONSOLE): array
{
    $have = db_one(
        'SELECT grant_id FROM role_grants WHERE revoked_at IS NULL AND principal_kind = ? AND principal = ? AND role = ? AND lab_id IS NULL',
        ['netid', ROLE_OWNER_NETID, ROLE_OWNER_ROLE]
    );
    if ($have) {
        return [(int) $have['grant_id'], false];
    }
    $id = role_grant('netid', ROLE_OWNER_NETID, ROLE_OWNER_ROLE, null,
        'owner, superadmin to start (' . ROLE_OWNER_EMAIL . '); assigns every other role', $by);
    return [$id, true];
}
