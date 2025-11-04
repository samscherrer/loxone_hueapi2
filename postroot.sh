#!/bin/bash
set -euo pipefail

log() {
  local level="$1"
  shift
  printf '<%s> %s\n' "$level" "$*"
}

COMMAND=${0:-}
PTEMPDIR=${1:-}
PSHNAME=${2:-}
PDIR=${3:-}
PVERSION=${4:-}
PTEMPPATH=${6:-}

PLUGIN_ROOT=${LBPPLUGINDIR:-REPLACELBPPLUGINDIR}
if [[ -z "$PLUGIN_ROOT" || "$PLUGIN_ROOT" == "REPLACELBPPLUGINDIR" ]]; then
  candidates=()
  if [[ -n "${LBHOMEDIR:-}" && -n "$PDIR" ]]; then
    candidates+=("$LBHOMEDIR/data/plugins/$PDIR")
    candidates+=("$LBHOMEDIR/system/plugins/$PDIR")
  fi
  if [[ -n "${LBPDATA:-}" && -n "$PDIR" ]]; then
    candidates+=("$LBPDATA/$PDIR")
  fi
  if [[ -n "${LBPBIN:-}" && -n "$PDIR" ]]; then
    candidates+=("$LBPBIN/$PDIR")
  fi
  if [[ -n "${LBPHTML:-}" && -n "$PDIR" ]]; then
    candidates+=("$LBPHTML/$PDIR")
  fi
  # Fallback to script location when testing outside of LoxBerry
  candidates+=("$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)")

  for path in "${candidates[@]}"; do
    if [[ -d "$path" && -f "$path/requirements.txt" ]]; then
      PLUGIN_ROOT="$path"
      break
    fi
  done
fi

if [[ -z "$PLUGIN_ROOT" || ! -d "$PLUGIN_ROOT" ]]; then
  log "ERROR" "Konnte das Plugin-Verzeichnis nicht bestimmen (PDIR=$PDIR)."
  exit 1
fi

REQ_FILE="$PLUGIN_ROOT/requirements.txt"
VENV_DIR="$PLUGIN_ROOT/venv"

if [[ ! -f "$REQ_FILE" ]]; then
  log "ERROR" "requirements.txt wurde im Plugin-Verzeichnis nicht gefunden ($REQ_FILE)."
  exit 1
fi

PYTHON_BIN=""
if command -v python3 >/dev/null 2>&1; then
  PYTHON_BIN="$(command -v python3)"
elif command -v python >/dev/null 2>&1; then
  PYTHON_BIN="$(command -v python)"
else
  log "ERROR" "Python 3 ist nicht installiert."
  exit 1
fi

log "INFO" "Erstelle virtuelles Environment unter $VENV_DIR"
rm -rf "$VENV_DIR"
"$PYTHON_BIN" -m venv "$VENV_DIR"
source "$VENV_DIR/bin/activate"

PIP_BIN="$VENV_DIR/bin/pip"
if ! "$PIP_BIN" install --upgrade pip >/tmp/hueapiv2_pip_upgrade.log 2>&1; then
  log "WARN" "pip konnte nicht aktualisiert werden. Details siehe /tmp/hueapiv2_pip_upgrade.log"
fi

if ! "$PIP_BIN" install -r "$REQ_FILE" >/tmp/hueapiv2_requirements.log 2>&1; then
  log "ERROR" "Python-Abhängigkeiten konnten nicht installiert werden. Details siehe /tmp/hueapiv2_requirements.log"
  cat /tmp/hueapiv2_requirements.log >&2
  exit 1
fi

if ! "$PIP_BIN" install --no-deps "$PLUGIN_ROOT" >/tmp/hueapiv2_package.log 2>&1; then
  log "ERROR" "Das Plugin-Paket konnte nicht installiert werden. Details siehe /tmp/hueapiv2_package.log"
  cat /tmp/hueapiv2_package.log >&2
  exit 1
fi

log "INFO" "Post-Installationsschritte erfolgreich abgeschlossen."
