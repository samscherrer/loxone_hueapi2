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
class LoxoneSettings:
    """Settings describing how the plugin reaches the Loxone Miniserver."""

    base_url: Optional[str] = None
    command_method: str = "POST"
    event_method: str = "POST"
    command_scope: str = "public"
    command_auth_user: Optional[str] = None
    command_auth_password: Optional[str] = None

    def to_dict(self) -> Dict[str, Any]:
        payload: Dict[str, Any] = {
            "command_method": self.command_method,
            "event_method": self.event_method,
            "command_scope": self.command_scope,
        }
        if self.base_url:
            payload["base_url"] = self.base_url
        if self.command_auth_user:
            payload["command_auth_user"] = self.command_auth_user
        if self.command_auth_password:
            payload["command_auth_password"] = self.command_auth_password
        return payload


@dataclass
class VirtualInputConfig:
    """Mapping from Hue sensor events to Loxone virtual inputs."""

    id: str
    bridge_id: str
    resource_id: str
    resource_type: str
    virtual_input: str
    name: Optional[str] = None
    trigger: Optional[str] = None
    active_value: str = "1"
    inactive_value: Optional[str] = None
    reset_value: Optional[str] = None
    reset_delay_ms: int = 250

    def to_dict(self) -> Dict[str, Any]:
        payload: Dict[str, Any] = {
            "id": self.id,
            "bridge_id": self.bridge_id,
            "resource_id": self.resource_id,
            "resource_type": self.resource_type,
            "virtual_input": self.virtual_input,
            "active_value": self.active_value,
            "reset_delay_ms": self.reset_delay_ms,
        }
        if self.name:
            payload["name"] = self.name
        if self.trigger:
            payload["trigger"] = self.trigger
        if self.inactive_value is not None:
            payload["inactive_value"] = self.inactive_value
        if self.reset_value is not None:
            payload["reset_value"] = self.reset_value
        return payload


@dataclass
class PluginConfig:
    """Container for all Hue bridge configurations."""

    bridges: List[HueBridgeConfig] = field(default_factory=list)
    loxone: LoxoneSettings = field(default_factory=LoxoneSettings)
    virtual_inputs: List[VirtualInputConfig] = field(default_factory=list)

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
        return {
            "bridges": [bridge.to_dict() for bridge in self.bridges],
            "loxone": self.loxone.to_dict(),
            "virtual_inputs": [entry.to_dict() for entry in self.virtual_inputs],
        }


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


def ensure_virtual_input_id(
    name: Optional[str],
    *,
    existing_ids: Iterable[str],
    bridge_id: str,
    resource_id: str,
) -> str:
    """Create a stable identifier for a virtual input mapping."""

    base = _slugify(name)
    if not base:
        base = _slugify(f"{bridge_id}-{resource_id[:8]}")
    if not base:
        base = "input"

    existing = set(existing_ids)
    candidate = base
    counter = 1
    while candidate in existing:
        candidate = f"{base}-{counter}"
        counter += 1
    return candidate


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
        loxone = _parse_loxone_settings(payload.get("loxone", {}))
        virtual_inputs = _parse_virtual_inputs(payload.get("virtual_inputs"), bridges)
        return PluginConfig(bridges, loxone=loxone, virtual_inputs=virtual_inputs)

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
    loxone = _parse_loxone_settings(payload.get("loxone", {}))
    virtual_inputs = _parse_virtual_inputs(payload.get("virtual_inputs"), [bridge])
    return PluginConfig([bridge], loxone=loxone, virtual_inputs=virtual_inputs)


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


def _parse_loxone_settings(payload: Any) -> LoxoneSettings:
    if not isinstance(payload, dict):
        return LoxoneSettings()

    base_url = payload.get("base_url")
    if isinstance(base_url, str):
        base_url = base_url.strip() or None
    else:
        base_url = None

    def _normalize_method(value: Any, default: str) -> str:
        if not isinstance(value, str):
            return default
        candidate = value.strip().upper()
        return candidate if candidate in {"GET", "POST"} else default

    command_method = _normalize_method(payload.get("command_method"), "POST")
    event_method = _normalize_method(payload.get("event_method"), command_method)
    scope_value = payload.get("command_scope")
    if isinstance(scope_value, str):
        scope_value = scope_value.strip().lower()
    else:
        scope_value = "public"
    if scope_value not in {"public", "admin"}:
        scope_value = "public"

    auth_user = payload.get("command_auth_user")
    if isinstance(auth_user, str):
        auth_user = auth_user.strip() or None
    else:
        auth_user = None

    auth_password = payload.get("command_auth_password")
    if isinstance(auth_password, str):
        auth_password = auth_password if auth_password != "" else None
    else:
        auth_password = None

    return LoxoneSettings(
        base_url=base_url,
        command_method=command_method,
        event_method=event_method,
        command_scope=scope_value,
        command_auth_user=auth_user,
        command_auth_password=auth_password,
    )


def _parse_virtual_inputs(
    payload: Any,
    bridges: Iterable[HueBridgeConfig],
) -> List[VirtualInputConfig]:
    if not isinstance(payload, list):
        return []

    bridge_ids = {bridge.id for bridge in bridges}
    entries: List[VirtualInputConfig] = []
    existing_ids: set[str] = set()

    for index, item in enumerate(payload):
        if not isinstance(item, dict):
            continue
        try:
            bridge_id = item["bridge_id"]
            resource_id = item["resource_id"]
            resource_type = item["resource_type"]
            virtual_input = item["virtual_input"]
        except KeyError as exc:
            raise ConfigError(
                f"Virtual-Input-Eintrag {index + 1} ist unvollständig: fehlender Schlüssel '{exc.args[0]}'"
            ) from exc

        if bridge_id not in bridge_ids:
            raise ConfigError(
                f"Virtual-Input-Eintrag {index + 1} verweist auf unbekannte Bridge '{bridge_id}'."
            )

        identifier = item.get("id")
        if not identifier:
            identifier = ensure_virtual_input_id(
                item.get("name"),
                existing_ids=existing_ids,
                bridge_id=bridge_id,
                resource_id=str(resource_id),
            )

        mapping = VirtualInputConfig(
            id=str(identifier),
            bridge_id=str(bridge_id),
            resource_id=str(resource_id),
            resource_type=str(resource_type),
            virtual_input=str(virtual_input),
            name=item.get("name"),
            trigger=item.get("trigger"),
            active_value=str(item.get("active_value", "1")),
            inactive_value=(
                str(item["inactive_value"])
                if item.get("inactive_value") is not None
                else None
            ),
            reset_value=(
                str(item["reset_value"])
                if item.get("reset_value") is not None
                else None
            ),
            reset_delay_ms=int(item.get("reset_delay_ms", 250) or 0),
        )

        existing_ids.add(mapping.id)
        entries.append(mapping)

    return entries


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
    "LoxoneSettings",
    "VirtualInputConfig",
    "PluginConfig",
    "ConfigError",
    "load_config",
    "save_config",
    "ensure_bridge_id",
    "ensure_virtual_input_id",
]
