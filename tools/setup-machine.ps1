# setup-machine.ps1 — make this computer start the application by itself.
#
# The application folder travels with OneDrive. The instruction to run it at
# logon does not: a Startup shortcut lives in this computer's own user profile,
# outside the synced folder, so a new machine has the application but nothing
# telling it to run. This is the one click that fixes that, and it has to be
# run once per computer.
#
# Every path is worked out at run time, so it does not matter what the OneDrive
# folder is called on this machine or which account it is under.

$ErrorActionPreference = 'Stop'

$root    = Split-Path -Parent $PSScriptRoot
. (Join-Path $PSScriptRoot 'paths.ps1')
$startup = [Environment]::GetFolderPath('Startup')
$desktop = [Environment]::GetFolderPath('Desktop')
$ws      = New-Object -ComObject WScript.Shell

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
    Read-Host '  Press Enter to close'
    exit 1
}
$offline = (Get-Item $php).Attributes.ToString() -match 'Offline'
if ($offline) {
    Write-Host ''
    Write-Host '  PHP is here but not downloaded yet (OneDrive is holding it in the cloud).' -ForegroundColor Yellow
    Write-Host '  Right-click the folder, choose "Always keep on this device", and wait for'
    Write-Host '  the green ticks before relying on this.'
}

# --- start at logon, and something to click ----------------------------------
# Neither shortcut names this folder or its drive (drive-invariance R5): both
# run the estate launcher open-app.vbs through Get-LabShortcutArgs, which
# finds the drive under whatever letter it has today. Without open-app.vbs
# (another computer) no shortcut is written. Both name tools\ensure-running.ps1,
# which open-app.vbs runs through a hidden PowerShell (platform D-037, D-038:
# no .vbs launcher); the Desktop shortcut adds -Open to open the browser.
$logonArgs = Get-LabShortcutArgs 'tools\ensure-running.ps1'
$openArgs  = Get-LabShortcutArgs 'tools\ensure-running.ps1'
if ($openArgs) { $openArgs += ' -Open' }
if ($logonArgs -and $openArgs) {
    $lnk = $ws.CreateShortcut((Join-Path $startup 'Shared Lab Equipment.lnk'))
    $lnk.TargetPath       = $LabWscript
    $lnk.Arguments        = $logonArgs
    $lnk.WindowStyle      = 7
    $lnk.Description      = 'Keeps the Shared Laboratory Equipment server running'
    $lnk.Save()
    Write-Host "  Starts at logon : yes  ($startup)"

    $d = $ws.CreateShortcut((Join-Path $desktop 'Shared Lab Equipment.lnk'))
    $d.TargetPath       = $LabWscript
    $d.Arguments        = $openArgs
    $d.Description      = 'Open the Shared Laboratory Equipment system'
    $d.Save()
    Write-Host "  Desktop shortcut: yes  ($desktop)"
} else {
    Write-Host '  Shortcuts       : none written (the estate launcher open-app.vbs is not on this computer); start the application with START HERE.bat in its folder.'
}

# --- and start it now --------------------------------------------------------
Write-Host ''
Write-Host '  Starting it...'
& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $root 'tools\ensure-running.ps1')
if ($LASTEXITCODE -eq 0) {
    Write-Host '  Running at http://localhost:8147/' -ForegroundColor Green
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
    Write-Host '  be copying. Use one machine at a time until IT hosts it properly, and'
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
Read-Host '  Press Enter to close'
