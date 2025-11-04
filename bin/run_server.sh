#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_PATH="${1:-$ROOT_DIR/config/config.json}"
export HUE_PLUGIN_CONFIG="$CONFIG_PATH"

if [[ -d "$ROOT_DIR/venv" && -x "$ROOT_DIR/venv/bin/python" ]]; then
  PYTHON_BIN="$ROOT_DIR/venv/bin/python"
elif command -v python3 >/dev/null 2>&1; then
  PYTHON_BIN="$(command -v python3)"
else
  echo "Weder ein virtuelles Environment noch python3 verfügbar." >&2
  exit 1
fi

exec "$PYTHON_BIN" -m uvicorn hue_plugin.server:app --host 0.0.0.0 --port 5510
