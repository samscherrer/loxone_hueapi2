#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_ROOT"

if ! git rev-parse --show-toplevel >/dev/null 2>&1; then
  echo "[ERROR] Dieses Skript muss innerhalb des Git-Repositories ausgeführt werden." >&2
  exit 1
fi

target_dir="${REPO_ROOT}/dist"
mkdir -p "$target_dir"

version_tag="$(git describe --tags --always 2>/dev/null || echo HEAD)"
archive_path="${target_dir}/hueapiv2-${version_tag}.zip"

echo "[INFO] Erzeuge Plugin-Archiv unter ${archive_path}" >&2

git archive \
  --format=zip \
  --prefix=hueapiv2/ \
  --output="$archive_path" \
  HEAD

echo "[OK] Archiv erfolgreich erstellt." >&2
echo "$archive_path"
