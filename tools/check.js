#!/usr/bin/env node
'use strict';
/**
 * tools/check.js: the one self-check (prep P19, .claude/gate.json).
 *
 *   node tools/check.js            healthy output ends with CHECK OK
 *
 * 1. The regression suite: php tests/run-tests.php must end "All N checks passed."
 *    (it builds its own scratch database and never opens the live one).
 * 2. The data folder: config.local.json must exist when the drive data folder
 *    ..\..\data\pacelab-equip exists; the PHP loader (tools/config.php show) and
 *    the PowerShell resolver (tools/paths.ps1) must name the same folder; that
 *    folder must hold lab.sqlite and .installed and lie outside the application
 *    folder and outside OneDrive.
 * 3. The live database, read only: lab.sqlite (and any -wal) is copied to a
 *    temporary folder, and the copy passes integrity_check and reports its row
 *    counts. Nothing in the data folder is opened for writing.
 * 4. PostgreSQL, when credentials exist (pg.env beside the data folder, .env, or
 *    the environment; tools/pg-status.php --quiet decides without printing a
 *    value): the suite again with PACELAB_TEST_DRIVER=pgsql on the test
 *    database, then tools/pg-load.php loads a copy of lab.sqlite into the test
 *    database, must print VERIFIED, and empties it again (--clean-after). When
 *    the application runs on PostgreSQL, tools/pg-status.php must also reach
 *    the live database. Without credentials the section says so and passes.
 * 5. The authorship credit (drez-ui-elements rule 27): page_footer() in
 *    includes/functions.php and the NOTICE file must both carry the exact line.
 * A failing run ends with CHECK FAILED: n.
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const APP = path.resolve(__dirname, '..');
const DRIVE_DATA = path.resolve(APP, '..', '..', 'data', 'pacelab-equip');
const problems = [];
const say = (s) => console.log(s);
const fail = (s) => { problems.push(s); say('  FAIL ' + s); };
const ok = (s) => say('  ok   ' + s);

// The authorship credit (drez-ui-elements rule 27). Cheap and static, so it runs first.
const CREDIT = 'Developed by M. J. Dreslik, with Claude Opus 5';
{
  const read = (f) => { try { return fs.readFileSync(path.join(APP, f), 'utf8'); } catch (_) { return null; } };
  const fn = read('includes/functions.php') || '';
  const foot = fn.slice(fn.indexOf('function page_footer'));
  if (foot.includes('<p class="app-credit">' + CREDIT + '</p>')) ok('the footer carries the credit line');
  else fail('page_footer() in includes/functions.php lacks the credit line "' + CREDIT + '"');
  const notice = read('NOTICE');
  if (notice && notice.includes(CREDIT)) ok('NOTICE carries the credit');
  else fail('NOTICE is missing or lacks "' + CREDIT + '"');
}

// The bundled PHP only on Windows: an application on the estate drive runs
// nothing from the computer. A server (Linux) uses its own php on PATH.
function findPhp() {
  const d = path.join(APP, 'tools', 'php');
  const exe = path.join(d, 'php.exe');
  if (fs.existsSync(exe)) return { cmd: exe, pre: ['-c', path.join(d, 'php.ini'), '-d', 'extension_dir=' + path.join(d, 'ext')] };
  if (process.platform === 'win32') return null;
  const r = spawnSync('php', ['-v'], { encoding: 'utf8' });
  return r.status === 0 ? { cmd: 'php', pre: [] } : null;
}
function php(p, args, timeoutMs, extraEnv) {
  const env = extraEnv ? Object.assign({}, process.env, extraEnv) : process.env;
  const r = spawnSync(p.cmd, p.pre.concat(args), { cwd: APP, encoding: 'utf8', timeout: timeoutMs || 120000, maxBuffer: 1 << 26, env });
  return { status: r.status, out: (r.stdout || '') + (r.stderr || ''), stdout: r.stdout || '' };
}
const lastLines = (s, n) => String(s).trim().split(/\r?\n/).slice(-n);

const p = findPhp();
if (!p) {
  say('  FAIL the bundled PHP tools\\php\\php.exe is missing: run SETUP.bat, or copy tools\\php into this folder from the drive');
  say('CHECK FAILED: 1');
  process.exit(1);
}

// ---------------------------------------------------------------- 1. tests
say('Tests');
const t = php(p, ['tests/run-tests.php'], 300000);
const passed = /All (\d+) checks passed\./.exec(t.out); // dialect-ok: the gate reads a temporary SQLite copy; RegExp.exec is not SQL
if (t.status === 0 && passed) ok(passed[0]);
else fail('tests/run-tests.php exited ' + t.status + ': ' + lastLines(t.out, 3).join(' | '));
if (process.platform === 'win32') {
  // The PowerShell twin of the drive-invariance checks: paths.ps1, the launcher and the shortcuts.
  const di = spawnSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(APP, 'tests', 'drive-invariance.ps1')], { encoding: 'utf8', timeout: 120000 });
  const diOut = (di.stdout || '') + (di.stderr || '');
  const diPassed = /All (\d+) drive-invariance checks passed\./.exec(diOut); // dialect-ok: RegExp.exec on test output, not SQL
  if (di.status === 0 && diPassed) ok(diPassed[0]);
  else fail('tests/drive-invariance.ps1 exited ' + di.status + ': ' + lastLines(diOut, 3).join(' | '));
}

// ---------------------------------------------------------------- 2. data folder
say('Data folder');
const cfgFile = path.join(APP, 'config.local.json');
const driveDataExists = fs.existsSync(DRIVE_DATA);
if (!fs.existsSync(cfgFile)) {
  if (driveDataExists) fail('config.local.json is missing while the drive data folder ' + DRIVE_DATA + ' exists: the app would use data\\ in its own folder. Run SETUP.bat.');
  else ok('no config.local.json and no drive data folder: the app uses data\\ in its own folder');
}
let info = null;
const show = php(p, ['tools/config.php', 'show', '--json']);
try { info = JSON.parse(show.stdout.slice(show.stdout.indexOf('{'))); } catch (_) { fail('tools/config.php show gave no JSON: ' + lastLines(show.out, 2).join(' | ')); }

if (info) {
  say('  PHP loader resolves:');
  for (const k of ['dataFolder', 'dataDir', 'database', 'installLock', 'serverLog', 'backupDir', 'tempDir', 'driver']) say('    ' + k.padEnd(12) + info[k]);
  if (process.platform === 'win32') {
    const ps = spawnSync('powershell.exe', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', path.join(APP, 'tools', 'paths.ps1')], { encoding: 'utf8', timeout: 60000 });
    let pj = null;
    try { pj = JSON.parse(ps.stdout.slice(ps.stdout.indexOf('{'))); } catch (_) { /* reported below */ }
    if (!pj) fail('tools/paths.ps1 gave no JSON: ' + lastLines((ps.stdout || '') + (ps.stderr || ''), 2).join(' | '));
    else {
      const same = (a, b) => path.resolve(a).toLowerCase() === path.resolve(b).toLowerCase();
      const diff = ['dataDir', 'database', 'backupDir', 'tempDir'].filter((k) => !same(pj[k], info[k]));
      if (diff.length) fail('PowerShell and PHP disagree on ' + diff.map((k) => k + ' (' + pj[k] + ' vs ' + info[k] + ')').join(', '));
      else ok('tools/paths.ps1 resolves the same data, database, backup and temp paths');
    }
  }
  const inside = (child, parent) => { const r = path.relative(parent, child); return r === '' || (!r.startsWith('..') && !path.isAbsolute(r)); };
  if (driveDataExists) {
    if (inside(info.dataDir, APP)) fail('the data folder ' + info.dataDir + ' is inside the application folder while the drive data folder exists');
    else if (!inside(info.dataDir, DRIVE_DATA)) fail('the data folder ' + info.dataDir + ' is not under ' + DRIVE_DATA);
    else ok('the data folder lies in the drive data folder ' + DRIVE_DATA);
  }
  if (/onedrive/i.test(info.dataDir)) fail('the data folder ' + info.dataDir + ' is inside OneDrive');
  if (!fs.existsSync(info.database)) fail('no database at ' + info.database);
  else ok('lab.sqlite present (' + fs.statSync(info.database).size + ' bytes)');
  if (fs.existsSync(info.database) && !fs.existsSync(info.installLock)) fail('no installer lock at ' + info.installLock + ': install.php would run again');
  else if (fs.existsSync(info.installLock)) ok('.installed present');

  // ------------------------------------------------------------ 3. read only
  if (fs.existsSync(info.database)) {
    say('Live database (read through a copy)');
    // The copy goes to the drive, never the computer's temp folder (owner standard B, D-007).
    const tmpBase = (info.tempDir || '').trim() || path.join(APP, 'data', 'tmp');
    fs.mkdirSync(tmpBase, { recursive: true });
    const tmp = fs.mkdtempSync(path.join(tmpBase, 'pacelab-gate-'));
    try {
      const copy = path.join(tmp, 'lab.sqlite');
      fs.copyFileSync(info.database, copy);
      if (fs.existsSync(info.database + '-wal')) fs.copyFileSync(info.database + '-wal', copy + '-wal');
      const probe = path.join(tmp, 'probe.php');
      fs.writeFileSync(probe, '<?php $p = new PDO("sqlite:" . $argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);'
        + ' echo "integrity ", $p->query("PRAGMA integrity_check")->fetchColumn(), "\\n";' // db-calls: allow the gate reads a temporary copy of the database, never the live file; dialect-ok: the gate reads a temporary SQLite copy; RegExp.exec is not SQL
        + ' foreach ($p->query("SELECT name FROM sqlite_master WHERE type = \'table\' AND name NOT LIKE \'sqlite_%\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t)' // db-calls: allow the gate reads a temporary copy of the database, never the live file; dialect-ok: the gate reads a temporary SQLite copy; RegExp.exec is not SQL
        + ' { echo $t, "=", $p->query("SELECT COUNT(*) FROM \\"" . $t . "\\"")->fetchColumn(), " "; } echo "\\n";'); // db-calls: allow the gate reads a temporary copy of the database, never the live file
      const r = php(p, [probe, copy]);
      const integ = /integrity (\S+)/.exec(r.out); // dialect-ok: the gate reads a temporary SQLite copy; RegExp.exec is not SQL
      if (!integ || integ[1] !== 'ok') fail('integrity_check on the copy: ' + lastLines(r.out, 2).join(' | '));
      else ok('integrity_check ok on a copy of ' + info.database);
      const counts = lastLines(r.stdout, 1)[0] || '';
      if (!/equipment=\d+/.test(counts)) fail('no equipment table in the copy');
      else say('    rows: ' + counts.trim());
    } finally {
      fs.rmSync(tmp, { recursive: true, force: true });
    }
  }
}

// ---------------------------------------------------------------- 4. PostgreSQL
say('PostgreSQL');
let probe = php(p, ['tools/pg-status.php', '--quiet']);
// One retry: a probe that returns no status and no output (seen once, 2026-10-10)
// is the process failing to start, not the database answering no.
if (probe.status !== 0 && probe.status !== 3) probe = php(p, ['tools/pg-status.php', '--quiet']);
if (probe.status === 3) {
  ok('no PostgreSQL credentials (pg.env beside the data folder, .env or the environment): PostgreSQL checks skipped');
  if (info && info.driver === 'pgsql') fail('the application is switched to PostgreSQL but no credentials were found');
} else if (probe.status !== 0) {
  fail('the PostgreSQL test database did not answer (exit ' + probe.status + '): ' + lastLines(probe.out, 2).join(' | ') + ' Run php tools/pg-status.php --test for the reason.');
} else {
  const tp = php(p, ['tests/run-tests.php'], 300000, { PACELAB_TEST_DRIVER: 'pgsql' });
  const tpPassed = /All (\d+) checks passed\./.exec(tp.out); // dialect-ok: RegExp.exec on test output, not SQL
  if (tp.status === 0 && tpPassed) ok('on PostgreSQL: ' + tpPassed[0]);
  else fail('tests/run-tests.php on PostgreSQL exited ' + tp.status + ': ' + lastLines(tp.out, 3).join(' | '));
  if (info && fs.existsSync(info.database)) {
    const tmpBase = (info.tempDir || '').trim() || path.join(APP, 'data', 'tmp');
    fs.mkdirSync(tmpBase, { recursive: true });
    const tmp = fs.mkdtempSync(path.join(tmpBase, 'pacelab-gate-pg-'));
    try {
      const copy = path.join(tmp, 'lab.sqlite');
      fs.copyFileSync(info.database, copy);
      if (fs.existsSync(info.database + '-wal')) fs.copyFileSync(info.database + '-wal', copy + '-wal');
      const ld = php(p, ['tools/pg-load.php', '--source', copy, '--clean-after'], 300000);
      const v = /VERIFIED: .*/.exec(ld.out); // dialect-ok: RegExp.exec on loader output, not SQL
      if (ld.status === 0 && v) ok('loader: ' + v[0]);
      else fail('tools/pg-load.php exited ' + ld.status + ': ' + lastLines(ld.out, 3).join(' | '));
    } finally {
      fs.rmSync(tmp, { recursive: true, force: true });
    }
  }
  if (info && info.driver === 'pgsql') {
    const live = php(p, ['tools/pg-status.php']);
    if (live.status === 0) ok('the application runs on PostgreSQL and the live database answers');
    else fail('the application runs on PostgreSQL and the live database did not answer: ' + lastLines(live.out, 2).join(' | '));
  }
}

if (problems.length) { say('CHECK FAILED: ' + problems.length); process.exit(1); }
say('CHECK OK');
