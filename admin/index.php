<?php
/**
 * admin/index.php — Administrative overview.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_installed();
$admin = require_admin();

$counts = [
    'equipment'   => (int) db_value('SELECT COUNT(*) FROM equipment WHERE lab_id = ? AND active = 1', [current_lab_id()]),
    'retired'     => (int) db_value('SELECT COUNT(*) FROM equipment WHERE lab_id = ? AND active = 0', [current_lab_id()]),
    'grants'      => (int) db_value('SELECT COUNT(*) FROM grants WHERE lab_id = ? AND active = 1', [current_lab_id()]),
    'expiring'    => (int) db_value(
        'SELECT COUNT(*) FROM grants WHERE lab_id = ? AND active = 1
           AND end_date IS NOT NULL AND end_date <> \'\' AND end_date BETWEEN ? AND ?',
        [current_lab_id(), date('Y-m-d'), date('Y-m-d', strtotime('+60 days'))]),

    // Charges and reservations belong to a laboratory through the equipment they
    // were made against, so they are counted through that join rather than by
    // a column of their own that could drift out of step with it.
    'unexported'  => (int) db_value(
        'SELECT COUNT(*) FROM usage_records r JOIN equipment e ON e.equipment_id = r.equipment_id
          WHERE e.lab_id = ? AND r.exported = 0 AND r.voided = 0', [current_lab_id()]),
    'unexported_amount' => (float) db_value(
        'SELECT COALESCE(SUM(r.total_charge), 0) FROM usage_records r
           JOIN equipment e ON e.equipment_id = r.equipment_id
          WHERE e.lab_id = ? AND r.exported = 0 AND r.voided = 0', [current_lab_id()]),
    'upcoming'    => (int) db_value(
        'SELECT COUNT(*) FROM reservations res JOIN equipment e ON e.equipment_id = res.equipment_id
          WHERE e.lab_id = ? AND res.end_datetime >= ?', [current_lab_id(), date('Y-m-d H:i:s')]),
];

$recentBatches = db_all('SELECT * FROM export_batches WHERE lab_id = ? ORDER BY generated_at DESC LIMIT 5', [current_lab_id()]);
$expiring = db_all(
    'SELECT g.*, u.code AS unit_code FROM grants g LEFT JOIN units u ON u.unit_id = g.unit_id
      WHERE g.lab_id = ? AND g.active = 1 AND g.end_date IS NOT NULL AND g.end_date <> \'\'
        AND g.end_date BETWEEN ? AND ?
      ORDER BY g.end_date',
    [current_lab_id(), date('Y-m-d'), date('Y-m-d', strtotime('+60 days'))]
);

admin_header('index', 'Administration');
?>
<div class="page-head">
  <div>
    <h1><?= h(setting('admin_title', 'Administration')) ?></h1>
    <p class="lede">Signed in as <?= h($admin['display_name'] ?: $admin['username']) ?>.</p>
  </div>
  <form method="post" action="logout.php">
    <?= csrf_field() ?>
    <button type="submit" class="button button-secondary button-small">Sign Out</button>
  </form>
</div>

<div class="totals-strip">
  <div class="total-tile">
    <div class="label">Active equipment</div>
    <div class="value"><?= $counts['equipment'] ?></div>
    <p class="hint"><?= $counts['retired'] ?> retired</p>
  </div>
  <div class="total-tile">
    <div class="label">Active grants</div>
    <div class="value"><?= $counts['grants'] ?></div>
    <p class="hint"><?= $counts['expiring'] ?> ending within 60 days</p>
  </div>
  <div class="total-tile">
    <div class="label">Waiting to export</div>
    <div class="value"><?= $counts['unexported'] ?></div>
    <p class="hint"><?= h(money($counts['unexported_amount'])) ?> in charges</p>
  </div>
  <div class="total-tile">
    <div class="label">Reservations ahead</div>
    <div class="value"><?= $counts['upcoming'] ?></div>
    <p class="hint">now and later</p>
  </div>
</div>

<?php if ($counts['equipment'] === 0 || $counts['grants'] === 0): ?>
  <div class="flash flash-notice">
    Seed the reference data before anyone uses the entry form:
    <?= $counts['equipment'] === 0 ? 'add at least one piece of equipment' : '' ?><?= ($counts['equipment'] === 0 && $counts['grants'] === 0) ? ' and ' : '' ?><?= $counts['grants'] === 0 ? 'add at least one grant' : '' ?>.
  </div>
<?php endif; ?>

<h2>What this panel covers</h2>
<?php
/*
 * One card per admin_pages() entry, in that order, so a screen added to the
 * list cannot be reachable from the breadcrumb and invisible here, which is
 * how Laboratories, the message log and the Studio went missing (PCE-07).
 * A screen with no sentence here still gets a card.
 */
$cardText = [
    'labs'         => 'The laboratories sharing this equipment, who belongs to each, and which one you are working in.',
    'equipment'    => 'Add, edit, and retire equipment. Retiring hides equipment from the dropdowns and preserves every historical charge.',
    'grants'       => 'CFOPA, title, short label, principal investigator, unit, and award dates. Expired grants leave the charge dropdown on their own.',
    'units'        => 'The survey codes offered when a grant is added.',
    'records'      => 'Correct or void any charge. A void needs a reason and leaves the row in place as an audit trail.',
    'costs'        => 'Consumables, repairs, parts, and the contracts that cover each equipment, set against what it has earned.',
    'reservations' => 'Cancel or reassign any reservation on any equipment.',
    'batches'      => 'Review past monthly exports and reopen one when the business office rejects it.',
    'emails'       => 'Every message the application has written, with the words it handed to Outlook and whether it was opened.',
    'settings'     => 'Laboratory name, page titles, and the instructions shown on each screen.',
    'users'        => auth_uses_netid()
        ? 'Who may act as an administrator or edit the code, by NetID or by group.'
        : 'Add or remove administrator accounts and reset passwords.',
    'studio'       => 'Edit the application\'s own source from inside it. Every save is parsed first and the previous copy is kept.',
];
?>
<div class="grid-cards">
  <?php foreach (admin_pages() as $key => [$label, $href]): ?>
    <?php if ($key === 'index') { continue; } ?>
    <?php if ($key === 'studio' && !auth_may_edit_code()) { continue; } ?>
    <a class="card" href="<?= h($href) ?>">
      <h3><?= h($label) ?></h3>
      <p><?= h($cardText[$key] ?? '') ?></p>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($expiring): ?>
<h2>Grants ending within sixty days</h2>
<div class="card card-tight">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Grant</th><th>CFOPA</th><th>Unit</th><th>Ends</th></tr></thead>
      <tbody>
      <?php foreach ($expiring as $g): ?>
        <tr>
          <td><a href="grants.php?edit=<?= (int) $g['grant_id'] ?>"><?= h($g['display_label'] ?: $g['title']) ?></a></td>
          <td><code><?= h($g['cfopa']) ?></code></td>
          <td><?= h($g['unit_code'] ?? '') ?></td>
          <td class="nowrap"><?= h(pretty_date($g['end_date'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($recentBatches): ?>
<h2>Recent exports</h2>
<div class="card card-tight">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Period</th><th>Generated</th><th>By</th><th class="num">Records</th><th class="num">Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recentBatches as $b): ?>
        <tr>
          <td class="nowrap"><?= h(pretty_date($b['period_start'])) ?> &ndash; <?= h(pretty_date($b['period_end'])) ?></td>
          <td class="nowrap"><?= h(pretty_datetime($b['generated_at'])) ?></td>
          <td><?= h($b['generated_by']) ?></td>
          <td class="num"><?= (int) $b['record_count'] ?></td>
          <td class="num"><?= h(money($b['total_amount'])) ?></td>
          <td><?= ((int) $b['reopened'] === 1) ? '<span class="pill pill-warn">Reopened</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p><a class="link-quiet" href="batches.php">All exports &rarr;</a></p>
<?php endif; ?>

<?php
page_footer();
