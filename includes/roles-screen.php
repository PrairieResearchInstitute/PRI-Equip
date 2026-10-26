<?php
/**
 * roles-screen.php: the Administrators screen in the NetID modes (D-015).
 *
 * admin/users.php hands over to this file when the campus login (or the
 * development sign-in) says who people are. It lists the role grants in force,
 * adds one for a NetID or a campus group, and revokes one. The rules live in
 * includes/roles.php: nobody grants code editing to themselves, nobody revokes
 * a grant naming themselves, and the last grant that opens the panel stays.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_installed();
$me = require_admin();          // again here, so a direct request to this file is refused too

$myGroups = (auth_principal() ?? [])['groups'] ?? [];
$errors   = [];

if (!roles_table_ready()) {
    admin_header('users', 'Administrators');
    echo '<h1>No Role Grants</h1>';
    echo '<p class="lede">This database has no role_grants table. Apply migration 0003 with tools/migrate.php, then seed the first administrator with tools/roles.php.</p>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'grant') {
            [$kind, $principal] = role_parse_principal((string) ($_POST['principal'] ?? ''));
            $role  = (string) ($_POST['role'] ?? '');
            $labId = (int) ($_POST['lab_id'] ?? 0);
            $id = role_grant($kind, $principal, $role, $role === 'member' && $labId > 0 ? $labId : null,
                (string) ($_POST['note'] ?? ''), (string) $me['username'], $myGroups);
            flash('Grant ' . $id . ' added: ' . ($kind === 'group' ? 'group:' : '') . $principal . ', ' . role_label($role) . '.');
            redirect('users.php');
        }
        if ($action === 'revoke') {
            if (needs_confirmation()) {
                confirm_page('Revoke Grant',
                             'Revoke grant ' . (int) ($_POST['grant_id'] ?? 0) . '?',
                             'Revoke', $_POST, 'users.php', true);
            }
            $row = role_revoke((int) ($_POST['grant_id'] ?? 0), (string) $me['username'], $myGroups);
            flash('Grant ' . $row['grant_id'] . ' revoked. The row stays in the history.');
            redirect('users.php');
        }
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    }
}

$grants  = roles_active();
$history = roles_history(50);

admin_header('users', 'Administrators');
?>
<h1>Administrators</h1>
<p class="lede">The campus login says who a person is. These grants say what that NetID, or everyone in a
campus group, may do here. You are signed in as <strong><?= h($me['username']) ?></strong>.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="stack">
  <div class="card card-tight">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>NetID or group</th><th>Role</th><th>Laboratory</th><th>Note</th><th>Granted</th><th></th></tr></thead>
        <tbody>
        <?php if (!$grants): ?>
          <tr><td colspan="6" class="empty">No grants yet. Seed the first administrator with tools/roles.php on the server.</td></tr>
        <?php endif; ?>
        <?php foreach ($grants as $g): ?>
          <tr>
            <td><code><?= h(role_principal_text($g)) ?></code>
              <?= role_names_actor((string) $g['principal_kind'], (string) $g['principal'], (string) $me['username'], $myGroups) ? ' <span class="pill pill-ok">you</span>' : '' ?></td>
            <td><?= h(role_label((string) $g['role'])) ?></td>
            <td><?= $g['lab_id'] !== null ? h((string) $g['lab_name']) : '<span class="muted">' . ($g['role'] === 'member' ? 'none named' : 'every laboratory') . '</span>' ?></td>
            <td><?= h((string) $g['note']) ?></td>
            <td class="nowrap"><?= h(pretty_date((string) $g['granted_at'])) ?> by <?= h((string) $g['granted_by']) ?></td>
            <td class="nowrap">
              <form method="post" data-confirm="Revoke grant <?= (int) $g['grant_id'] ?>?"
                    data-confirm-ok="Revoke" data-confirm-danger>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="grant_id" value="<?= (int) $g['grant_id'] ?>">
                <button type="submit" class="button button-danger button-small">Revoke</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="grant">
    <h2 class="card-heading">Add a Grant</h2>
    <div class="field">
      <label for="g_principal">NetID or group</label>
      <input type="text" id="g_principal" name="principal" required autocomplete="off" placeholder="eruiz2 or group:pri-pacelab-staff">
      <p class="hint">Write a campus group as group: followed by its name, as the campus login passes it.</p>
    </div>
    <div class="field">
      <label for="g_role">Role</label>
      <select id="g_role" name="role">
        <?php foreach (ROLE_NAMES as $r): ?>
        <option value="<?= h($r) ?>"><?= h(role_label($r)) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="hint">Code editing is never granted to yourself or to a group you belong to.</p>
    </div>
    <div class="field">
      <label for="g_lab">Laboratory <span class="muted">(member grants only)</span></label>
      <select id="g_lab" name="lab_id">
        <option value="0">None</option>
        <?php foreach (labs() as $lab): ?>
        <option value="<?= (int) $lab['lab_id'] ?>"><?= h($lab['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="g_note">Note <span class="muted">(optional)</span></label>
      <input type="text" id="g_note" name="note" autocomplete="off">
    </div>
    <div class="form-actions">
      <button type="submit" class="button">Add Grant</button>
    </div>
  </form>

  <?php accordion_open('roles-history', 'History', ['meta' => count($history) . ' most recent']); ?>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>No.</th><th>NetID or group</th><th>Role</th><th>Laboratory</th><th>Granted</th><th>Revoked</th></tr></thead>
        <tbody>
        <?php foreach ($history as $g): ?>
          <tr<?= $g['revoked_at'] !== null ? ' class="is-locked"' : '' ?>>
            <td><?= (int) $g['grant_id'] ?></td>
            <td><code><?= h(role_principal_text($g)) ?></code></td>
            <td><?= h(role_label((string) $g['role'])) ?></td>
            <td><?= h((string) ($g['lab_code'] ?? '')) ?></td>
            <td class="nowrap"><?= h((string) $g['granted_at']) ?> by <?= h((string) $g['granted_by']) ?></td>
            <td class="nowrap"><?= $g['revoked_at'] !== null ? h((string) $g['revoked_at']) . ' by ' . h((string) $g['revoked_by']) : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php accordion_close(); ?>
</div>
<?php
page_footer();
