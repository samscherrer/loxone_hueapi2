#!/bin/bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_PATH="${1:-$ROOT_DIR/config/config.json}"
export HUE_PLUGIN_CONFIG="$CONFIG_PATH"

exec uvicorn hue_plugin.server:app --host 0.0.0.0 --port 5510
