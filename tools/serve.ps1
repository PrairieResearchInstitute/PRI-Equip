# serve.ps1 - the one owner of this application's web server on this computer.
#
#     powershell -NoProfile -ExecutionPolicy Bypass -File tools\serve.ps1 start [-Open] [-Console]
#     powershell -NoProfile -ExecutionPolicy Bypass -File tools\serve.ps1 stop [-Popup]
#     powershell -NoProfile -ExecutionPolicy Bypass -File tools\serve.ps1 status
#
# PaCELab Equip.exe runs "start -Open"; Stop PaCELab Equip.exe runs "stop -Popup";
# the owner's logon shortcut runs PaCELab Equip.exe (AGENTS.md). Owner ruling
# 2026-11-03 (skill drez-local-php-runner): one script starts, stops and
# reports; no .bat or .vbs launcher other than SETUP.bat.
#
# start   Refuses an administrator window (PostgreSQL will not run in one).
#         Asks whatever listens on the port for /health.php: this folder's own
#         server (by folder identity, tools\paths.ps1 Test-LabSameFolder, never
#         by spelling) is left running and opened; another copy or program is
#         named and nothing starts. On PostgreSQL starts the estate's cluster
#         through tools\estate-pg.ps1. Then starts the supervisor hidden and
#         waits for health.php. Safe to run any number of times.
#         -Open opens the browser and reports failures in a message box as well
#         as here, because the .exe has no window. -Console runs the supervisor
#         in this window instead, for debugging (Ctrl+C stops it).
# stop    The supervisor first (killing PHP under a live supervisor only makes
#         it start another), then PHP, only processes whose command line names
#         this folder by identity, then the estate's PostgreSQL through
#         tools\estate-pg.ps1 unless another application is connected. Run it
#         before unplugging the drive.
# status  One line: running or stopped, the port, the process and the log.
#
# The supervisor (action "supervise", started by start) holds a named mutex so
# only one runs, starts PHP bound to 127.0.0.1 (never "localhost", which is
# ::1 first and 127.0.0.1 second, so one address can reach two servers),
# restarts it when it exits, takes the daily backup (tools\backup.ps1) and
# logs to server.log in the data folder.
#
# Exit codes: 0 done, 1 failed, 2 port held by another copy or program,
# 3 PostgreSQL did not start, 4 administrator window.

param(
    [Parameter(Position = 0)][ValidateSet('start', 'stop', 'status', 'supervise')][string]$Action = 'status',
    [switch]$Open,
    [switch]$Console,
    [switch]$Popup,
    [int]$Port = 8147
)

$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'paths.ps1')
$title = 'PaCELab Equip'
$url = "http://127.0.0.1:$Port/home.php"
if ($Open) { $Popup = $true }

$dataDir = Get-LabDataDir
$log = Join-Path $dataDir 'server.log'

function Note([string]$msg) {
    $line = "{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $msg
    try {
        New-Item -ItemType Directory -Force -Path $dataDir -ErrorAction Stop | Out-Null
        Add-Content -Path $log -Value $line -Encoding utf8
    } catch { }
}

# Every message this script stops with goes to the window, to server.log and,
# from the launchers, to a message box: the .exe has no window, so a failure
# that only printed would look like "the exe did nothing".
function Say([string]$msg, [string]$color = '') {
    if ($color) { Write-Host "  $msg" -ForegroundColor $color } else { Write-Host "  $msg" }
}
function Fail([string]$msg, [int]$code) {
    Write-Host ''
    Say $msg Red
    Write-Host ''
    Note "$Action refused: $msg"
    if ($Popup) {
        try { (New-Object -ComObject WScript.Shell).Popup($msg, 0, $title, 48) | Out-Null } catch { }
    }
    exit $code
}

# The bundled PHP only: an application on the estate drive runs nothing from
# the computer (owner, 2026-10-20 and 2026-11-03).
$phpDir = Join-Path $root 'tools\php'
$php = Join-Path $phpDir 'php.exe'

# --- Processes that belong to this folder -----------------------------------
# By identity: the folder a command line names is compared with this folder
# through Test-LabSameFolder, so the fixed-folder spelling and the drive-letter
# spelling are one copy, and another app's PHP or supervisor is never touched.
function Get-MyPhp {
    Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue | Where-Object {
        $_.CommandLine -and $_.CommandLine -match '\s-S\s' -and $_.CommandLine -match '\s-t\s+"([^"]+)"' -and
        (Test-LabSameFolder $Matches[1] $root)
    }
}
# The supervisor of this script, and the supervisor.ps1 of the launchers this
# script replaced, so a supervisor started before the change is stopped too.
function Get-MySupervisor {
    foreach ($p in @(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" -ErrorAction SilentlyContinue)) {
        if ($p.ProcessId -eq $PID -or -not $p.CommandLine) { continue }
        $m = [regex]::Match($p.CommandLine, '-File\s+"([^"]+)\\tools\\(serve|supervisor)\.ps1"(.*)$')
        if (-not $m.Success) { continue }
        if ($m.Groups[2].Value -eq 'serve' -and $m.Groups[3].Value -notmatch '(^|\s)supervise(\s|$)') { continue }
        if (Test-LabSameFolder $m.Groups[1].Value $root) { $p }
    }
}

# 'none' when nothing listens on the port, 'mine' when this folder's server
# answers, 'other' when a different copy or another program holds it. Whether
# the port is taken comes from the TCP listener table, never from an HTTP probe
# (silence is not the same as free); health.php then says whose it is. Both
# loopback addresses are asked, because a server bound to "localhost" by an
# earlier launcher listens on ::1 only.
$script:otherRoot = ''
function Get-ServerState {
    $listen = Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue
    if (-not $listen) { return 'none' }
    foreach ($h in @('127.0.0.1', '[::1]')) {
        try {
            $body = (Invoke-WebRequest "http://${h}:$Port/health.php" -TimeoutSec 2 -UseBasicParsing).Content
        } catch { continue }
        $c = Compare-LabServerRoot ([string]$body) $root
        $script:otherRoot = $c.root
        return $c.state
    }
    $script:otherRoot = ''
    return 'other'
}

function Test-Elevated {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Invoke-EstatePg([string]$what) {
    # Out-Host: its lines go to the window, not into this function's result.
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'estate-pg.ps1') $what | Out-Host
    $code = $LASTEXITCODE
    if ($code -ne 0) { Note "estate-pg.ps1 $what exited $code" }
    return $code
}

# --- status ------------------------------------------------------------------
if ($Action -eq 'status') {
    $state = Get-ServerState
    $mine = @(Get-MyPhp)
    $sup = @(Get-MySupervisor)
    $pids = ($mine | ForEach-Object { $_.ProcessId }) -join ','
    switch ($state) {
        'mine'  { Say "running: port $Port, php $pids, supervisor $(@($sup | ForEach-Object { $_.ProcessId }) -join ','), $url, log $log" }
        'other' { Say ("stopped: port $Port is held by " + $(if ($script:otherRoot) { "the copy in $($script:otherRoot)" } else { 'a program that does not report its folder' }) + ", log $log") }
        default { Say ("stopped: nothing on port $Port" + $(if ($mine) { " (php $pids of this folder runs without answering)" } else { '' }) + ", log $log") }
    }
    exit 0
}

# --- stop --------------------------------------------------------------------
if ($Action -eq 'stop') {
    $sups = @(Get-MySupervisor)
    foreach ($s in $sups) { Stop-Process -Id $s.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Milliseconds 400
    $phps = @(Get-MyPhp)
    foreach ($p in $phps) { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Sleep -Milliseconds 600
    $left = @(Get-MyPhp)
    if ($left.Count) {
        Fail "Something of this application is still running: php.exe $(($left | ForEach-Object { $_.ProcessId }) -join ', '). Stop it from Task Manager." 1
    }
    Note "stopped $($sups.Count) supervisor(s) and $($phps.Count) php server(s)"
    Write-Host ''
    Say ('Stopped {0} supervisor(s) and {1} server(s) of this folder.' -f $sups.Count, $phps.Count) Green
    Say 'It starts again when you log on, or now with PaCELab Equip.exe.'
    # Only after this application's PHP server has gone, so none of its own
    # sessions remain (platform D-028, D-013). Nothing on SQLite.
    $pg = Invoke-EstatePg 'stop'
    Write-Host ''
    if ($pg -ne 0) { Fail 'The server stopped, but PostgreSQL on the drive did not stop cleanly. The reason is shown above. Run the stop again before unplugging the drive.' 1 }
    if ($Popup) {
        try { (New-Object -ComObject WScript.Shell).Popup('PaCELab Equip has stopped. It starts again when you log on, or with PaCELab Equip.exe.', 0, $title, 64) | Out-Null } catch { }
    }
    exit 0
}

# --- supervise (started by start; never run by hand) ------------------------
if ($Action -eq 'supervise') {
    if (-not (Test-Path -LiteralPath $php)) {
        Note "The bundled PHP is missing ($php). Run SETUP.bat. Giving up."
        exit 1
    }
    # One supervisor only. A second would fight the first for the port.
    $mutex = New-Object System.Threading.Mutex($false, 'Global\SharedLabEquipmentSupervisor')
    if (-not $mutex.WaitOne(0)) {
        Note 'another supervisor already holds the lock; exiting'
        exit 0
    }

    function Backup-IfDue {
        # One a day is enough. The marker is the newest backup's date, so a
        # computer that is off overnight still gets one the next time it is on.
        try {
            $dest = Get-LabBackupDir
            $today = Get-Date -Format 'yyyy-MM-dd'
            if (Test-Path $dest) {
                # lab-<date>-*.sqlite on SQLite, lab-<date>-*.pgdump on PostgreSQL
                # (backup.ps1 hands PostgreSQL to pg-backup.php).
                $have = Get-ChildItem $dest -Filter "lab-$today-*" -ErrorAction SilentlyContinue |
                        Where-Object { $_.Extension -in '.sqlite', '.pgdump' }
                if ($have) { return }
            }
            Note 'taking the daily backup'
            & powershell -NoProfile -ExecutionPolicy Bypass -File `
                ('"' + (Join-Path $PSScriptRoot 'backup.ps1') + '"') | Out-Null
        } catch {
            Note "backup attempt failed: $($_.Exception.Message)"
        }
    }

    Note "supervisor started, port $Port, serving $root"
    try {
        while ($true) {
            # If something else holds the port, wait rather than spin.
            if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) {
                if (Get-MyPhp) { Start-Sleep -Seconds 5; continue }
                Note "port $Port is held by something that is not this folder's server; waiting"
                Start-Sleep -Seconds 30
                continue
            }

            # Every path is quoted. Start-Process does not quote for you, and
            # the folder may have a space in its name, so an unquoted -t split
            # at the space and php exited 1 immediately, which looked exactly
            # like the server "not starting" for no reason. Sessions, uploads
            # and PHP's own temp go on the drive beside the data folder: a
            # session file holds who is signed in (owner standard B, D-007).
            $phpArgs = @(
                '-c', ('"' + (Join-Path $phpDir 'php.ini') + '"'),
                '-d', ('"extension_dir=' + (Join-Path $phpDir 'ext') + '"'))
            $tmp = Get-LabTempDir
            if ($tmp) {
                New-Item -ItemType Directory -Force -Path $tmp | Out-Null
                $phpArgs += @('-d', ('"session.save_path=' + $tmp + '"'),
                              '-d', ('"upload_tmp_dir='   + $tmp + '"'),
                              '-d', ('"sys_temp_dir='     + $tmp + '"'))
            }
            # tools\router.php refuses dotfiles (.env holds the PostgreSQL
            # password), data, tools, tests and migrations: the built-in server
            # reads neither .htaccess nor web.config.
            $phpArgs += @('-S', "127.0.0.1:$Port", '-t', ('"' + $root + '"'),
                          ('"' + (Join-Path $PSScriptRoot 'router.php') + '"'))

            if ($Console) {
                Note 'php started in the console'
                & $php @($phpArgs | ForEach-Object { $_.Trim('"') })
                Note "php exited with $LASTEXITCODE"
                break
            }
            $p = Start-Process -FilePath $php -PassThru -WindowStyle Hidden `
                 -RedirectStandardError (Join-Path $dataDir 'php-stderr.log') `
                 -ArgumentList $phpArgs
            Note "php started, pid $($p.Id)"

            # Wake hourly while php runs, so the supervisor is also what takes
            # the daily backup: no second scheduled job to install or forget.
            while (-not $p.HasExited) {
                $null = $p.WaitForExit(3600000)
                if (-not $p.HasExited) { Backup-IfDue }
            }
            Note "php exited with $($p.ExitCode); restarting in 3s"
            Start-Sleep -Seconds 3
            Backup-IfDue
        }
    } finally {
        $mutex.ReleaseMutex()
        Note 'supervisor stopped'
    }
    exit 0
}

# --- start -------------------------------------------------------------------
if (Test-Elevated) {
    Fail 'PostgreSQL will not run in an administrator window. Close this window and start PaCELab Equip from a normal one (not "Run as administrator").' 4
}
if (-not (Test-Path -LiteralPath $php)) {
    Fail ("The bundled PHP is missing:`n  $php`n`nRun SETUP.bat in this folder, or copy tools\php into it from the drive. " +
          'If this folder is on OneDrive it may not have finished downloading. Right-click the folder, choose "Always keep on this device", wait for the green checks, and start again.') 1
}

function Stop-OtherCopy {
    $who = if ($script:otherRoot) { "the copy in $($script:otherRoot)" }
           else { 'a server that does not report its folder (an older copy, such as the OneDrive original, or another program)' }
    Fail ("Port $Port is held by $who, not by this copy ($root). " +
          'PaCELab Equip will not open it. Stop that server (tools\serve.ps1 stop in that folder), then start this one again.') 2
}

$state = Get-ServerState
if ($state -eq 'other') { Stop-OtherCopy }

# On PostgreSQL the estate's cluster on the drive must answer before the pages
# can (platform D-028, D-013). Nothing on SQLite, outside the estate, or when
# it already runs. Its own process, so the PGTZ it sets never reaches PHP.
if ((Invoke-EstatePg 'start') -ne 0) {
    Fail ('PostgreSQL on the drive did not start, so PaCELab Equip cannot reach its data. The reason is in the window above or in ' + $log + '.') 3
}

if ($state -eq 'none') {
    if ($Console) {
        Say "Serving $root at $url in this window. Ctrl+C stops it." Cyan
        & $PSCommandPath supervise -Console -Port $Port
        exit $LASTEXITCODE
    }
    # Quoted: Start-Process does no quoting, and an unquoted -File never found
    # the script and nothing was logged at all.
    Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', ('"' + $PSCommandPath + '"'), 'supervise', '-Port', "$Port"
    ) | Out-Null
    foreach ($i in 1..40) {
        Start-Sleep -Milliseconds 250
        $state = Get-ServerState
        if ($state -ne 'none') { break }
    }
}

if ($state -eq 'other') { Stop-OtherCopy }
if ($state -eq 'mine') {
    Say "running: $url (serving $root)" Green
    if ($Open) { Start-Process $url }
    exit 0
}
Fail ("The server did not come up. Look at $log and " + (Join-Path $dataDir 'php-stderr.log') + ' for the reason.') 1
