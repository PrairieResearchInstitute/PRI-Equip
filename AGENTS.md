# Working on the Shared Laboratory Equipment system

Read this before the first edit. Several things here look wrong and are
deliberate; a few look harmless and have already cost a day each.

---

## What this is

A booking, use-recording and grant-billing system for instruments that more
than one laboratory shares. Illinois Natural History Survey, Prairie Research
Institute.

PHP 8 and SQLite. **No Composer, no npm, no build step, no CDN for anything the
application itself renders.** It is meant to be unzipped into a campus web
directory and opened. That constraint came from the specification and is the
reason the stack is not the one in `pri-ai-project-template` — do not "improve"
it into Docker and FastAPI. Container files for the hosted test server are
a separate matter, covered in the decisions table below.

The ship list in `tools/build-release.ps1` names 53 files.
`BUILD FOR SERVER.bat` makes the deployable zip.

The application lives and is developed in `App_to_Migrate\apps\pacelab-equip`.
The OneDrive folder it came from is a frozen archive: do not edit, build or
commit there.

---

## Run the tests. They are not decoration.

```
node tools/check.js      the gate: the suite, the data folder, the live database
RUN TESTS.bat            the suite on its own
```

276 checks on SQLite. They build a throwaway database from `includes/schema.php`, the
same source `install.php` uses, so a test can never drift from production the
way a hand-written `CREATE TABLE` would. They never open the live database.
One of them proves `schema.php` and the numbered migrations build the same
tables, columns and indexes.

On PostgreSQL (`PACELAB_TEST_DRIVER=pgsql`, 279 checks) the harness refuses a
database whose name does not end in `_test`, asks the server which database it
reached, drops every table and rebuilds the test database from
`migrations/*.pgsql.sql` (D-011). Credentials come from the PG* names in
`pg.env` beside the data folder or `.env` (D-010); nothing prints a value.

The gate ends with `CHECK OK`. After the suite it confirms that the PHP loader
and the PowerShell resolver name the same data folder, then copies the live
database to a temporary folder and reports `integrity_check` and row counts
from the copy. Nothing in the data folder is opened for writing. When
PostgreSQL credentials exist it also runs the suite on the test database and
`tools/pg-load.php` from a copy of `lab.sqlite`, which must print `VERIFIED`.

**Three real bugs have been caught by adding a check rather than by reading
code.** If you fix something that failed silently, add the check that would
have caught it, then break the code on purpose and confirm the check fails. A
verification that cannot fail is decoration.

---

## Rules that cost money or leak data if broken

**A charge keeps the rate it was made at.** `usage_records` stores
`rate_charged`, `rate_unit_charged` and `receiving_subaccount` at the moment of
entry. Changing an instrument's rate must never alter a charge already made.
This is the single most important rule in the system.

**CFOPA is five segments.** `1-303631-375002-375150-A51`. Four segments
normalise to `-A00`, the parent. The billing file goes straight to the business
office; a code with a segment missing is a line they cannot post. Unique per
laboratory, not globally — two labs charging one award is normal.

**Laboratories cannot see each other.** `equipment_by_id()` and
`grant_by_id()` are the choke points; every screen loads through them. Three
cross-laboratory leaks were found by hand during the build, which is why the
suite tests isolation by attack rather than by inspection.

**Nothing is deleted.** Charges void with a reason and stay in the table.
Instruments retire and keep their history. Export batches survive being
reopened. An auditor must be able to find everything that ever happened.

**The application never sends mail.** It composes a message, hands it to
Outlook with `mailto:`, and logs the exact words. The log says *written* or
*opened* — never *sent*, because it cannot know that. Do not make it claim
otherwise.

---

## Traps that have already bitten

**Named placeholders must be bound.** SQLite binds a missing named parameter as
NULL rather than raising, so `WHERE lab_id = :lab` with no `:lab` silently
matches nothing — no error, no warning, an empty table that looks like a quiet
week. This shipped in three queries and made the Reservations screen useless
for months. A test now scans every file for it.

**Paths in this project contain a space.** `Start-Process` does not quote its
arguments. An unquoted `-t` split the document root and PHP exited instantly;
an unquoted `-File` meant a script never ran and wrote no log at all. Quote
every path handed to `Start-Process`.

**Do not estimate text height.** Two layout bugs came from advancing a cursor
by a guessed line count. Let the renderer lay text out — one text frame with
real paragraph spacing, not a box per item.

**`cmd /c "RESET ..."` runs the Windows `reset` command**, not your file. The
launcher is `FORGOT ADMIN PASSWORD.bat` for that reason. `START HERE.bat` has
the same hazard and works only because it is double-clicked.

---

## How it runs locally

`tools/supervisor.ps1` owns the server: it starts PHP on **port 8147**,
restarts it if it stops, and takes the daily backup. A Startup shortcut runs it
at logon. `SETUP.bat` installs that on a new machine and writes
`config.local.json` first. The app folder travels with the drive, the logon
shortcut does not.

PHP's built-in server reads neither `.htaccess` nor `web.config` and, left to
itself, hands out any file in the folder, `/.env` included (checked on a
scratch copy, 2026-10-10). The supervisor therefore starts it with
`tools/router.php`, which refuses dotfiles, `data/`, `tools/`, `tests/`,
`migrations/` and file types that are never a page or an asset. Add a new
folder the web must not reach to that router as well.

**The database is no longer inside the app folder.** `config.local.json` names
the data folder; `includes/local-config.php` resolves it, `tools/paths.ps1` is
its PowerShell twin, and `tools/config.php` is the only writer. On this machine
the database, the install lock, the logs and the backups all resolve under
`App_to_Migrate\data\pacelab-equip`. With no `config.local.json` everything
stays in `data/` inside the app folder, which is what a server copy does. The
path in `config.local.json` is relative, so the estate moving from the exFAT drive to the NTFS drive on
8 October 2026 changed nothing but the paths written in documents. Never
write a `data\...` path by hand in PowerShell: call `Get-LabDataDir` and
`Get-LabBackupDir`.

**Do not run it from two machines at once.** One SQLite file with two writers
loses charges. While the folder sat in OneDrive the same mistake also produced
sync conflict copies.

---

## The Studio

`admin/studio.php` edits the application's own source from inside it, for the
day something is wrong on the server and nobody with a code editor is around.
Four properties hold it together and none of them is optional: **confined** (a
path is resolved, then checked to be inside the app folder — never
string-matched), **checked** (PHP is parsed before it is written; a file that
would white screen the site is refused), **reversible** (the old copy is kept),
**recorded** (who, what, when, byte delta).

It is granted by the `may_edit_code` column, which defaults to 0, and the web
interface refuses to let anybody grant it to themselves. `config.local.json`
is neither listed nor openable, because an edit there would point the
application at another database. If you change anything in
`includes/studio.php`, run the suite: fourteen of its checks exist purely to
prove the containment cannot be walked out of.

## Backups

`tools/backup.ps1` uses `VACUUM INTO`, not a file copy, because copying a
SQLite file mid-write gives you something that usually opens and is
occasionally torn. Every backup is reopened and its row counts compared before
it is kept. `-Restore` runs the drill.

The destination is the first of these that answers: `backup-path.txt` in the
data folder, `backupFolder` in `config.local.json`, a `backups` folder beside
the data folder, then a folder beside the application. Point it at Taiga once
hosted.

On PostgreSQL `backup.ps1` asks `tools/config.php show` for the driver and
hands over to `tools/pg-backup.php`: `pg_dump -Fc` to `lab-<date>.pgdump`,
`pg_restore --list`, a restore into the test database and a row-count
comparison before the dump is kept; `-Restore` runs the same drill on the
newest dump. When it cannot read the driver it stops rather than guess, because
the SQLite file still exists after a switch and a copy of it would succeed
every night while saving nothing current.

---

## Decisions already taken

| Decision | Why |
|---|---|
| SQLite for now, **Postgres before production hosting** | Agreed with the project owner. Testing continues on SQLite; a virtual test server and the Postgres move come before real billing data. Everything goes through PDO, so keep it that way and do not write SQLite-specific SQL. |
| Postgres port on branch `port/postgres` (platform D-027) | The code runs on both engines behind one switch: `databaseDriver` in config.local.json on the drive, `PACELAB_DRIVER` in a server's environment (D-010). Live data stays on SQLite until the owner switches; `tools/pg-load.php` moves it and proves the copy. |
| PostgreSQL on the drive (platform D-028, D-013) | The cluster is the estate's, on the portable drive: runtime `<estate>\runtime\postgresql\17`, data `<estate>\data\postgres\17`, 127.0.0.1:5433. Never a PostgreSQL installed on the computer. `tools/estate-pg.ps1` starts it from START HERE.bat and stops it from STOP SERVER.bat when the driver is pgsql; pg_dump and pg_restore come from the estate runtime. No API server: pages connect directly. |
| PHP, no framework | The spec: drop into a campus web directory, no build step. |
| Illinois toolkit from `cdn.toolkit.illinois.edu` | The one outside dependency. Approved branding; the app still works without it, just unstyled chrome. |
| `property_tag` matches PRI Facilities | Natural key shared with that system so an instrument can be reconciled without either owning the other. |
| Identity is a typed last name, until the NetID modes (D-015) | `person_key` is the lowercased string. In the `password` mode (the default) people type a name and administrators sign in from `admin_users`. In the `proxy` mode (Radiant, set by the image) the university's authenticating proxy passes the NetID in `X-Forwarded-Email` and groups in `X-Forwarded-Groups`; `role_grants` gives member, admin or code_editor to a NetID or a group; `lab_members.person_key` holds NetIDs. The `local` mode is a development sign-in for this computer only. PRI forbids an application password table on Radiant, so the proxy mode has none. |
| Repository on the owner's GitHub, not the PRI org | For now. |
| Data folder outside the app folder | `config.local.json` names it, so the application and its data move independently. A copy without that file keeps everything in `data/`, so a server copy behaves as it always did. D-014. |
| Container files for the hosted test server | `Dockerfile`, `.dockerignore` and `compose.yaml` exist for the move to Radiant. The application itself is unchanged, still unzips into a web directory, and the release zip never carries them. They are not a license to rewrite the stack. |

Every decision carries a number in `reference/context/DECISIONS.md`, written only by decisions.py (drez-decide); `docs/decisions.md` is a pointer to it.

---

## Before you hand anything over

- `node tools/check.js` ends with `CHECK OK`.
- `BUILD FOR SERVER.bat` builds. It refuses if the tests fail, works from an
  explicit ship list, and warns about any file on neither list. Builds are
  centralized: ask the owner before running one.
- Nothing in `data/` is in the zip, and no `.bat` is either, and neither is
  `config.local.json`.
- `plan.md` and `handoff.md` are current in the same turn as the work.
- On the server: run `check.php` **after** installing, before deleting it. It
  asks the server for the database over HTTP and says in capitals if the
  database can be downloaded. It resolves the path through the loader, so a
  server that moved its data folder is probed where the data actually is.
