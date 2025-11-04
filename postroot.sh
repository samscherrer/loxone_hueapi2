#!/bin/bash
set -euo pipefail

if [[ -z "${LBPPLUGINDIR:-}" ]]; then
  echo "LBPPLUGINDIR ist nicht gesetzt. Dieses Skript muss von LoxBerry ausgeführt werden." >&2
  exit 1
fi

VENV_DIR="$LBPPLUGINDIR/venv"
REQ_FILE="$LBPPLUGINDIR/requirements.txt"

if [[ ! -f "$REQ_FILE" ]]; then
  echo "Keine requirements.txt gefunden ("$REQ_FILE")." >&2
  exit 1
fi

rm -rf "$VENV_DIR"
python3 -m venv "$VENV_DIR"
source "$VENV_DIR/bin/activate"
python -m pip install --upgrade pip
python -m pip install -r "$REQ_FILE"

# Installiere das Plugin-Paket im virtuellen Environment
python -m pip install "$LBPPLUGINDIR"
