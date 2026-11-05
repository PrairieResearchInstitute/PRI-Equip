# setup-machine.ps1 - check this copy and start the application.
#
# SETUP.bat runs it as its last step (it replaces SETUP ON THIS PC.bat); run it
# again by hand on each computer you use:
#
#     powershell -NoProfile -ExecutionPolicy Bypass -File tools\setup-machine.ps1
#
# It checks the bundled PHP, starts the server, and warns about OneDrive. It
# writes nothing on the computer: an application on the estate drive keeps
# everything on the drive (owner, 2026-10-20 and 2026-11-03). A logon shortcut
# lives in the computer's own profile, so it is the owner's step; AGENTS.md
# ("Start at logon") and README.docx carry the one command that makes it.
#
# Every path is worked out at run time, so it does not matter which letter the
# drive has or which account runs it.

$ErrorActionPreference = 'Stop'

$root    = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'paths.ps1')

Write-Host ''
Write-Host '  Shared Laboratory Equipment - set up this computer' -ForegroundColor Cyan
Write-Host '  ================================================='
Write-Host ''
Write-Host "  Application : $root"

# --- is PHP actually here, or still in the cloud? ----------------------------
$php = Join-Path $root 'tools\php\php.exe'
if (-not (Test-Path $php)) {
    Write-Host ''
    Write-Host '  PHP is missing from tools\php.' -ForegroundColor Red
    Write-Host '  If this folder is on OneDrive it has probably not finished downloading.'
    Write-Host '  Right-click the folder, choose "Always keep on this device", wait for the'
    Write-Host '  green ticks, and run this again.'
    Write-Host ''
    exit 1
}
$offline = (Get-Item $php).Attributes.ToString() -match 'Offline'
if ($offline) {
    Write-Host ''
    Write-Host '  PHP is here but not downloaded yet (OneDrive is holding it in the cloud).' -ForegroundColor Yellow
    Write-Host '  Right-click the folder, choose "Always keep on this device", and wait for'
    Write-Host '  the green ticks before relying on this.'
}

# --- start at logon: the owner's step -----------------------------------------
# The shortcut targets PaCELab Equip.exe through the estate's fixed folder
# (Get-LabFixedPath), so it names no drive letter (drive-invariance R5) and
# follows the drive under any letter. Printed only when that fixed folder is
# this very folder (not another computer, not a copy elsewhere).
$exe = Get-LabFixedPath 'PaCELab Equip.exe'
Write-Host ''
if ($exe) {
    Write-Host '  To start PaCELab Equip at logon, make a shortcut named "Shared Lab Equipment"'
    Write-Host '  in your Startup folder (it replaces any older one) whose target is'
    Write-Host "    $exe"
    Write-Host '  The one PowerShell command that does it is in README.docx and AGENTS.md ("Start at logon").'
} else {
    Write-Host '  Estate\App_to_Migrate on the system drive does not lead to this folder, so no'
    Write-Host '  logon shortcut is offered; start the application with PaCELab Equip.exe in its folder.'
}

# --- and start it now --------------------------------------------------------
Write-Host ''
Write-Host '  Starting it...'
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'tools\serve.ps1') start
if ($LASTEXITCODE -eq 0) {
    Write-Host '  Running at http://127.0.0.1:8147/' -ForegroundColor Green
} else {
    Write-Host ('  It did not start. See ' + (Join-Path (Get-LabDataDir) 'server.log') + '.') -ForegroundColor Red
}

# --- the thing that will actually bite ---------------------------------------
$dataDir = Get-LabDataDir
$db = Join-Path $dataDir 'lab.sqlite'
if ((Test-Path $db) -and ($db -like '*OneDrive*')) {
    Write-Host ''
    Write-Host '  One warning worth reading' -ForegroundColor Yellow
    Write-Host '  -------------------------'
    Write-Host '  The database is inside the OneDrive folder, so it syncs between your'
    Write-Host '  computers. That is fine while only one computer is using it at a time.'
    Write-Host '  Running it on two at once can produce OneDrive conflict copies and lose'
    Write-Host '  charges, because a database being written to is not a file sync should'
    Write-Host '  be copying. Use one computer at a time until IT hosts it properly, and'
    Write-Host '  then everybody uses the one address instead of this folder.'
}

# --- has that already happened? ----------------------------------------------
$conflicts = Get-ChildItem $dataDir -Filter '*lab*' -ErrorAction SilentlyContinue |
             Where-Object { $_.Name -ne 'lab.sqlite' -and $_.Name -like '*lab*.sqlite*' }
if ($conflicts) {
    Write-Host ''
    Write-Host '  OneDrive has already made conflict copies of the database:' -ForegroundColor Red
    $conflicts | ForEach-Object { Write-Host "    $($_.Name)" }
    Write-Host '  Work out which is current before using the application further.'
}
Write-Host ''
