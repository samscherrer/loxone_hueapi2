#!/bin/bash
set -euo pipefail

PLUGIN_BIN_DIR="REPLACELBPBINDIR"
PLUGIN_CONFIG_DIR="REPLACELBPCONFIGDIR"
PLUGIN_ROOT="REPLACELBPPLUGINDIR"

# Fallbacks for local development outside of LoxBerry
if [[ "$PLUGIN_BIN_DIR" == "REPLACELBPBINDIR" ]]; then
  PLUGIN_BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fi
if [[ "$PLUGIN_ROOT" == "REPLACELBPPLUGINDIR" ]]; then
  PLUGIN_ROOT="$(cd "$PLUGIN_BIN_DIR/.." && pwd)"
fi
if [[ "$PLUGIN_CONFIG_DIR" == "REPLACELBPCONFIGDIR" ]]; then
  if [[ -d "$PLUGIN_ROOT/config" ]]; then
    PLUGIN_CONFIG_DIR="$PLUGIN_ROOT/config"
  else
    PLUGIN_CONFIG_DIR="$PLUGIN_ROOT"
  fi
fi

CONFIG_PATH="${1:-$PLUGIN_CONFIG_DIR/config.json}"
export HUE_PLUGIN_CONFIG="$CONFIG_PATH"

VENV_PY="$PLUGIN_ROOT/venv/bin/python"
if [[ -x "$VENV_PY" ]]; then
  PYTHON_BIN="$VENV_PY"
elif command -v python3 >/dev/null 2>&1; then
  PYTHON_BIN="$(command -v python3)"
else
  echo "Weder ein virtuelles Environment noch python3 verfügbar." >&2
  exit 1
fi

export PYTHONPATH="$PLUGIN_ROOT:${PYTHONPATH:-}"

cleanup() {
  if [[ -n "${FORWARDER_PID:-}" ]]; then
    kill "$FORWARDER_PID" >/dev/null 2>&1 || true
    wait "$FORWARDER_PID" 2>/dev/null || true
  fi
}

trap cleanup EXIT INT TERM

"$PYTHON_BIN" -m hue_plugin.event_forwarder >/dev/null 2>&1 &
FORWARDER_PID=$!

"$PYTHON_BIN" -m uvicorn hue_plugin.server:app --host 0.0.0.0 --port 5510 "$@"
status=$?
cleanup
exit $status
