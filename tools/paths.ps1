# paths.ps1 - where the running application keeps its data, for the PowerShell tools.
#
# Dot-source it ( . (Join-Path $PSScriptRoot 'paths.ps1') ) after setting $root.
# It reads config.local.json beside the application the same way
# includes\local-config.php does: dataFolder, relative to the application
# folder, names the folder holding lab.sqlite, .installed and the logs. With no
# config.local.json, or an empty dataFolder, everything stays in data\ inside
# the application folder, as it always did.
#
# Run on its own it prints the resolved paths as JSON (tools\check.js compares
# them with the PHP side):  powershell -File tools\paths.ps1

param([string]$AppRoot)

if (-not $root) { $root = if ($AppRoot) { $AppRoot } else { Split-Path -Parent $PSScriptRoot } }

function Get-LabConfig {
    $f = Join-Path $root 'config.local.json'
    if (-not (Test-Path -LiteralPath $f)) { return $null }
    try { return (Get-Content -LiteralPath $f -Raw -Encoding UTF8 | ConvertFrom-Json) } catch { return $null }
}

function Resolve-LabPath([string]$rel) {
    if ([IO.Path]::IsPathRooted($rel)) { return $rel }
    return [IO.Path]::GetFullPath((Join-Path $root $rel))
}

function Get-LabDataDir {
    $c = Get-LabConfig
    if ($c -and $c.dataFolder -and "$($c.dataFolder)".Trim()) {
        return Resolve-LabPath ("$($c.dataFolder)".Trim())
    }
    return (Join-Path $root 'data')
}

# The same folder under this copy's own drive, for an absolute path written
# while the drive had another letter: the tail after the estate folder (two
# above the application), then the same path under the application's own
# drive root. $null when neither exists. Twin of lab_reroot() in PHP.
function Find-LabRerooted([string]$abs) {
    $norm   = $abs -replace '/', '\'
    $estate = Split-Path -Parent (Split-Path -Parent $root)
    $name   = Split-Path -Leaf $estate
    $cands  = @()
    if ($name) {
        $m = [regex]::Match($norm, '(?i)(^|\\)' + [regex]::Escape($name) + '(\\.*)?$')
        if ($m.Success) { $cands += ($estate + $m.Groups[2].Value) }
    }
    $own  = [IO.Path]::GetPathRoot($root)
    $tail = [regex]::Match($norm, '^[A-Za-z]:\\(.+)$')
    if ($tail.Success -and $own -match '^[A-Za-z]:\\$') {
        $cands += (Join-Path $own $tail.Groups[1].Value)
    }
    foreach ($c in $cands) {
        if (Test-Path -LiteralPath $c -PathType Container) { return $c }
    }
    return $null
}

# A backup folder named in backup-path.txt or backupFolder (drive-invariance
# R1 to R3): relative resolves against the application folder; an absolute
# folder that exists stands; one that does not is re-rooted, or refused with a
# message, so no backup creates folders on whatever volume holds that letter.
function Resolve-LabBackupTarget([string]$p, [string]$source) {
    if (-not [IO.Path]::IsPathRooted($p)) { return (Resolve-LabPath $p) }
    if (Test-Path -LiteralPath $p -PathType Container) { return $p }
    $moved = Find-LabRerooted $p
    if ($moved) { return $moved }
    throw "Backup folder not found at $p (named in $source). Connect the drive that holds it, or write the path relative to the application folder, and start again."
}

# data\backup-path.txt in the data folder wins, then backupFolder, then
# <dataFolder>\backups when a data folder is configured, then the old sibling
# folder beside the application. Both named values go through
# Resolve-LabBackupTarget, the twin of lab_backup_target() in PHP.
function Get-LabBackupDir {
    $pathFile = Join-Path (Get-LabDataDir) 'backup-path.txt'
    if (Test-Path -LiteralPath $pathFile) {
        $p = (Get-Content -LiteralPath $pathFile -Raw).Trim()
        if ($p) { return (Resolve-LabBackupTarget $p $pathFile) }
    }
    $c = Get-LabConfig
    if ($c -and $c.backupFolder -and "$($c.backupFolder)".Trim()) {
        return (Resolve-LabBackupTarget ("$($c.backupFolder)".Trim()) 'backupFolder in config.local.json')
    }
    if ($c -and $c.dataFolder -and "$($c.dataFolder)".Trim()) {
        return (Join-Path (Get-LabDataDir) 'backups')
    }
    return (Join-Path (Split-Path -Parent $root) 'PaCELab Equip Backups')
}

# The arguments of a shortcut that starts $launcher (a file in this folder)
# without naming the drive (drive-invariance R5): wscript.exe runs the estate
# launcher open-app.vbs in the harness at %SystemDrive%\Estate (a fixed folder
# that always leads to the drive), which finds the drive under whatever letter
# it has today. The value keeps %SystemDrive% unexpanded; Windows expands it
# when the shortcut runs. $null when open-app.vbs is absent (another computer),
# and then no shortcut is written.
$LabWscript = '%SystemRoot%\System32\wscript.exe'
function Get-LabShortcutArgs([string]$launcher, [string]$systemDrive = $env:SystemDrive) {
    $openApp = Join-Path $systemDrive 'Estate\App_to_Migrate\harness\tools\open-app.vbs'
    if (-not (Test-Path -LiteralPath $openApp)) { return $null }
    $app = Split-Path -Leaf $root
    return ('"%SystemDrive%\Estate\App_to_Migrate\harness\tools\open-app.vbs" ' + $app + ' "' + $launcher + '"')
}

# Which copy of the application answers on the port (drive-invariance R6).
# $body is what /health.php returned; its "root" is the folder that server
# serves. 'mine' only when that folder is this copy's own root, so a launcher
# never opens a server another copy started (the OneDrive original, an old
# drive letter). Anything else that answers is 'other', with the root it
# named, or '' when it named none.
function Compare-LabServerRoot([string]$body, [string]$mine) {
    $reported = ''
    try { $reported = [string](($body | ConvertFrom-Json).root) } catch { $reported = '' }
    if (-not $reported) { return @{ state = 'other'; root = '' } }
    $norm = {
        param($p)
        try { $p = [IO.Path]::GetFullPath($p) } catch { }
        return $p.TrimEnd('\', '/')
    }
    if ([string]::Equals((& $norm $reported), (& $norm $mine), [StringComparison]::OrdinalIgnoreCase)) {
        return @{ state = 'mine'; root = $reported }
    }
    return @{ state = 'other'; root = $reported }
}

# Scratch space beside the data folder: the PHP session folder, uploads, the
# restore drill and the gate's copy. Empty when no data folder is configured,
# so the server's own temp stands (D-007). The PHP twin is lab_temp_dir().
function Get-LabTempDir {
    $c = Get-LabConfig
    if ($c -and $c.dataFolder -and "$($c.dataFolder)".Trim()) {
        return (Join-Path (Split-Path -Parent (Get-LabDataDir)) 'tmp')
    }
    return ''
}

if ($MyInvocation.InvocationName -ne '.') {
    $d = Get-LabDataDir
    [ordered]@{
        dataDir     = $d
        database    = (Join-Path $d 'lab.sqlite')
        installLock = (Join-Path $d '.installed')
        serverLog   = (Join-Path $d 'server.log')
        backupDir   = (Get-LabBackupDir)
        tempDir     = (Get-LabTempDir)
    } | ConvertTo-Json
}
