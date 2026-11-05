# reset-admin-password.ps1 - get back into the admin panel (replaces FORGOT ADMIN PASSWORD.bat).
#
#     powershell -NoProfile -ExecutionPolicy Bypass -File tools\reset-admin-password.ps1
#
# Use it when you are locked out: a forgotten password, or too many failed
# sign-ins and the throttle will not let you try again. It lists the
# administrator accounts, asks which one to reset, clears its failed sign-in
# attempts, then asks admin-recovery.php for the new password, which is not
# echoed as you type. Entering nothing at the username prompt changes nothing.
#
# It changes the database of THIS copy only and never goes to the server: the
# build refuses to ship tools\, and admin-recovery.php answers 403 to any web
# request, so it only ever runs from a command line. Run it in a normal
# PowerShell window; it asks questions.

$root = Split-Path -Parent $PSScriptRoot
$phpDir = Join-Path $root 'tools\php'
$php = Join-Path $phpDir 'php.exe'

if (-not (Test-Path -LiteralPath $php)) {
    Write-Host ''
    Write-Host "  The bundled PHP is missing: $php" -ForegroundColor Red
    Write-Host '  Run SETUP.bat in this folder, or copy tools\php into it from the drive.'
    Write-Host ''
    exit 1
}

$phpArgs = @('-c', (Join-Path $phpDir 'php.ini'), '-d', "extension_dir=$(Join-Path $phpDir 'ext')")
$recovery = Join-Path $root 'admin-recovery.php'
$Host.UI.RawUI.WindowTitle = 'PaCELab Equip - administrator recovery'

Write-Host ''
Write-Host '  Administrator accounts on this computer'
Write-Host '  ======================================='
Write-Host ''
& $php @phpArgs $recovery list
Write-Host ''

$who = (Read-Host '  Which username? (blank to cancel)').Trim()
if (-not $who) {
    Write-Host ''
    Write-Host '  Cancelled. Nothing was changed.'
    Write-Host ''
    exit 0
}

Write-Host ''
Write-Host '  Clearing any failed sign-in attempts first...'
& $php @phpArgs $recovery unlock $who

Write-Host ''
Write-Host '  Now the new password. Ten characters or more.'
Write-Host ''
& $php @phpArgs $recovery reset $who
$code = $LASTEXITCODE

Write-Host ''
Write-Host '  Start the application with PaCELab Equip.exe and sign in at'
Write-Host '    http://127.0.0.1:8147/admin/login.php'
Write-Host ''
exit $code
