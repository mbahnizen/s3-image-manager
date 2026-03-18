# Nizen Image Manager

Nizen Image Manager is a lightweight PHP app for uploading, organizing, and managing images in S3-compatible storage. It provides a single-admin UI for workspaces, metadata (alt/caption), and stable public URLs for use in articles.

**Highlights**
- Admin login with session hardening and CSRF protection.
- Workspace-based organization with rename/delete workflows.
- Direct upload to S3-compatible storage with public URLs.
- Background worker for large workspace rename/delete operations.
- SQLite storage with simple backup/restore scripts.
- UI optimized for bulk upload, clipboard paste, and quick metadata editing.

## Purpose
This project was built to give content creators a simple uploader (lighter than full S3 clients) that generates public URLs and helps manage alt text and captions while uploading. It is especially useful for technical writers and web developers who store assets outside their main server.

**Key advantages**
- Bulk upload with per-image alt text and caption editing.
- Easy renaming for clean, consistent filenames.
- Ready-to-copy snippets for fast publishing.

**Non-goals**
- Not a full digital asset management (DAM) platform.
- No multi-user roles or advanced permissions.
- No in-browser image editing or transformations.

## How to Use
1. Open the app in your browser and log in with `ADMIN_PASSWORD`.
2. Create a workspace to group assets by article or topic.
3. Upload images via drag-and-drop, file picker, or clipboard paste.
4. Edit filename, alt text, and caption before or after upload.
5. Copy the generated URL or snippet and paste into your article.

## Architecture
- **Backend**: PHP + SQLite (`data/image_manager.sqlite`).
- **Storage**: S3-compatible object storage (public-read by default).
- **Frontend**: Single-page UI in `public/index.php` using `public/app.js` and `public/app.css`.
- **Jobs**: Queue-based workspace rename/delete via `scripts/workspace_worker.php`.

## Requirements
- PHP 8.x (recommended).
- Extensions: `pdo_sqlite`, `sqlite3`, `curl`, `fileinfo`, `mbstring`, `openssl`.
- S3-compatible storage endpoint and credentials.

## Quick Start (Local)
1. Copy environment file and edit secrets:
   - Copy `.env.example` to `.env`.
   - Set a strong `ADMIN_PASSWORD`.
   - Set `S3_*` values for your storage.
2. Run the app:
   - `php -S localhost:8000 -t public`
3. Open:
   - `http://localhost:8000`

## Deployment
Below are minimal examples. Adjust to your environment and security standards.

### Apache (VirtualHost)
Enable `mod_rewrite` and point the document root to `public/`.
```
<VirtualHost *:80>
  ServerName uploader.local
  DocumentRoot "C:/laragon/www/your-app/public"
  <Directory "C:/laragon/www/your-app/public">
    AllowOverride All
    Require all granted
  </Directory>
</VirtualHost>
```

### Nginx (Server Block)
```
server {
  listen 80;
  server_name uploader.local;
  root /var/www/uploader/public;
  index index.php;

  location / {
    try_files $uri /index.php?$query_string;
  }

  location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
  }
}
```

### Laragon (Windows)
- Place the project under `C:\laragon\www\`.
- Ensure the web root is `public/` (Laragon's auto-virtual host points to folder root; use a `public` alias if needed).
- Open `http://your-app.test` or similar.

## Configuration
All configuration is via `.env` (loaded in `src/bootstrap.php`).

**Required**
- `ADMIN_PASSWORD`: Admin login password.
- `S3_ENDPOINT`, `S3_BUCKET`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_REGION`.

**Optional**
- `S3_ACL`: Defaults to `public-read`.
- `PUBLIC_URL_BASE`: Public base URL to serve objects.
- `SESSION_LIFETIME_SECONDS`: Default `43200`.
- `SESSION_IDLE_TIMEOUT_SECONDS`: Default `7200`.
- `WORKSPACE_SYNC_THRESHOLD`: Default `20` (assets limit for synchronous rename/delete).
- `WORKSPACE_JOB_STALE_MINUTES`: Default `15`.

**Notes**
- `.env` is read at runtime. If you change it, reload the PHP server.
- Do not commit `.env` to public repositories.
- If `PUBLIC_URL_BASE` is not set, URLs are built from `S3_ENDPOINT` and `S3_BUCKET`.

## Storage Behavior
- Uploads are stored under a workspace slug prefix.
- Objects are public by default and URLs are returned as stable public URLs.
- Filenames are normalized and a unique suffix is added to prevent collisions.

## Security Notes
- Admin-only access with IP-bound sessions and idle timeout.
- CSRF protection on all mutating endpoints.
- Login rate limiting with IP lockout (5 attempts/15 minutes).
- CSP and security headers enabled for the UI.
- Upload validation includes MIME checks and image dimension limits.

## Background Worker (Workspace Jobs)
Large workspace rename/delete operations are queued and processed by a worker.

**How it works**
- If a workspace has **<= `WORKSPACE_SYNC_THRESHOLD`** assets, rename/delete runs synchronously.
- If it exceeds the threshold, a job is queued in `workspace_jobs` and processed by the worker.
- Jobs update `status`, `progress`, `total`, and `last_error` for UI tracking.

**Run manually**
- Windows: `php C:\laragon\www\your-app\scripts\workspace_worker.php`
- Linux: `php /var/www/uploader/scripts/workspace_worker.php`

**Recommended schedule**
- Every 1-5 minutes (Task Scheduler or cron).

**Job states**
- `queued` → `running` → `completed`
- `failed` if a copy/delete step fails or DB update fails
- `canceled` for queued jobs explicitly canceled

**Operational tips**
- If a job is stuck in `running`, use "Mark Stale" or the force reset API.
- Increasing `WORKSPACE_SYNC_THRESHOLD` reduces queue usage but may slow UI operations.

**Cron/Task Scheduler recommendation**
- For small teams: every **2 minutes** is a good default.
- For heavier usage: every **1 minute**.
- For low traffic: every **5 minutes**.

**Example cron (Linux)**
```
*/2 * * * * /usr/bin/php /var/www/uploader/scripts/workspace_worker.php
```

**Example Task Scheduler (Windows)**
- Trigger: every 2 minutes.
- Action: `php C:\laragon\www\your-app\scripts\workspace_worker.php`

## API Endpoints (Authenticated)
- `POST /api/login.php` - login and receive CSRF token.
- `GET /api/workspaces.php` - list workspaces.
- `POST /api/workspaces.php` - create workspace.
- `GET /api/list_assets.php?workspace_id=...` - list assets.
- `POST /api/upload.php` - upload image.
- `POST /api/update_asset.php` - update metadata.
- `POST /api/rename_asset.php` - rename asset.
- `POST /api/delete.php` - delete asset.
- `POST /api/rename_workspace.php` - rename workspace (sync or queue).
- `POST /api/delete_workspace.php` - delete workspace (sync or queue).
- `GET /api/workspace_jobs.php` - list jobs.
- `GET /api/workspace_jobs_active.php` - active jobs.
- `GET /api/workspace_job_status.php?id=...` - job status.
- `POST /api/workspace_job_retry.php` - retry failed job.
- `POST /api/workspace_job_cancel.php` - cancel queued job.
- `POST /api/workspace_job_force_reset.php` - mark stale job failed.
- `POST /api/cleanup_deleted.php` - purge soft-deleted assets.
- `GET /api/health.php` - health checks (requires auth).

All POST requests require a valid CSRF token in `X-CSRF-TOKEN`.

## Backup & Restore
See `ops/backup_restore.md` for scripted backup and restore instructions.

## Operations Docs
- `ops/backup_restore.md` - backup/restore procedures and S3 protection tips.
- `ops/workspace_jobs.md` - workspace job behavior and scheduling notes.

## Scripts
- `scripts/workspace_worker.php` - processes queued workspace jobs.
- `scripts/backup.ps1` / `scripts/backup.sh` - create SQLite backups.
- `scripts/restore.ps1` / `scripts/restore.sh` - restore from backups.

## Project Structure
- `public/` - Web root, UI, assets.
- `api/` - JSON endpoints.
- `src/` - Core services and bootstrap.
- `data/` - SQLite database (local state).
- `scripts/` - Backup/restore and worker.
- `ops/` - Operational docs.
- `docs/` - Screenshots.

## Public Repository Notes
- Never commit `.env` or real credentials.
- Rotate `S3_ACCESS_KEY`, `S3_SECRET_KEY`, and `ADMIN_PASSWORD` before publishing.
- If `data/image_manager.sqlite` contains production data, remove it from git history.
- Keep backups of the database and enable bucket versioning if possible.

## Screenshots
Light and dark mode previews (stored in `docs/`):

**Upload**
- Light: `docs/upload-light.png`
- Dark: `docs/upload-dark.png`

**Gallery**
- Light: `docs/gallery-light.png`
- Dark: `docs/gallery-dark.png`

**Workspaces**
- Light: `docs/workspace-light.png`
- Dark: `docs/workspace-dark.png`

## Troubleshooting
- **401 Unauthorized**: Login expired or CSRF missing. Re-login and retry.
- **S3 Upload Failed**: Check `S3_*` values and bucket permissions.
- **Job Stuck in Running**: Use the "Mark Stale" action in UI or the force reset API.

## License
MIT
