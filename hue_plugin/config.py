"""Configuration handling for the LoxBerry Hue API v2 plugin."""
from __future__ import annotations

from dataclasses import dataclass
import json
import os
from pathlib import Path
from typing import Any, Dict

_DEFAULT_CONFIG_PATH = Path("config/config.json")
ENV_CONFIG_PATH = "HUE_PLUGIN_CONFIG"


class ConfigError(RuntimeError):
    """Raised when the configuration cannot be loaded."""


@dataclass
class HueConfig:
    """Runtime configuration for the Hue bridge connection."""

    bridge_ip: str
    application_key: str
    client_key: str | None = None
    use_https: bool = True
    verify_tls: bool = False

    @property
    def base_url(self) -> str:
        protocol = "https" if self.use_https else "http"
        return f"{protocol}://{self.bridge_ip}/clip/v2/resource"


def _load_json(path: Path) -> Dict[str, Any]:
    if not path.exists():
        raise ConfigError(f"Configuration file '{path}' does not exist.")

    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ConfigError(f"Invalid JSON in configuration file '{path}': {exc}") from exc


def load_config(path: str | Path | None = None) -> HueConfig:
    """Load the configuration from disk.

    The path can be provided directly, read from the ``HUE_PLUGIN_CONFIG`` environment
    variable, or default to ``config/config.json``.
    """

    resolved_path = _resolve_config_path(path)
    data = _load_json(resolved_path)

    try:
        bridge_ip = data["bridge_ip"]
        application_key = data["application_key"]
    except KeyError as exc:
        raise ConfigError(f"Missing required configuration value: {exc.args[0]}") from exc

    return HueConfig(
        bridge_ip=bridge_ip,
        application_key=application_key,
        client_key=data.get("client_key"),
        use_https=data.get("use_https", True),
        verify_tls=data.get("verify_tls", False),
    )


def _resolve_config_path(path: str | Path | None) -> Path:
    if path is not None:
        return Path(path)

    env_path = os.environ.get(ENV_CONFIG_PATH)
    if env_path:
        return Path(env_path)

    return _DEFAULT_CONFIG_PATH


__all__ = ["HueConfig", "ConfigError", "load_config"]
