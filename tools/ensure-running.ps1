# ensure-running.ps1 — make sure the application is up, then optionally open it.
#
# Safe to run any number of times. If the supervisor is already running it does
# nothing but open the browser, so double-clicking the launcher twice can never
# start two servers or take a second port. It opens only a server that
# health.php shows to be serving this very folder; when another copy holds
# the port it says so and opens nothing.

param([switch]$Open, [int]$Port = 8147)

$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
$url  = "http://localhost:$Port/home.php"
. (Join-Path $PSScriptRoot 'paths.ps1')

# 'none' when nothing answers on the port, 'mine' when this copy's server
# answers, 'other' when a different copy (or another program) holds the port.
# health.php reports the folder it serves; Compare-LabServerRoot in paths.ps1
# checks it against this launcher's own root (drive-invariance R6).
$script:otherRoot = ''
function Get-ServerState {
    $body = $null
    try {
        $body = (Invoke-WebRequest "http://localhost:$Port/health.php" -TimeoutSec 2 -UseBasicParsing).Content
    } catch {
        # A redirect or an error page still means something is answering.
        if (-not $_.Exception.Response) { return 'none' }
        $body = ''
    }
    $c = Compare-LabServerRoot ([string]$body) $root
    $script:otherRoot = $c.root
    return $c.state
}

function Stop-OtherCopy {
    $who = if ($script:otherRoot) { "the copy in $($script:otherRoot)" }
           else { 'a server that does not report its folder (an older copy, such as the OneDrive original, or another program)' }
    $msg = "Port $Port is held by $who, not by this copy ($root). " +
           "This launcher will not open it. Stop that server (STOP SERVER.bat in that folder), then start this one again."
    Write-Host ''
    Write-Host "  $msg" -ForegroundColor Red
    Write-Host ''
    if ($Open) {
        try { (New-Object -ComObject WScript.Shell).Popup($msg, 0, 'Shared Lab Equipment', 48) | Out-Null } catch { }
    }
    exit 2
}

$state = Get-ServerState
if ($state -eq 'other') { Stop-OtherCopy }

# On PostgreSQL the estate's cluster on the drive must answer before the pages
# can (platform D-028, D-013). tools\estate-pg.ps1 does nothing on SQLite or
# outside the estate, and nothing when the cluster already runs. Its own
# process, so the PGTZ it sets never reaches the PHP server.
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'estate-pg.ps1') start
if ($LASTEXITCODE -ne 0) {
    $msg = 'PostgreSQL on the drive did not start, so the application cannot reach its data. The reason is shown above; the server log is in ' + (Get-LabDataDir) + '.'
    Write-Host ''
    Write-Host "  $msg" -ForegroundColor Red
    Write-Host ''
    if ($Open) {
        try { (New-Object -ComObject WScript.Shell).Popup($msg, 0, 'Shared Lab Equipment', 48) | Out-Null } catch { }
    }
    exit 3
}

if ($state -eq 'none') {
    # Quoted, for the same reason the supervisor quotes its own arguments: this
    # folder has a space in its name and Start-Process does no quoting, so an
    # unquoted -File never found the script and nothing was logged at all.
    $sup = Join-Path $PSScriptRoot 'supervisor.ps1'
    Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', ('"' + $sup + '"'),
        '-Port', "$Port"
    ) | Out-Null

    foreach ($i in 1..40) {
        Start-Sleep -Milliseconds 250
        $state = Get-ServerState
        if ($state -ne 'none') { break }
    }
}

if ($state -eq 'other') { Stop-OtherCopy }
if ($state -eq 'mine') {
    if ($Open) { Start-Process $url }
    exit 0
}

Write-Host ''
Write-Host '  The server did not come up.' -ForegroundColor Red
Write-Host ("  Look at " + (Join-Path (Get-LabDataDir) 'server.log') + " for the reason.")
Write-Host ''
if ($Open) { Read-Host '  Press Enter to close' }
exit 1
