#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR=""
KEEP_DAYS=30

while [[ $# -gt 0 ]]; do
  case "$1" in
    --backup-dir)
      BACKUP_DIR="$2"
      shift 2
      ;;
    --keep-days)
      KEEP_DAYS="$2"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_PATH="$REPO_ROOT/data/image_manager.sqlite"

if [[ ! -f "$DB_PATH" ]]; then
  echo "Database not found: $DB_PATH" >&2
  exit 1
fi

if [[ -z "$BACKUP_DIR" ]]; then
  BACKUP_DIR="$REPO_ROOT/backups"
fi

mkdir -p "$BACKUP_DIR"
TIMESTAMP="$(date +"%Y%m%d_%H%M%S")"
BACKUP_PATH="$BACKUP_DIR/image_manager_${TIMESTAMP}.sqlite"

if command -v sqlite3 >/dev/null 2>&1; then
  sqlite3 "$DB_PATH" ".backup '$BACKUP_PATH'"
else
  cp -f "$DB_PATH" "$BACKUP_PATH"
fi

cp -f "$BACKUP_PATH" "$BACKUP_DIR/latest.sqlite"

if [[ "$KEEP_DAYS" -gt 0 ]]; then
  find "$BACKUP_DIR" -type f -name "image_manager_*.sqlite" -mtime "+$KEEP_DAYS" -delete
fi

echo "Backup created: $BACKUP_PATH"