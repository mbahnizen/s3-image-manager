$ErrorActionPreference = "Stop"

param(
    [Parameter(Mandatory = $true)]
    [string]$BackupFile,
    [string]$TargetDb = ""
)

$repoRoot = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($TargetDb)) {
    $TargetDb = Join-Path $repoRoot "data\image_manager.sqlite"
}

if (-not (Test-Path $BackupFile)) {
    throw "Backup file not found: $BackupFile"
}

$targetDir = Split-Path -Parent $TargetDb
New-Item -ItemType Directory -Force -Path $targetDir | Out-Null

Copy-Item $BackupFile $TargetDb -Force
Write-Output "Restored database to: $TargetDb"