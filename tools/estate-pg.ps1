# estate-pg.ps1 - start and stop the estate's PostgreSQL on the drive for this application.
#
#     powershell -NoProfile -ExecutionPolicy Bypass -File tools\estate-pg.ps1 start | stop | status
#
# Platform D-028 (owner, 2026-10-11): PostgreSQL, program and data, lives on the
# portable drive, never on the computer. One runtime in
# <estate>\runtime\postgresql\17, one cluster in <estate>\data\postgres\17,
# listening on 127.0.0.1 port 5433. An application starts the cluster when it
# opens and stops it cleanly when it quits, unless another application is still
# connected. tools\serve.ps1 start (PaCELab Equip.exe, the Desktop and logon
# shortcuts) calls "start"; tools\serve.ps1 stop (Stop PaCELab Equip.exe) calls
# "stop" after the PHP server has gone, so this application holds no connection.
# It is the one wrapper: nothing else in this application starts or stops
# PostgreSQL, and it never runs init or reads superuser.env or pg.env.
#
# Nothing happens unless the application runs on PostgreSQL (driver pgsql from
# tools\config.php show) inside the estate (a folder above holds .estate-live).
# <estate> is found by walking up from this folder, never by a drive letter.
#
# How (D-013): the estate's own script, <estate>\runtime\postgresql\estate-pg.js,
# is the one authority. When a node.exe is found (in <estate>\runtime first,
# then on PATH) this file runs "node estate-pg.js start|stop". This application
# runs on a portable PHP and needs no Node, so without one it does the same
# steps itself with the runtime's own programs, mirroring estate-pg.js:
#   running  pg_isready -h 127.0.0.1 -p 5433 -t 3
#   start    refuse when <data>\PG_VERSION is missing (the cluster is created by
#            "node estate-pg.js init", never here); nothing when running;
#            pg_ctl start -D <data> -l <estate>\data\postgres\log\postgresql.log
#            -w -t 60 -o "-p 5433", then pg_isready again
#   stop     nothing when not running; nothing while other client sessions are
#            open (counted by php tools\pg-status.php --other-sessions with the
#            application's own role, because superuser.env is read only by
#            estate-pg.js); otherwise pg_ctl stop -D <data> -m fast -w -t 60,
#            the fast, clean shutdown that keeps the data safe before the drive
#            is unplugged
# Every PostgreSQL program runs with PGTZ=UTC, as in estate-pg.js. The script
# runs in its own PowerShell process, so PGTZ never reaches the PHP server.
#
# Exit codes: 0 done or nothing to do, 1 failed, 2 usage.

param([string]$Action = 'status')

$ErrorActionPreference = 'Continue'
if ($Action -notin @('start', 'stop', 'status')) {
    Write-Host '  Usage: estate-pg.ps1 start | stop | status'
    exit 2
}

$root = Split-Path -Parent $PSScriptRoot

# PostgreSQL refuses to start under an administrator account, and its own
# error reads like a broken install. Say it plainly instead.
$elevated = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator)
if ($Action -eq 'start' -and $elevated) {
    Write-Host '  PostgreSQL will not run in an administrator window. Close this window and start PaCELab Equip from a normal one (not "Run as administrator").' -ForegroundColor Red
    exit 1
}
$PgHost = '127.0.0.1'
$PgPort = 5433

function Say([string]$m, [string]$color = '') {
    if ($color) { Write-Host "  $m" -ForegroundColor $color } else { Write-Host "  $m" }
}

# The bundled PHP only, as serve.ps1 and backup.ps1 use it; never one on the computer.
$phpDir = Join-Path $root 'tools\php'
$php    = Join-Path $phpDir 'php.exe'
$phpArgs = @('-c', (Join-Path $phpDir 'php.ini'), '-d', "extension_dir=$(Join-Path $phpDir 'ext')")

function Get-Driver {
    if (-not (Test-Path -LiteralPath $php)) { return '' }
    $show = (& $php @phpArgs (Join-Path $PSScriptRoot 'config.php') show --json 2>$null) -join "`n"
    $brace = $show.IndexOf('{')
    if ($LASTEXITCODE -ne 0 -or $brace -lt 0) { return '' }
    try { return [string](($show.Substring($brace) | ConvertFrom-Json).driver) } catch { return '' }
}

function Find-Estate {
    $d = $root
    while ($d) {
        if (Test-Path -LiteralPath (Join-Path $d '.estate-live') -PathType Leaf) { return $d }
        $up = Split-Path -Parent $d
        if (-not $up -or $up -eq $d) { break }
        $d = $up
    }
    return $null
}

function Find-Node([string]$estate) {
    foreach ($c in @((Join-Path $estate 'runtime\node\node.exe'), (Join-Path $estate 'runtime\nodejs\node.exe'))) {
        if (Test-Path -LiteralPath $c -PathType Leaf) { return $c }
    }
    $cmd = Get-Command node.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($cmd) { return $cmd.Source }
    return $null
}

$driver = Get-Driver
if ($driver -ne 'pgsql') {
    if ($Action -eq 'status') { Say "The application runs on '$driver', not PostgreSQL; the drive's cluster is not needed." }
    exit 0
}

$estate = Find-Estate
if (-not $estate) {
    Say 'No .estate-live marker above this folder: this copy is outside the estate, so the drive''s PostgreSQL is not managed here.'
    exit 0
}

$js = Join-Path $estate 'runtime\postgresql\estate-pg.js'
$node = Find-Node $estate
if ($node -and (Test-Path -LiteralPath $js -PathType Leaf)) {
    Say "PostgreSQL on the drive: node estate-pg.js $Action"
    & $node $js $Action
    exit $LASTEXITCODE
}

# --- Without Node: the same steps with the runtime's own programs -------------
$bin  = Join-Path $estate 'runtime\postgresql\17\bin'
$home_ = Join-Path $estate 'data\postgres'
$data = Join-Path $home_ '17'
$log  = Join-Path $home_ 'log\postgresql.log'
$env:PGTZ = 'UTC'

$pgIsReady = Join-Path $bin 'pg_isready.exe'
$pgCtl     = Join-Path $bin 'pg_ctl.exe'
if (-not (Test-Path -LiteralPath $pgCtl) -or -not (Test-Path -LiteralPath $pgIsReady)) {
    Say "The estate's PostgreSQL runtime is missing ($bin)." Red
    exit 1
}
$initialized = Test-Path -LiteralPath (Join-Path $data 'PG_VERSION')

function Test-Running {
    & $pgIsReady -h $PgHost -p "$PgPort" -t 3 | Out-Null
    return ($LASTEXITCODE -eq 0)
}

if ($Action -eq 'status') {
    $run = $initialized -and (Test-Running)
    Say ("PostgreSQL on the drive (without Node): initialized {0}, running {1}, {2}:{3}, data {4}" -f $initialized, $run, $PgHost, $PgPort, $data)
    exit 0
}

if ($Action -eq 'start') {
    if (-not $initialized) {
        Say "The estate PostgreSQL cluster does not exist yet ($data). It is created once with: node estate-pg.js init" Red
        exit 1
    }
    if (Test-Running) { Say 'PostgreSQL on the drive is already running.'; exit 0 }
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $log) | Out-Null
    # No redirection here: the server pg_ctl leaves running must not hold a
    # pipe this script waits on.
    & $pgCtl start -D $data -l $log -w -t 60 -o "-p $PgPort"
    $code = $LASTEXITCODE
    if ($code -ne 0 -or -not (Test-Running)) {
        Say "PostgreSQL on the drive did not start (pg_ctl exit $code). Log: $log" Red
        exit 1
    }
    Say 'PostgreSQL on the drive started.' Green
    exit 0
}

# stop
if (-not $initialized -or -not (Test-Running)) { Say 'PostgreSQL on the drive is not running.'; exit 0 }
$count = (& $php @phpArgs (Join-Path $PSScriptRoot 'pg-status.php') --other-sessions 2>&1) -join ' '
$countCode = $LASTEXITCODE
if ($countCode -ne 0 -or $count.Trim() -notmatch '^\d+$') {
    Say "PostgreSQL on the drive left running: the open sessions could not be counted ($($count.Trim())). Run tools\serve.ps1 stop again, or node estate-pg.js stop where Node exists, before unplugging the drive." Red
    exit 1
}
if ([int]$count.Trim() -gt 0) {
    Say "PostgreSQL on the drive left running: $($count.Trim()) other session(s) still open (another application). The last application to quit stops it."
    exit 0
}
& $pgCtl stop -D $data -m fast -w -t 60
if ($LASTEXITCODE -ne 0) {
    Say "pg_ctl stop failed (exit $LASTEXITCODE). Log: $log" Red
    exit 1
}
Say 'PostgreSQL on the drive stopped cleanly.' Green
exit 0
