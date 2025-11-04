#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="$ROOT_DIR/dist"
VERSION=$(ROOT_DIR="$ROOT_DIR" python3 - <<'PY'
from pathlib import Path
import os
import re

root = Path(os.environ["ROOT_DIR"])
content = (root / "pyproject.toml").read_text(encoding='utf-8')
match = re.search(r"^version\s*=\s*\"([^\"]+)\"", content, re.MULTILINE)
if not match:
    raise SystemExit("Konnte Version nicht in pyproject.toml finden")

print(match.group(1))
PY
)
ZIP_NAME="loxberry-hueapi2-${VERSION}.zip"
TMP_DIR=$(mktemp -d)

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

rsync -a --exclude '__pycache__' --exclude '.pytest_cache' --exclude '.mypy_cache' \
  --exclude '.git' --exclude 'dist' --exclude '*.pyc' --exclude '.venv' \
  --exclude 'loxone_hueapi2.egg-info' --exclude 'build_plugin_zip.sh' \
  "$ROOT_DIR/" "$TMP_DIR/hueapiv2/"

mkdir -p "$DIST_DIR"
(cd "$TMP_DIR" && zip -r "$DIST_DIR/$ZIP_NAME" "hueapiv2")

echo "Erstellt: $DIST_DIR/$ZIP_NAME"
