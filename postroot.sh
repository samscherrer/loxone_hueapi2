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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SOURCE_ROOT="$SCRIPT_DIR"

# Capture placeholder strings without triggering the LoxBerry installer
# replacement so that we can detect whether values were substituted.
PLACEHOLDER_LBH="REPLACE"'LBHOMEDIR'
PLACEHOLDER_LBP="REPLACE"'LBPPLUGINDIR'

# Ensure LoxBerry placeholders are expanded even if the environment variable
# is not exported before running the script.
LBHOMEDIR="${LBHOMEDIR:-REPLACELBHOMEDIR}"
LBPPLUGINDIR="${LBPPLUGINDIR:-REPLACELBPPLUGINDIR}"

resolve_plugin_root() {
  local candidate=""

  if [[ -n "${LBPPLUGINDIR:-}" && "${LBPPLUGINDIR}" != "$PLACEHOLDER_LBP" ]]; then
    candidate="${LBPPLUGINDIR}"
  elif [[ -n "${LBHOMEDIR:-}" && "${LBHOMEDIR}" != "$PLACEHOLDER_LBH" && -n "$PDIR" ]]; then
    candidate="$LBHOMEDIR/data/plugins/$PDIR"
  fi

  if [[ -z "$candidate" ]]; then
    candidate="$(cd "$SCRIPT_DIR/.." && pwd)"
  fi

  printf '%s' "$candidate"
}

PLUGIN_ROOT="$(resolve_plugin_root)"

if [[ -z "$PLUGIN_ROOT" ]]; then
  log "ERROR" "Konnte das Plugin-Verzeichnis nicht bestimmen (PDIR=$PDIR)."
  exit 1
fi

mkdir -p "$PLUGIN_ROOT"
log "INFO" "Verwende Plugin-Verzeichnis: $PLUGIN_ROOT"

copy_into_plugin_root() {
  local src="$1"
  local dest="$PLUGIN_ROOT"
  local base

  if [[ ! -e "$src" ]]; then
    return
  fi

  base="$(basename "$src")"

  if [[ -d "$src" ]]; then
    rm -rf "$dest/$base"
    cp -a "$src" "$dest/"
  else
    cp -a "$src" "$dest/$base"
  fi
}

if [[ "$SOURCE_ROOT" != "$PLUGIN_ROOT" ]]; then
  log "INFO" "Synchronisiere Plugindateien aus $SOURCE_ROOT"
  copy_into_plugin_root "$SOURCE_ROOT/hue_plugin"
  copy_into_plugin_root "$SOURCE_ROOT/requirements.txt"
  copy_into_plugin_root "$SOURCE_ROOT/pyproject.toml"
fi

if [[ ! -f "$PLUGIN_ROOT/requirements.txt" ]]; then
  log "ERROR" "requirements.txt wurde im Plugin-Verzeichnis nicht gefunden ($PLUGIN_ROOT)."
  exit 1
fi

REQ_FILE="$PLUGIN_ROOT/requirements.txt"
VENV_DIR="$PLUGIN_ROOT/venv"

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
