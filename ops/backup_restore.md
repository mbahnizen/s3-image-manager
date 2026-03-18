# Backup and Restore (SQLite + S3)

## Goals
- Protect against accidental deletion or corruption of the SQLite database.
- Preserve objects in S3 with versioning and lifecycle retention.

## SQLite Backup (Windows)

### Run backup manually
```powershell
powershell -ExecutionPolicy Bypass -File C:\laragon\www\your-app\scripts\backup.ps1
```

Optional parameters:
- `-BackupDir` (default: `C:\laragon\www\your-app\backups`)
- `-KeepDays` (default: 30)

Example:
```powershell
powershell -ExecutionPolicy Bypass -File C:\laragon\www\your-app\scripts\backup.ps1 -BackupDir D:\backups\your-app -KeepDays 14
```

### Suggested schedule
- Daily at off-peak hours.
- Keep 7-30 days of snapshots.

## SQLite Restore (Windows)

### Restore from a backup file
```powershell
powershell -ExecutionPolicy Bypass -File C:\laragon\www\your-app\scripts\restore.ps1 -BackupFile C:\laragon\www\your-app\backups\image_manager_YYYYMMDD_HHMMSS.sqlite
```

Notes:
- Stop the PHP app/web server before restoring to avoid file locks.
- After restore, start the app and check `/api/health.php`.

## SQLite Backup (Linux)

### Run backup manually
```bash
bash /var/www/your-app/scripts/backup.sh
```

Optional parameters:
- `--backup-dir` (default: `/var/www/your-app/backups`)
- `--keep-days` (default: 30)

Example:
```bash
bash /var/www/your-app/scripts/backup.sh --backup-dir /var/backups/your-app --keep-days 14
```

### Suggested schedule
- Daily at off-peak hours.
- Keep 7-30 days of snapshots.

## SQLite Restore (Linux)

### Restore from a backup file
```bash
bash /var/www/your-app/scripts/restore.sh --backup-file /var/www/your-app/backups/image_manager_YYYYMMDD_HHMMSS.sqlite
```

Optional parameters:
- `--target-db` (default: `/var/www/your-app/data/image_manager.sqlite`)

Notes:
- Stop the PHP app/web server before restoring to avoid file locks.
- After restore, start the app and check `/api/health.php`.

## S3 Protection

1. Enable bucket versioning.
2. Add lifecycle policy:
   - Keep noncurrent versions for 7-30 days.
   - Optionally delete delete-markers after 7-30 days.
3. For critical assets, consider a secondary bucket for periodic sync.

## Validation Checklist
- `GET /api/health.php` returns `ok: true`.
- Load the gallery and verify file URLs are valid.
- Upload a test image and verify it appears in the workspace.
