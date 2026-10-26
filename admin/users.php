<?php
/**
 * admin/users.php — Administrator accounts.
 *
 * Passwords are stored as password_hash output and never in any other form.
 * The last active account cannot be deactivated, because that would lock the
 * panel with no way back in short of editing the database by hand.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
$me = require_admin();

// The NetID modes (D-015) have no accounts or passwords here: this screen
// manages role grants instead, and the account screen below never runs.
if (auth_uses_netid()) {
    require __DIR__ . '/../includes/roles-screen.php';
    exit;
}

$errors = [];

/** How many accounts can still sign in. */
function active_admin_count(): int
{
    return (int) db_value('SELECT COUNT(*) FROM admin_users WHERE active = 1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($action === 'code') {
        if ($userId !== (int) $me['user_id'] && needs_confirmation()) {
            $holder = db_one('SELECT username, may_edit_code FROM admin_users WHERE user_id = ?', [$userId]);
            $has    = (int) ($holder['may_edit_code'] ?? 0) === 1;
            confirm_page($has ? 'Remove Code Editing' : 'Grant Code Editing',
                         ($has ? 'Remove' : 'Grant') . ' code editing for '
                             . (string) ($holder['username'] ?? 'this account') . '?',
                         $has ? 'Remove' : 'Grant', $_POST, 'users.php', $has);
        }
        // Nobody may hand it to themselves. One person with an account and a
        // browser should not be able to turn that into permission to rewrite
        // the application; it takes a second administrator, or the console.
        if ($userId === (int) $me['user_id']) {
            flash('You cannot change your own code-editing grant. Ask another administrator, '
                . 'or run: php admin-recovery.php grant <username>', 'error');
            redirect('users.php');
        }
        $target = db_one('SELECT * FROM admin_users WHERE user_id = ?', [$userId]);
        if (!$target) {
            flash('No such administrator.', 'error');
            redirect('users.php');
        }
        $now = (int) ($target['may_edit_code'] ?? 0) === 1 ? 0 : 1;
        db_run('UPDATE admin_users SET may_edit_code = ? WHERE user_id = ?', [$now, $userId]);
        flash($target['username'] . ($now ? ' may now edit the code.' : ' may no longer edit the code.'));
        redirect('users.php');
    }

    if ($action === 'add') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $display  = trim((string) ($_POST['display_name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '')                    { $errors[] = 'Choose a username.'; }
        if (strlen($password) < 10)              { $errors[] = 'The password must be at least ten characters.'; }
        if ($password !== ($_POST['password_confirm'] ?? '')) { $errors[] = 'The two passwords do not match.'; }
        if (db_one('SELECT user_id FROM admin_users WHERE username = ?', [$username])) {
            $errors[] = 'That username is taken.';
        }

        if (!$errors) {
            db_run(
                'INSERT INTO admin_users (username, password_hash, display_name, active, created_at)
                 VALUES (?, ?, ?, 1, ?)',
                [$username, password_hash($password, PASSWORD_DEFAULT), $display ?: $username, date('Y-m-d H:i:s')]
            );
            flash('Administrator ' . $username . ' added.');
            redirect('users.php');
        }
    }

    if ($action === 'reset') {
        $password = (string) ($_POST['password'] ?? '');
        $target   = db_one('SELECT * FROM admin_users WHERE user_id = ?', [$userId]);

        if (!$target)                            { $errors[] = 'That account no longer exists.'; }
        if (strlen($password) < 10)              { $errors[] = 'The new password must be at least ten characters.'; }
        if ($password !== ($_POST['password_confirm'] ?? '')) { $errors[] = 'The two passwords do not match.'; }

        if (!$errors) {
            db_run('UPDATE admin_users SET password_hash = ? WHERE user_id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $userId]);
            flash('Password reset for ' . $target['username'] . '.');
            redirect('users.php');
        }
    }

    if ($action === 'toggle') {
        $target = db_one('SELECT * FROM admin_users WHERE user_id = ?', [$userId]);
        if ($target && needs_confirmation()) {
            $off = (int) $target['active'] === 1;
            confirm_page($off ? 'Deactivate Account' : 'Reactivate Account',
                         ($off ? 'Deactivate ' : 'Reactivate ') . (string) $target['username'] . '?',
                         $off ? 'Deactivate' : 'Reactivate', $_POST, 'users.php');
        }
        if (!$target) {
            flash('That account no longer exists.', 'error');
        } elseif ((int) $target['active'] === 1 && active_admin_count() <= 1) {
            flash('This is the only account that can still sign in. Add another administrator before deactivating it.', 'error');
        } elseif ((int) $target['active'] === 1 && (int) $target['user_id'] === (int) $me['user_id']) {
            flash('Deactivating your own account would sign you straight out. Ask another administrator to do it.', 'error');
        } else {
            db_run('UPDATE admin_users SET active = ? WHERE user_id = ?',
                [(int) $target['active'] === 1 ? 0 : 1, $userId]);
            flash($target['username'] . ((int) $target['active'] === 1 ? ' deactivated.' : ' reactivated.'));
        }
        redirect('users.php');
    }
}

$resetting = isset($_GET['reset']) ? db_one('SELECT * FROM admin_users WHERE user_id = ?', [(int) $_GET['reset']]) : null;
$admins    = db_all('SELECT * FROM admin_users ORDER BY active DESC, lower(username)');

admin_header('users', 'Administrators');
?>
<h1>Administrators</h1>
<p class="lede">These accounts open the administrative panel and close a monthly export. Laboratory users need no account at all.</p>

<?php foreach ($errors as $error): ?>
  <div class="flash flash-error"><?= h($error) ?></div>
<?php endforeach; ?>

<div class="stack">
  <div class="card card-tight">
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Username</th><th>Name</th><th>Added</th><th>Last signed in</th><th>May edit code</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($admins as $row): ?>
          <tr<?= (int) $row['active'] === 0 ? ' class="is-locked"' : '' ?>>
            <td>
              <strong><?= h($row['username']) ?></strong>
              <?= (int) $row['user_id'] === (int) $me['user_id'] ? ' <span class="pill pill-ok">You</span>' : '' ?>
              <?= (int) $row['active'] === 0 ? ' <span class="pill">inactive</span>' : '' ?>
            </td>
            <td><?= h($row['display_name']) ?></td>
            <td class="nowrap"><?= h(pretty_date($row['created_at'])) ?></td>
            <td class="nowrap"><?= $row['last_login'] ? h(pretty_datetime($row['last_login'])) : '<span class="muted">never</span>' ?></td>
            <td class="nowrap">
              <?php
                // Editing the application is a second grant, not a consequence of
                // being an administrator. It gets its own column so that who holds
                // it is obvious without opening anything.
                $hasCode = (int) ($row['may_edit_code'] ?? 0) === 1;
              ?>
              <form method="post"
                    data-confirm="<?= $hasCode ? 'Remove' : 'Grant' ?> code editing for <?= h($row['username']) ?>?"
                    data-confirm-ok="<?= $hasCode ? 'Remove' : 'Grant' ?>"<?= $hasCode ? ' data-confirm-danger' : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="code">
                <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                <button type="submit" class="button <?= $hasCode ? '' : 'button-secondary ' ?>button-small"><?= $hasCode ? 'Granted' : 'Not granted' ?></button>
              </form>
            </td>
            <td class="nowrap">
              <div class="button-row">
                <a class="button button-secondary button-small" href="?reset=<?= (int) $row['user_id'] ?>">Reset Password</a>
                <form method="post"
                      data-confirm="<?= (int) $row['active'] === 1 ? 'Deactivate' : 'Reactivate' ?> <?= h($row['username']) ?>?"
                      data-confirm-ok="<?= (int) $row['active'] === 1 ? 'Deactivate' : 'Reactivate' ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="user_id" value="<?= (int) $row['user_id'] ?>">
                  <button type="submit" class="button button-secondary button-small"><?= (int) $row['active'] === 1 ? 'Deactivate' : 'Reactivate' ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <?php if ($resetting): ?>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reset">
      <input type="hidden" name="user_id" value="<?= (int) $resetting['user_id'] ?>">
      <h2 class="card-heading">Reset Password for <?= h($resetting['username']) ?></h2>
      <div class="field">
        <label for="r_password">New password</label>
        <input type="password" id="r_password" name="password" required minlength="10" autocomplete="new-password">
      </div>
      <div class="field">
        <label for="r_password2">New password again</label>
        <input type="password" id="r_password2" name="password_confirm" required minlength="10" autocomplete="new-password">
      </div>
      <div class="form-actions">
        <button type="submit" class="button">Reset Password</button>
        <a class="link-quiet" href="users.php">Cancel</a>
      </div>
    </form>
    <?php endif; ?>

    <form method="post" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <h2 class="card-heading">Add an Administrator</h2>

      <div class="field">
        <label for="a_username">Username</label>
        <input type="text" id="a_username" name="username" required autocomplete="off">
      </div>
      <div class="field">
        <label for="a_display">Display name</label>
        <input type="text" id="a_display" name="display_name" autocomplete="off">
        <p class="hint">Stamped on exports and void reasons.</p>
      </div>
      <div class="field">
        <label for="a_password">Password</label>
        <input type="password" id="a_password" name="password" required minlength="10" autocomplete="new-password">
      </div>
      <div class="field">
        <label for="a_password2">Password again</label>
        <input type="password" id="a_password2" name="password_confirm" required minlength="10" autocomplete="new-password">
      </div>

      <div class="form-actions">
        <button type="submit" class="button">Add Administrator</button>
      </div>
    </form>
  </div>
</div>
<?php
page_footer();
