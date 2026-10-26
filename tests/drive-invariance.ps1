# drive-invariance.ps1 - the PowerShell half of the drive-invariance checks.
#
#     powershell -NoProfile -ExecutionPolicy Bypass -File tests\drive-invariance.ps1
#
# tests\run-tests.php covers the PHP loader; this file covers its PowerShell
# twin in tools\paths.ps1 and the two launcher rules: a launcher opens only a
# server that serves this very folder (R6), and a shortcut never names the
# drive (R5). Everything happens in a scratch folder of its own, removed at
# the end. tools\check.js runs it on Windows. Healthy output ends with
# "All N drive-invariance checks passed."

$ErrorActionPreference = 'Stop'
$realApp = Split-Path -Parent $PSScriptRoot
$root = $realApp
. (Join-Path $realApp 'tools\paths.ps1')

$script:passed = 0
$script:failed = @()
function Check([string]$what, [bool]$cond, [string]$detail = '') {
    if ($cond) { $script:passed++; Write-Host "    pass  $what"; return }
    $script:failed += $what
    Write-Host "    FAIL  $what   [$detail]"
}

# The scratch folder goes beside the data folder when one is configured
# (owner standard B), otherwise in the system temp folder.
$base = Get-LabTempDir
if (-not $base -or -not (Test-Path -LiteralPath (Split-Path -Parent $base))) { $base = [IO.Path]::GetTempPath() }
if (-not (Test-Path -LiteralPath $base)) { New-Item -ItemType Directory -Path $base | Out-Null }
$scratch = Join-Path $base ('drive-inv-ps-' + $PID)

# A letter with no drive behind it, computed so no letter is written here.
$unmounted = ''
foreach ($c in [char[]]'ZYXWVUTSRQPONMLKJIHGFED') {
    if (-not (Test-Path -LiteralPath ("$c" + ':\'))) { $unmounted = "$c"; break }
}

Write-Host ''
Write-Host '  The PowerShell tools follow the drive (drive-invariance)'
try {
    # A fake estate: <scratch>\App_to_Migrate\apps\pacelab-equip and its data.
    $estate  = Join-Path $scratch 'App_to_Migrate'
    $fakeApp = Join-Path $estate 'apps\pacelab-equip'
    $backups = Join-Path $estate 'data\pacelab-equip\backups'
    New-Item -ItemType Directory -Force -Path $fakeApp, $backups | Out-Null
    $root = $fakeApp

    Check 'a relative backup folder resolves against the application folder' `
        ((Resolve-LabBackupTarget '..\..\data\pacelab-equip\backups' 'test') -eq [IO.Path]::GetFullPath($backups))

    # R2: the value written while the drive had another letter.
    $stale = $unmounted + ':\App_to_Migrate\data\pacelab-equip\backups'
    $found = ''
    try { $found = Resolve-LabBackupTarget $stale 'test' } catch { $found = $_.Exception.Message }
    Check 'a backup folder stored under another drive letter is found under the estate today' `
        ($found -eq $backups) "$stale gave $found"

    $refused = $false
    try { Resolve-LabBackupTarget ($unmounted + ':\no-such-' + $PID + '\backups') 'test' | Out-Null } catch { $refused = $true }
    Check 'a missing absolute backup folder is refused, never created' $refused

    # R6: only this copy's own server counts.
    $mine  = (@{ app = 'pacelab-equip'; root = $fakeApp } | ConvertTo-Json)
    $other = (@{ app = 'pacelab-equip'; root = (Join-Path $scratch 'elsewhere\pacelab-equip') } | ConvertTo-Json)
    Check 'a server reporting this folder is this copy' ((Compare-LabServerRoot $mine $fakeApp).state -eq 'mine')
    Check 'a trailing separator or other case is still this copy' `
        ((Compare-LabServerRoot (@{ root = ($fakeApp.ToUpper() + '\') } | ConvertTo-Json) $fakeApp).state -eq 'mine')
    $o = Compare-LabServerRoot $other $fakeApp
    Check 'a server reporting another folder is another copy, named' (($o.state -eq 'other') -and ($o.root -like '*elsewhere*'))
    $n = Compare-LabServerRoot '<html>It works</html>' $fakeApp
    Check 'an answer with no root is never taken for this copy' (($n.state -eq 'other') -and ($n.root -eq ''))

    # R5: a shortcut runs the estate launcher and names no drive.
    $sysDrive = Join-Path $scratch 'sysdrive'
    New-Item -ItemType Directory -Force -Path $sysDrive | Out-Null
    Check 'no shortcut without the estate launcher' ($null -eq (Get-LabShortcutArgs 'tools\ensure-running.ps1' $sysDrive))
    $openApp = Join-Path $sysDrive 'Estate\App_to_Migrate\harness\tools'
    New-Item -ItemType Directory -Force -Path $openApp | Out-Null
    Set-Content -LiteralPath (Join-Path $openApp 'open-app.vbs') -Value "' test" -Encoding ascii
    $a = Get-LabShortcutArgs 'tools\ensure-running.ps1' $sysDrive
    Check 'the shortcut arguments name the app folder and launcher, through %SystemDrive%' `
        ($a -eq '"%SystemDrive%\Estate\App_to_Migrate\harness\tools\open-app.vbs" pacelab-equip "tools\ensure-running.ps1"') "$a"
    Check 'the shortcut target and arguments hold no drive letter' `
        (-not ($a -match '[A-Za-z]:\\') -and -not ($LabWscript -match '[A-Za-z]:\\')) "$LabWscript $a"
    $setup = Get-Content -LiteralPath (Join-Path $realApp 'tools\setup-machine.ps1') -Raw
    Check 'setup-machine writes no shortcut from the folder path' `
        (-not ($setup -match '\.(TargetPath|WorkingDirectory|Arguments)\s*=.*\$root'))
    foreach ($l in 'tools\ensure-running.ps1', 'START HERE.bat') {
        Check "the launcher $l exists in the application folder" (Test-Path -LiteralPath (Join-Path $realApp $l))
    }
} finally {
    Remove-Item -LiteralPath $scratch -Recurse -Force -ErrorAction SilentlyContinue
}

Write-Host ''
if ($script:failed.Count) {
    Write-Host "$($script:failed.Count) FAILED, $($script:passed) passed"
    exit 1
}
Write-Host "All $($script:passed) drive-invariance checks passed."
exit 0
