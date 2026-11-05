# Putting this on the server

The application is 53 files and about 600 KB. There is nothing to compile, no package
manager, and no dependencies to fetch. You unzip a folder and open it in a
browser.

## Getting the package

Run **`powershell -NoProfile -ExecutionPolicy Bypass -File tools\build-release.ps1 -Open`**
in the application folder. It runs the tests, refuses to build if any fail,
writes `dist\shared-lab-equipment-<date>.zip` and opens `dist\`.

**Give IT that zip.** Do not give them the working folder: it carries 84 MB of
Windows PHP, a local test database, and a script that invents fake charges.

The build works from an explicit list of files rather than an exclusion pattern,
and warns about anything in the project that is on neither the ship list nor the
do-not-ship list — so a file added later cannot go missing silently.

The zip was tested by unpacking it on Linux tooling, installing it from nothing,
and walking every screen. It came up clean, with no PHP diagnostics of any kind.

## What is in it

    index.php          home.php           lab.php
    schedule.php       report.php         api.php
    check.php          install.php        admin-recovery.php
    health.php         config.template.json
    DEPLOY.md          (this file)
    includes/          auth.php  db.php  functions.php  schema.php
                       local-config.php  studio.php  env.php  db_migrations.php
                       .htaccess  web.config
    migrations/        0001_baseline.pgsql.sql  0001_baseline.sqlite.sql
                       0002_lab_id_required.pgsql.sql  0002_lab_id_required.sqlite.sql
                       .htaccess  web.config
    assets/            style.css  app.js  calendar.js
    admin/             all 15 .php files

## What is deliberately left out

| Leave behind | Why |
|---|---|
| `tools/` | 84 MB of Windows PHP. The server has its own. |
| `data/` | Your local database and its install lock. Copying it would put local test data on the server, and the lock would stop the installer. |
| `SETUP.bat`, `PaCELab Equip.exe`, `Stop PaCELab Equip.exe`, `launcher/`, `launcher-stop/` | Windows setup and launchers. Meaningless on a web server. |
| `seed-demo.php` | Creates fake instruments and charges. Do not put this where anybody can reach it. |
| `tests/` | Optional. Harmless, but it belongs with the source rather than on the server. |
| `.git/`, `.claude/`, `.gitignore`, `.gitattributes` | Not part of the application. |
| `*.docx` | The spec and the README. |
| `config.local.json` | Names the data folder on the computer it was written on. The server makes its own; see below. |

## What the server needs

PHP 8.0 or later with PDO SQLite and sessions. Nothing else — no Composer, no
extensions to install, no database server to provision. (PostgreSQL is the
option for a multi-user host; see "Running on PostgreSQL" below.)

It is developed and tested on PHP 8.3, and driven end to end with
`error_reporting=E_ALL` and `display_errors` on: no deprecation, no warning and
no notice comes out of any screen. The suite also refuses anything a newer PHP
has taken away — `utf8_encode`, `strftime`, `${var}` interpolation, implicitly
nullable parameters — so moving to 8.4 or 8.5 should be uneventful. `check.php`
warns if the server is older than 8.3 while still letting it run.

## Installing

1. Open `check.php`. It tests PHP, PDO SQLite, sessions, and whether it can
   write. Fix anything it reports before going on.
2. Open `install.php`. Name the installation, set the first administrator's
   username and password. It creates `data/`, writes the database, drops an
   `.htaccess` in `data/` denying web access to it, and locks itself.
3. **Delete `check.php` and `install.php`.** The installer locks itself, but
   deleting it is the version that does not depend on a lock file surviving.
4. Sign in at `admin/login.php`, create a laboratory under Administration →
   Laboratories, and add people to it.

## Confirm the database is not downloadable

**This is the one thing that must not be skipped.** `data/lab.sqlite` holds every
charge and every administrator password hash. If the web server will serve it,
anyone who guesses the URL downloads the lot.

The installer writes both `data/.htaccess` (Apache) and `data/web.config` (IIS),
and ships the same pair in `includes/`. Each server reads one and ignores the
other. **nginx reads neither** — it has no per-directory config at all — so on
nginx the deny has to go in the site configuration, or the database has to live
outside the web root.

`check.php` no longer guesses. It asks this server for `data/lab.sqlite` over
HTTP, exactly as an outsider would, and reports one of:

- **ok** — the request came back 403 or 404, or the database is outside the web root.
- **fail** — the request came back with the database. It says so in capitals. Do
  not put real data in until this is fixed.
- **warn** — it could not run the test, and tells you the URL to try by hand.

So: **run `check.php` again after installing, before deleting it.** Before
installation there is no database and the test has nothing to answer.

## Where the database lives

Two files can move it and they are not interchangeable. Read both before
choosing one.

**On a server, use `config.php`.** Create it beside `index.php`:

```php
<?php define('LAB_DB_PATH', '/var/www/private/lab.sqlite');
```

The path is absolute, the directory must exist and be writable by the web
server account, and nothing else changes. Moving the database out of the web
root this way is the safest arrangement and the only one that does not depend
on server configuration, which matters most on nginx, where neither
`.htaccess` nor `web.config` is read at all.

**`config.local.json` is the portable mechanism.** The Windows copy this
application is developed on uses it, because there the data folder travels
separately from the application. Copy `config.template.json` to
`config.local.json` and fill in the folder:

```json
{ "dataFolder": "../lab-data", "backupFolder": "../lab-backups" }
```

The path is relative to the application folder and an absolute one is refused,
so `../lab-data` is how it reaches a folder beside the web root rather than
inside it. The folder holds `lab.sqlite`, the install lock, the logs and the
Studio backups, and the application writes the Apache and IIS deny files into
it the first time it opens the database.

**`config.php` wins.** When both files exist, `LAB_DB_PATH` from `config.php`
decides where the database is and `dataFolder` has no say in it. Set one or
the other, not both.

## Running on PostgreSQL

SQLite stays the default: an installation that sets nothing runs exactly as
described above. A multi-user host (Radiant) runs the same files on PostgreSQL;
only settings change.

**What the server needs in addition:** PHP's `pdo_pgsql` extension, a
PostgreSQL database (17 is what the application is tested on) and a login role
for the application. The database name for tests must end in `_test`.

**Settings.** The application reads the standard PostgreSQL names: `PGHOST`,
`PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`, and `PGTESTDATABASE` for the
test database. Put them in the service's environment (for the container,
the tier's environment file named in `compose.yaml`). Where that is not
possible, the application also reads a `pg.env` beside its data folder, then
a `.env` beside `index.php`; either file must be readable only by the web
server account and must never be served. `pg.env` sits one level above the
data folder, so with `dataFolder` pointing outside the web root it is outside
too. Do not use `.env` on a server: it sits inside the application folder,
and unlike `config.php`, which the server runs, a plain-text file in the web
root is handed to anyone who asks for it unless the server is told otherwise.
On the owner's computer the built-in PHP server refuses it through
`tools/router.php`. `env.template.txt` in the source lists every name. Nothing the application prints ever includes
a value: a connection failure says only "The database could not be reached".

**Choosing PostgreSQL.** Set `PACELAB_DRIVER=pgsql` in the service's
environment. (The drive copy is switched with
`php tools/config.php set databaseDriver pgsql` instead; a value the server
sets in its real environment always wins.)

**On the drive (until Radiant).** The owner's copy uses the estate's own
PostgreSQL, program and data on the portable drive, never a PostgreSQL
installed on the computer (platform D-028, D-013): the runtime in
`<estate>\runtime\postgresql\17`, one cluster in `<estate>\data\postgres\17`,
listening on 127.0.0.1 port 5433, with this application's own role
`pacelab_app` and databases `pacelab` and `pacelab_test`. `pg.env` beside the
data folder carries `PGPORT=5433`; with PGHOST and PGPORT empty the
application uses 127.0.0.1:5433 anyway. When the driver is pgsql,
`PaCELab Equip.exe` (and the owner's logon shortcut to it), through
`tools\serve.ps1 start`, start the cluster unless it runs, and
`Stop PaCELab Equip.exe` (`tools\serve.ps1 stop`) stops it with a fast, clean
shutdown unless another application is still connected; run it before
unplugging the drive, from a normal window (PostgreSQL refuses an
administrator account). Both go through `tools\estate-pg.ps1`, which runs the
estate's `estate-pg.js` when a Node exists and the same `pg_ctl` steps when it
does not. The cluster itself is created once by `node estate-pg.js init`
(the PRI Suite session), never by this application. There is no API server:
the pages connect to the cluster directly; an API tier is for Radiant later.

**The schema.** `install.php` builds it from `migrations/*.pgsql.sql` when the
role may create tables, records each file in `schema_migrations`, then seeds
and creates the first administrator as on SQLite. Where the database
administrator keeps schema changes to an owner role, run the migration as that
role first, from the source folder:

```bash
php tools/migrate.php --pg live --status           # what is pending
php tools/migrate.php --pg live --owner-approved   # apply it
```

`install.php` then finds nothing pending and only seeds. It refuses to run
over a database that already holds administrators, lock file or not.

**Moving existing data.** From the source folder, with the application
stopped, load the SQLite database and let the loader prove the copy:

```bash
php tools/pg-load.php --source <lab.sqlite> --target live --replace --owner-approved
```

It snapshots the source with `VACUUM INTO`, copies every table with its
original ids in one transaction, moves every sequence past the highest id,
and compares every row field by field and by checksum. Anything but
`VERIFIED: ...` means do not switch.

**Backups.** `pg_dump -Fc` nightly, kept off the server (Taiga), and a restore
actually performed before the first one is trusted. On the drive copy
`tools/backup.ps1` does this itself once the driver is PostgreSQL (through
`tools/pg-backup.php`: dump, `pg_restore --list`, restore into the test
database, compare row counts), and `tools\backup.ps1 -Now` takes one and runs
the drill.

**Health.** `health.php?db=1` answers `{"db":"ok"}` when PostgreSQL answers
and 503 when it does not.

**Going back.** Unset `PACELAB_DRIVER` (or `set databaseDriver sqlite`). The
SQLite file is untouched by the switch, so it holds the data as it was at the
moment of switching; anything written on PostgreSQL since then stays there.

## Who signs in (NetID, decision D-015)

On Radiant nobody types a password into this application. The university's
authenticating proxy verifies the person and passes their address in
`X-Forwarded-Email` (their NetID at illinois.edu) and, when configured, their
campus groups in `X-Forwarded-Groups`. The container image sets
`PACELAB_AUTH_MODE=proxy`. The application must be reachable only through the
proxy: never publish its port, and set `PACELAB_TRUSTED_PROXIES` to the proxy's
addresses once NCSA names them. The other settings (`PACELAB_IDENTITY_HEADER`,
`PACELAB_IDENTITY_DOMAINS`, `PACELAB_GROUPS_HEADER`, `PACELAB_UNLISTED_ROLE`) are
listed with their defaults in `env.template.txt`.

What a NetID may do comes from the `role_grants` table (migration 0003):
`member` (a laboratory's screens; with a laboratory named, membership of it),
`admin` (the administrative panel) or `code_editor` (the panel and the Studio),
granted to a NetID or to a campus group. Seed the first administrator from the
command line, then use Administration, Administrators:

    php tools/migrate.php --pg live --owner-approved            (as <app>_owner; applies 0003)
    php tools/roles.php --pg live grant group:<admin group> admin --owner-approved
    php tools/roles.php --pg live grant <netid> code_editor --owner-approved
    php tools/roles.php --pg live list

The grants need rows only, so `tools/roles.php` runs as `<app>_app`, once
`<app>_app` holds SELECT, INSERT and UPDATE on `role_grants` and USAGE on its
sequence. `Migration/03_grants.sql` grants every existing table; its default
privileges cover only tables created by the role that ran it, so run the move's
grant step (or 03_grants.sql, with the role names for Radiant) after migration
0003, unless `<app>_owner` has set default privileges for its own tables. Without the proxy header every page
answers 401; a NetID without the administrator role gets 403 on the panel.

## The Studio: fixing the application without a code editor

`admin/studio.php` lets an administrator edit the application's own source from
inside it — the same idea, and the same safeguards, as the Studio in PRI
Facilities Request. It exists so a problem on the campus server can be fixed by
somebody who has a browser and a password but no checkout, no editor, and no
way to copy files up.

Read this before granting it, because it writes PHP that the next request runs.

**What protects you**

- **Confined.** A path is resolved and then checked to be inside the
  application folder. Not string-matched — resolved — so `..`, a symbolic link
  and a Windows short name all fail. `data/`, `tools/php/` and `dist/` are not
  editable at all, so the database cannot be opened or overwritten.
- **Checked.** PHP is parsed before it is written. A file that would white
  screen the application is refused and nothing is saved.
- **Reversible.** Every save copies the old file into `data/studio-backups`
  first. Restore is one click.
- **Recorded.** `data/studio-history.log` gets a line per save, refusal and
  restore: who, what, when, and how many bytes changed.
- **Granted separately.** Being an administrator is not enough. The
  `may_edit_code` column defaults to 0, and **nobody can grant it to
  themselves** — it takes a second administrator, or the console.

**Granting it**

From the Administrators screen, or when there is only one administrator:

```bash
php admin-recovery.php grant <username>
```

`revoke` takes it back.

**What it does not protect against**

Anyone holding the grant *and* an administrator password can run arbitrary code
on the server as the web user. That is what the feature is for, and it is the
reason to grant it to as few people as possible — ideally nobody, until the day
something is broken and it is needed.

If hosting policy does not allow it at all, delete `admin/studio.php` and
`includes/studio.php` from the server. Nothing else depends on them.

## The one outside dependency

The approved Illinois header and footer are web components loaded from
Illinois-run hosts:

- `https://cdn.toolkit.illinois.edu/3.14.0/toolkit.css` and `toolkit.js`, pinned
  to that version so an upstream release cannot change the chrome unannounced
- `https://use.typekit.net/etc1rtr.css` (the campus typefaces)

Nothing else leaves the server. If those hosts are unreachable, the application
still works — every screen it renders is plain HTML and its own stylesheet — but
the banner and footer fall back to unstyled text. That is the tradeoff for using
the approved branding rather than a copy of it that goes stale.

## Before any change goes up

On the drive copy run `powershell -File tools\run-tests.ps1`; on a server run

```bash
php tests/run-tests.php
```

283 checks on SQLite. It builds a scratch database in the temporary folder and never opens
`data/lab.sqlite`, so it is safe to run against a live installation.

## If the administrator password is lost

From a shell on the server, in the application folder:

```bash
php admin-recovery.php list
```

`reset`, `add` and `unlock` do what they say. It refuses to run over the web.
