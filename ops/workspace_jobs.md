# Workspace Jobs (Rename/Delete)

## Overview
Rename and delete workspace operations use a hybrid approach:
- If the workspace has <= `WORKSPACE_SYNC_THRESHOLD` assets, the operation runs synchronously.
- If it exceeds the threshold, the request is queued and processed by a worker.

## Configuration
Set in `.env`:
- `WORKSPACE_SYNC_THRESHOLD` (default: 20)

## Worker Script
- Windows: `C:\laragon\www\your-app\scripts\workspace_worker.php`
- Linux: `/var/www/your-app/scripts/workspace_worker.php`

## Run Manually

### Windows
```powershell
php C:\laragon\www\your-app\scripts\workspace_worker.php
```

### Linux
```bash
php /var/www/your-app/scripts/workspace_worker.php
```

## Scheduling

### Windows Task Scheduler
- Trigger: every 1-5 minutes.
- Action: `php C:\laragon\www\your-app\scripts\workspace_worker.php`

### Linux cron (every 2 minutes)
```bash
*/2 * * * * /usr/bin/php /var/www/your-app/scripts/workspace_worker.php
```

## Notes
- Jobs are processed one at a time in FIFO order.
- If a job fails, it remains in `failed` state with `last_error` for debugging.
- You can requeue by using the UI Retry button or the API endpoint.
