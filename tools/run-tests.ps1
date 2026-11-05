# run-tests.ps1 - run the regression tests (replaces RUN TESTS.bat).
#
#     powershell -NoProfile -ExecutionPolicy Bypass -File tools\run-tests.ps1
#
# Run it before putting a change on the server. It builds a scratch database
# from the same schema install.php uses, checks the rules that cost money or
# leak data when they break, and prints a line per check. It never opens the
# live lab.sqlite, so it is safe to run while the application is in use.
# Anything that says FAIL is listed again at the bottom with what was expected
# and what happened instead. The exit code is the suite's own.

$root = Split-Path -Parent $PSScriptRoot
$phpDir = Join-Path $root 'tools\php'
$php = Join-Path $phpDir 'php.exe'

if (-not (Test-Path -LiteralPath $php)) {
    Write-Host ''
    Write-Host "  The bundled PHP is missing: $php" -ForegroundColor Red
    Write-Host '  Run SETUP.bat in this folder, or copy tools\php into it from the drive.'
    Write-Host ''
    Write-Host '  If this folder is on OneDrive, it has probably not finished downloading.'
    Write-Host '  Right-click the folder, choose "Always keep on this device", wait for the'
    Write-Host '  green checks, and start this again.'
    Write-Host ''
    exit 1
}

Push-Location $root
try {
    & $php -c (Join-Path $phpDir 'php.ini') -d "extension_dir=$(Join-Path $phpDir 'ext')" (Join-Path $root 'tests\run-tests.php')
    $result = $LASTEXITCODE
} finally { Pop-Location }

Write-Host ''
if ($result -eq 0) {
    Write-Host '  Everything holds. Safe to deploy.' -ForegroundColor Green
} else {
    Write-Host '  SOMETHING IS BROKEN. Do not deploy until the failures above are fixed.' -ForegroundColor Red
}
Write-Host ''
exit $result
