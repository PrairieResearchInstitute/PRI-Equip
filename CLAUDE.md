# PaCELab Equip: Shared Laboratory Equipment System

PHP 8 and SQLite (PostgreSQL through PDO when the driver is `pgsql`: `databaseDriver` in config.local.json, or `PACELAB_DRIVER` in a server's environment, D-010) web application: booking, use recording and grant billing for shared instruments, INHS and PRI. No Composer, no npm, no build step. Domain rules, traps and decisions already taken: read `AGENTS.md` before the first edit.

## Commands

| Command | Healthy output |
|---|---|
| `node tools/check.js` (the gate, `.claude/gate.json`) | last line `CHECK OK` |
| `tools\php\php.exe -c tools\php\php.ini -d extension_dir=tools\php\ext tests\run-tests.php` | `All 283 checks passed.` (or `powershell -File tools\run-tests.ps1`) |
| the same with `PACELAB_TEST_DRIVER=pgsql` (credentials from pg.env or .env) | `Running against Postgres database 'pacelab_test'.` then `All 286 checks passed.` |
| `php tools/pg-status.php` | which env files exist and the names they set (never a value); both PostgreSQL databases, owners, row counts, registry |
| `php tools/pg-load.php --source <copy of lab.sqlite>` | `VERIFIED: 196 rows across 14 tables match the SQLite source in 'pacelab_test'.` |
| `php tools/migrate.php --pg test --status` | `declared 1, applied ..., pending ...` |
| `powershell -File tests\drive-invariance.ps1` (the gate runs it) | `All 19 drive-invariance checks passed.` |
| `powershell -File tools\serve.ps1 status` (also `start`, `start -Open`, `stop`) | one line: `running: port 8147, php ..., supervisor ...` or `stopped: ...`, with the log path |
| `php tools/config.php show` (same PHP flags) | the resolved data, database and backup paths, the effective `driver` and `authMode` |
| `php tools/roles.php --pg test list` (or `--db <copy>`; `--pg live` writes need `--owner-approved`) | the role grants in force; also `grant`, `revoke`, `members`, `rekey` (app D-015) |
| `node ..\..\Skills\drez-app-prep\scripts\audit.js pacelab-equip` (env `DREZ_PHP`, `DREZ_PHP_EXT`) | one line per item P1 to P19 |
| `SETUP.bat /config-only` | creates config.local.json, prints the paths; without the switch it also runs `tools\setup-machine.ps1` (PHP check, server start; writes nothing on the computer) |
| `node <estate>\Skills\drez-containerize\scripts\make-launcher.js .` | `OK   .: Built PaCELab Equip.exe` and `OK   .: Built Stop PaCELab Equip.exe` |

Git on the drive needs `git -c safe.directory=*` on every command (platform D-011).

## Layout

- Pages at the root (`index.php`, `schedule.php`, `report.php`, `api.php`, `admin/`); `includes/db.php` is the one data-access file (`db_run`, `db_all`, `db_one`, `db_value`, `db_begin`, `db_last_id` and kin); `includes/schema.php` the SQLite schema `install.php` and the tests build from.
- `includes/local-config.php` the portable loader: `config.local.json` `dataFolder` moves lab.sqlite, `.installed`, the logs and studio backups; `tools/paths.ps1` is its PowerShell twin; `tools/config.php` the only writer.
- PostgreSQL is the estate cluster on the drive (platform D-028, D-013): 127.0.0.1:5433, runtime `<estate>\runtime\postgresql\17`, never a PostgreSQL installed on the computer. `tools/estate-pg.ps1 start|stop|status` (called only by `tools/serve.ps1`) runs `node estate-pg.js` when Node exists, else the same pg_ctl steps; it acts only when the driver is pgsql. No API server.
- `includes/env.php` reads the PG* names (environment, PACELAB_ENV_FILE, `pg.env` beside the data folder, `.env`); `includes/db_migrations.php` the migration runner shared by `tools/migrate.php`, `install.php` and the tests.
- Launchers (owner, 2026-11-03): `SETUP.bat` is the only `.bat`. `PaCELab Equip.exe` runs `tools\serve.ps1 start -Open` and `Stop PaCELab Equip.exe` runs `tools\serve.ps1 stop -Popup`, built from `launcher\launcher.ini` and `launcher-stop\launcher.ini`. `tools/serve.ps1` is the one owner of the server: a supervisor with a mutex, PHP on 127.0.0.1:8147 with `tools/router.php`, folder identity through `Test-LabSameFolder` in `tools/paths.ps1`. Other actions: `tools\run-tests.ps1`, `tools\backup.ps1 -Now`, `tools\build-release.ps1 -Open`, `tools\reset-admin-password.ps1`, `tools\setup-machine.ps1` (table in AGENTS.md).
- `tools/` backup (`VACUUM INTO`, or `tools/pg-backup.php` on PostgreSQL), setup; `tools/pg-load.php` the verified SQLite to PostgreSQL loader; `tools/pg-status.php` the read-only report; `tools/php` bundled PHP (git-ignored, protected).
- Identity (app D-015): `includes/auth.php` has three modes, `password` (default: typed names and admin_users), `local` (development sign-in, loopback and PHP's built-in server only) and `proxy` (Radiant: `X-Forwarded-Email` gives the NetID, `X-Forwarded-Groups` the groups). `includes/roles.php` and the `role_grants` table (migration 0003) give `member`, `admin` or `code_editor` to a NetID or a group; `includes/roles-screen.php` is the Administrators screen in the NetID modes; `tools/roles.php` seeds grants. Pages still ask only `is_admin()`, `require_admin()`, `current_user_name()` and `auth_may_edit_code()`.
- `migrations/` 0001 baseline, 0002 lab_id required, 0003 role grants, each a pair (shipped; install.php applies them on PostgreSQL); `Migration/` the earlier PostgreSQL scripts (load-data.php now points at tools/pg-load.php).
- Agent pack: `intent.md`, `plan.md`, `handoff.md`, `reference/context/DECISIONS.md` (through decisions.py; `docs/decisions.md` is a pointer); owner questions in `reference/open-questions.md`.

## Hard rules

- Live data: the `pacelab` PostgreSQL database (live since 2026-10-11, D-014), and `App_to_Migrate\data\pacelab-equip\data` (lab.sqlite, the pre-switch copy, .installed, .htaccess) and `..\backups`. Never edit, move or delete anything there; read the database through a copy.
- Never open, copy or print `.env` or `pg.env`; run PostgreSQL work through the app's own tools, which never print a value (a worktree passes another copy's file with `PACELAB_ENV_FILE`). The `pacelab` PostgreSQL database is written only with the owner's `--owner-approved`. Never start the server, `tools\serve.ps1 start`, the launchers or `tools\setup-machine.ps1` unless the task says so: the owner launches.
- Run gates and tests from the drive-letter spelling of this folder, never from `%SystemDrive%\Estate\App_to_Migrate` (owner, 2026-11-03): path-comparing checks fail falsely there. Day-to-day use works from either spelling.
- Start PostgreSQL (and `serve.ps1 start`) from a normal window, never "Run as administrator": PostgreSQL refuses an administrator account, and `serve.ps1` and `estate-pg.ps1` stop with that message.
- Everything from the drive: the only PHP is the bundled `tools\php` (missing means "run SETUP.bat", never a PHP on the computer), scratch stays beside the data folder, and no script reads APPDATA, LOCALAPPDATA or the Startup folder (the suite fails otherwise). The logon shortcut is the owner's one command in AGENTS.md, "Start at logon"; never write it from a script or a session.
- No `.bat`, `.cmd` or `.vbs` besides `SETUP.bat`; a new action is a `tools\*.ps1` command (the suite and the gate fail otherwise).
- Without config.local.json every path stays in `data\` inside the app folder; keep it that way.
- Before any UI work, read `docs/design.md` (the UI Elements spec, skill drez-ui-elements) and reuse the component it names.
- Pages reach the database only through `db_*` calls (D-015); a line that must differ carries `db-calls: allow` or `dialect-ok:` with its reason.
- Writing rules: no em dashes, no contractions, no "and/or", no sentence opening with a bare This, These, Those or That.

## Things Claude gets wrong here

- Treating the person as a typed last name everywhere: in the `local` and `proxy` modes `current_user_name()` is the NetID and `set_current_user_name()` does nothing. Test both modes; the suite switches with `auth_mode('<mode>')`, which only the command line honours. A new admin check goes through `is_admin()` or `require_admin()`, never a direct `admin_users` query.

- Pointing anything at the PostgreSQL service installed on the computer or its Program Files folder: the target is the drive cluster on port 5433, client programs from `lab_estate_pg_bin()`. Never run `estate-pg.js init` or read `superuser.env`.

- Adding a page or include without adding it to the ship list in `tools/build-release.ps1`: the release zip then lacks it.
- Writing `data\...` paths in PowerShell: use `Get-LabDataDir` and `Get-LabBackupDir` from `tools/paths.ps1`.
- Comparing two folder paths as text (`-eq`, `-like "*$root*"`, `GetFullPath` then `Equals`): `%SystemDrive%\Estate\...` and the drive-letter path are one folder. Use `Test-LabSameFolder`.
- Putting a step back into a `.bat` or `cmd /c`: `cmd /c` runs its own `reset` and `start` built-ins and misparses names with spaces; call the `tools\*.ps1` script with `powershell -File`.
- Writing a backslash path through `node -e` or a heredoc in Git Bash: `\r` and `\t` arrive as control characters or vanish; edit such lines with the Edit tool.

## Before saying done

Run `node tools/check.js` and quote the last line. Update `plan.md` and rewrite `handoff.md` in the same turn.
