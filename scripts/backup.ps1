$ErrorActionPreference = "Stop"

param(
    [string]$BackupDir = "",
    [int]$KeepDays = 30
)

$repoRoot = Split-Path -Parent $PSScriptRoot
$dbPath = Join-Path $repoRoot "data\image_manager.sqlite"
if (-not (Test-Path $dbPath)) {
    throw "Database not found: $dbPath"
}

if ([string]::IsNullOrWhiteSpace($BackupDir)) {
    $BackupDir = Join-Path $repoRoot "backups"
}

New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupPath = Join-Path $BackupDir ("image_manager_$timestamp.sqlite")

if (Get-Command sqlite3 -ErrorAction SilentlyContinue) {
    sqlite3 $dbPath ".backup '$backupPath'"
} else {
    Copy-Item $dbPath $backupPath -Force
}

$latestPath = Join-Path $BackupDir "latest.sqlite"
Copy-Item $backupPath $latestPath -Force

if ($KeepDays -gt 0) {
    Get-ChildItem -Path $BackupDir -Filter "image_manager_*.sqlite" |
        Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$KeepDays) } |
        Remove-Item -Force
}

Write-Output "Backup created: $backupPath"