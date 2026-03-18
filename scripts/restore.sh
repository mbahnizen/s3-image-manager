#!/usr/bin/env bash
set -euo pipefail

BACKUP_FILE=""
TARGET_DB=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --backup-file)
      BACKUP_FILE="$2"
      shift 2
      ;;
    --target-db)
      TARGET_DB="$2"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

if [[ -z "$BACKUP_FILE" ]]; then
  echo "--backup-file is required" >&2
  exit 1
fi

if [[ ! -f "$BACKUP_FILE" ]]; then
  echo "Backup file not found: $BACKUP_FILE" >&2
  exit 1
fi

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ -z "$TARGET_DB" ]]; then
  TARGET_DB="$REPO_ROOT/data/image_manager.sqlite"
fi

mkdir -p "$(dirname "$TARGET_DB")"
cp -f "$BACKUP_FILE" "$TARGET_DB"

echo "Restored database to: $TARGET_DB"