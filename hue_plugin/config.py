"""Configuration handling for the LoxBerry Hue API v2 plugin."""
from __future__ import annotations

from dataclasses import dataclass, field
import json
import os
from pathlib import Path
import re
from typing import Any, Dict, Iterable, List, Optional

_DEFAULT_CONFIG_PATH = Path("config/config.json")
ENV_CONFIG_PATH = "HUE_PLUGIN_CONFIG"
_DEFAULT_BRIDGE_ID = "default"


class ConfigError(RuntimeError):
    """Raised when the configuration cannot be loaded or saved."""


@dataclass
class HueBridgeConfig:
    """Configuration for a single Hue bridge connection."""

    id: str
    bridge_ip: str
    application_key: str
    name: Optional[str] = None
    client_key: Optional[str] = None
    use_https: bool = True
    verify_tls: bool = False

    @property
    def base_url(self) -> str:
        protocol = "https" if self.use_https else "http"
        return f"{protocol}://{self.bridge_ip}/clip/v2/resource"

    def to_dict(self) -> Dict[str, Any]:
        return {
            "id": self.id,
            "name": self.name,
            "bridge_ip": self.bridge_ip,
            "application_key": self.application_key,
            "client_key": self.client_key,
            "use_https": self.use_https,
            "verify_tls": self.verify_tls,
        }


@dataclass
class PluginConfig:
    """Container for all Hue bridge configurations."""

    bridges: List[HueBridgeConfig] = field(default_factory=list)

    @property
    def default_bridge(self) -> HueBridgeConfig:
        if not self.bridges:
            raise ConfigError("No Hue bridges configured. Bitte lege zunächst eine Bridge an.")
        return self.bridges[0]

    def get_bridge(self, bridge_id: Optional[str]) -> HueBridgeConfig:
        if bridge_id is None:
            return self.default_bridge

        for bridge in self.bridges:
            if bridge.id == bridge_id:
                return bridge
        raise ConfigError(f"Bridge mit der ID '{bridge_id}' wurde nicht gefunden.")

    def to_dict(self) -> Dict[str, Any]:
        return {"bridges": [bridge.to_dict() for bridge in self.bridges]}


def load_config(path: str | Path | None = None) -> PluginConfig:
    """Load the configuration from disk.

    Supports both the current multi-bridge structure as well as the legacy
    single-bridge JSON layout.
    """

    resolved_path = _resolve_config_path(path)
    data = _load_json(resolved_path)
    return _parse_plugin_config(data)


def save_config(config: PluginConfig, path: str | Path | None = None) -> None:
    """Persist the plugin configuration to disk."""

    resolved_path = _resolve_config_path(path)
    resolved_path.parent.mkdir(parents=True, exist_ok=True)

    tmp_path = resolved_path.with_suffix(resolved_path.suffix + ".tmp")
    tmp_path.write_text(
        json.dumps(config.to_dict(), indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    tmp_path.replace(resolved_path)


def ensure_bridge_id(name: Optional[str], *, existing_ids: Iterable[str]) -> str:
    """Generate a stable identifier for a bridge based on its name/IP."""

    base = _slugify(name) if name else None
    if not base:
        base = _DEFAULT_BRIDGE_ID

    if base not in existing_ids:
        return base

    counter = 1
    while f"{base}-{counter}" in existing_ids:
        counter += 1
    return f"{base}-{counter}"


def _parse_plugin_config(payload: Dict[str, Any]) -> PluginConfig:
    if "bridges" in payload and isinstance(payload["bridges"], list):
        bridges: List[HueBridgeConfig] = []
        existing_ids: set[str] = set()
        for index, entry in enumerate(payload["bridges"]):
            bridge = _parse_bridge(entry, index=index, existing_ids=existing_ids)
            existing_ids.add(bridge.id)
            bridges.append(bridge)
        if not bridges:
            raise ConfigError("Die Konfigurationsdatei enthält keine Hue-Bridges.")
        return PluginConfig(bridges)

    # Legacy single-bridge structure
    try:
        bridge_ip = payload["bridge_ip"]
        application_key = payload["application_key"]
    except KeyError as exc:
        raise ConfigError(f"Missing required configuration value: {exc.args[0]}") from exc

    bridge = HueBridgeConfig(
        id=payload.get("id") or _DEFAULT_BRIDGE_ID,
        name=payload.get("name"),
        bridge_ip=bridge_ip,
        application_key=application_key,
        client_key=payload.get("client_key"),
        use_https=payload.get("use_https", True),
        verify_tls=payload.get("verify_tls", False),
    )
    return PluginConfig([bridge])


def _parse_bridge(
    entry: Dict[str, Any], *, index: int, existing_ids: Iterable[str]
) -> HueBridgeConfig:
    try:
        bridge_ip = entry["bridge_ip"]
        application_key = entry["application_key"]
    except KeyError as exc:
        raise ConfigError(
            f"Bridge-Eintrag {index + 1} ist unvollständig: fehlender Schlüssel '{exc.args[0]}'"
        ) from exc

    identifier = entry.get("id")
    if not identifier:
        identifier = ensure_bridge_id(entry.get("name"), existing_ids=existing_ids)

    return HueBridgeConfig(
        id=identifier,
        name=entry.get("name"),
        bridge_ip=bridge_ip,
        application_key=application_key,
        client_key=entry.get("client_key"),
        use_https=entry.get("use_https", True),
        verify_tls=entry.get("verify_tls", False),
    )


def _load_json(path: Path) -> Dict[str, Any]:
    if not path.exists():
        raise ConfigError(f"Configuration file '{path}' does not exist.")

    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ConfigError(f"Invalid JSON in configuration file '{path}': {exc}") from exc


def _resolve_config_path(path: str | Path | None) -> Path:
    if path is not None:
        return Path(path)

    env_path = os.environ.get(ENV_CONFIG_PATH)
    if env_path:
        return Path(env_path)

    return _DEFAULT_CONFIG_PATH


def _slugify(value: Optional[str]) -> Optional[str]:
    if not value:
        return None
    slug = re.sub(r"[^a-zA-Z0-9]+", "-", value.strip().lower()).strip("-")
    return slug or None


__all__ = [
    "HueBridgeConfig",
    "PluginConfig",
    "ConfigError",
    "load_config",
    "save_config",
    "ensure_bridge_id",
]
