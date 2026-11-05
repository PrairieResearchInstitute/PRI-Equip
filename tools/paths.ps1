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

# The identity of a folder, not its spelling: the volume serial number and the
# file index Windows gives it, so the estate's fixed folder (a mount of the
# drive) and the same folder under the drive's own letter, or through a
# junction or subst, give one answer. $null when the folder does not exist.
# Comparing spellings as text called one folder two copies (incident
# 2026-11-03: the old launcher refused to start, "held by the copy in" the
# fixed-folder spelling of this very folder).
if (-not ('LabFolderId' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using Microsoft.Win32.SafeHandles;
public static class LabFolderId {
    [StructLayout(LayoutKind.Sequential)] struct FT { public uint lo, hi; }
    [StructLayout(LayoutKind.Sequential)] struct BHFI {
        public uint attr; public FT created, accessed, written;
        public uint volume, sizeHi, sizeLo, links, indexHi, indexLo;
    }
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    static extern SafeFileHandle CreateFile(string name, uint access, uint share, IntPtr security, uint disposition, uint flags, IntPtr template);
    [DllImport("kernel32.dll", SetLastError = true)]
    static extern bool GetFileInformationByHandle(SafeFileHandle handle, out BHFI info);
    public static string Of(string path) {
        // No access rights, share everything, open existing, FILE_FLAG_BACKUP_SEMANTICS (a folder).
        using (SafeFileHandle h = CreateFile(path, 0, 7, IntPtr.Zero, 3, 0x02000000, IntPtr.Zero)) {
            if (h.IsInvalid) return null;
            BHFI i;
            if (!GetFileInformationByHandle(h, out i)) return null;
            return i.volume.ToString("x") + ":" + i.indexHi.ToString("x") + ":" + i.indexLo.ToString("x");
        }
    }
}
'@
}

function Get-LabFolderId([string]$path) {
    if (-not $path) { return $null }
    try { return [LabFolderId]::Of($path.TrimEnd('\', '/') + '\') } catch { return $null }
}

# True only when both paths reach one existing folder, however each is spelled.
function Test-LabSameFolder([string]$a, [string]$b) {
    $x = Get-LabFolderId $a
    return ($null -ne $x) -and ($x -eq (Get-LabFolderId $b))
}

# A path to $rel inside this application through the estate's fixed folder,
# <system drive>\Estate\App_to_Migrate\apps\<app>, for a shortcut that names no
# drive letter (drive-invariance R5). It keeps %SystemDrive% unexpanded, which
# Windows expands when the shortcut runs (target and arguments alike). $null
# unless the fixed folder really is this folder (another computer, a stale
# mount, a copy elsewhere), and then no shortcut is written.
function Get-LabFixedPath([string]$rel, [string]$systemDrive = $env:SystemDrive) {
    $app   = Split-Path -Leaf $root
    $fixed = Join-Path $systemDrive ('Estate\App_to_Migrate\apps\' + $app)
    if (-not (Test-LabSameFolder $fixed $root)) { return $null }
    return ('%SystemDrive%\Estate\App_to_Migrate\apps\' + $app + '\' + $rel)
}

# Which copy of the application answers on the port (drive-invariance R6).
# $body is what /health.php returned; its "root" is the folder that server
# serves. 'mine' only when that folder is this copy's own folder by identity
# (Test-LabSameFolder), so a launcher never opens a server another copy started
# (the OneDrive original, an old drive letter) and never refuses this folder
# reached through another spelling. Anything else that answers is 'other',
# with the root it named, or '' when it named none.
function Compare-LabServerRoot([string]$body, [string]$mine) {
    $reported = ''
    try { $reported = [string](($body | ConvertFrom-Json).root) } catch { $reported = '' }
    if (-not $reported) { return @{ state = 'other'; root = '' } }
    if (Test-LabSameFolder $reported $mine) {
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
