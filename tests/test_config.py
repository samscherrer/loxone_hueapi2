from pathlib import Path
import json

import pytest

from hue_plugin.config import (
    ConfigError,
    HueBridgeConfig,
    LoxoneSettings,
    PluginConfig,
    VirtualInputConfig,
    ensure_bridge_id,
    ensure_virtual_input_id,
    load_config,
    save_config,
)


def _write(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload))


def test_load_config_success(tmp_path: Path) -> None:
    config_path = tmp_path / "config.json"
    _write(
        config_path,
        {
            "bridges": [
                {
                    "id": "wohnzimmer",
                    "bridge_ip": "192.168.1.2",
                    "application_key": "abc",
                    "client_key": "client",
                    "use_https": False,
                    "verify_tls": True,
                }
            ],
            "loxone": {
                "base_url": "http://loxone:pass@192.168.1.10",
                "command_method": "POST",
                "event_method": "GET",
            },
            "virtual_inputs": [
                {
                    "id": "wohnzimmer-switch",
                    "name": "Wohnzimmer-Taster",
                    "bridge_id": "wohnzimmer",
                    "resource_id": "rid-1",
                    "resource_type": "button",
                    "virtual_input": "VirtSwitch",
                    "trigger": "short_press",
                    "active_value": "1",
                    "inactive_value": "0",
                    "reset_value": "0",
                    "reset_delay_ms": 200,
                }
            ],
        },
    )

    config = load_config(config_path)

    assert isinstance(config, PluginConfig)
    assert config.default_bridge.id == "wohnzimmer"
    assert config.default_bridge.bridge_ip == "192.168.1.2"
    assert config.default_bridge.application_key == "abc"
    assert config.default_bridge.client_key == "client"
    assert config.default_bridge.use_https is False
    assert config.default_bridge.verify_tls is True
    assert (
        config.default_bridge.base_url == "http://192.168.1.2/clip/v2/resource"
    )
    assert isinstance(config.loxone, LoxoneSettings)
    assert config.loxone.base_url == "http://loxone:pass@192.168.1.10"
    assert config.loxone.command_method == "POST"
    assert config.loxone.event_method == "GET"
    assert len(config.virtual_inputs) == 1
    entry = config.virtual_inputs[0]
    assert entry.id == "wohnzimmer-switch"
    assert entry.bridge_id == "wohnzimmer"
    assert entry.resource_id == "rid-1"
    assert entry.resource_type == "button"
    assert entry.virtual_input == "VirtSwitch"
    assert entry.trigger == "short_press"
    assert entry.active_value == "1"
    assert entry.inactive_value == "0"
    assert entry.reset_value == "0"
    assert entry.reset_delay_ms == 200


def test_load_config_missing_file(tmp_path: Path) -> None:
    with pytest.raises(ConfigError):
        load_config(tmp_path / "missing.json")


def test_load_config_missing_required_value(tmp_path: Path) -> None:
    config_path = tmp_path / "config.json"
    _write(config_path, {"bridges": [{"bridge_ip": "192.168.1.2"}]})

    with pytest.raises(ConfigError):
        load_config(config_path)


def test_load_config_env_override(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    config_path = tmp_path / "config.json"
    _write(
        config_path,
        {
            "bridges": [
                {
                    "id": "lb",
                    "bridge_ip": "10.0.0.2",
                    "application_key": "key",
                }
            ]
        },
    )

    monkeypatch.setenv("HUE_PLUGIN_CONFIG", str(config_path))

    try:
        config = load_config()
    finally:
        monkeypatch.delenv("HUE_PLUGIN_CONFIG", raising=False)

    assert config.default_bridge.bridge_ip == "10.0.0.2"
    assert config.default_bridge.application_key == "key"


def test_legacy_single_bridge_structure(tmp_path: Path) -> None:
    config_path = tmp_path / "config.json"
    _write(
        config_path,
        {
            "bridge_ip": "10.0.0.5",
            "application_key": "legacy",
            "use_https": False,
        },
    )

    config = load_config(config_path)
    assert config.default_bridge.id == "default"
    assert config.default_bridge.base_url == "http://10.0.0.5/clip/v2/resource"


def test_save_config_roundtrip(tmp_path: Path) -> None:
    config_path = tmp_path / "config.json"
    plugin_config = PluginConfig(
        bridges=[
            HueBridgeConfig(
                id="test",
                name="Test",
                bridge_ip="1.1.1.1",
                application_key="k",
            )
        ],
        loxone=LoxoneSettings(
            base_url="http://loxone:pass@192.168.1.11",
            command_method="POST",
            event_method="GET",
        ),
        virtual_inputs=[
            VirtualInputConfig(
                id="switch",
                bridge_id="test",
                resource_id="rid",
                resource_type="button",
                virtual_input="VirtInput",
                trigger="short_press",
                active_value="1",
                inactive_value="0",
                reset_value="0",
                reset_delay_ms=300,
            )
        ],
    )

    save_config(plugin_config, config_path)

    loaded = load_config(config_path)
    assert loaded.default_bridge.id == "test"
    assert loaded.default_bridge.name == "Test"
    assert loaded.loxone.base_url == "http://loxone:pass@192.168.1.11"
    assert loaded.loxone.event_method == "GET"
    assert len(loaded.virtual_inputs) == 1
    assert loaded.virtual_inputs[0].virtual_input == "VirtInput"


def test_ensure_bridge_id_generates_unique_values() -> None:
    existing = {"default", "default-1"}
    new_id = ensure_bridge_id("Default", existing_ids=existing)
    assert new_id == "default-2"


def test_ensure_virtual_input_id_generates_unique_values() -> None:
    existing = {"switch", "switch-1"}
    new_id = ensure_virtual_input_id(
        "Switch",
        existing_ids=existing,
        bridge_id="bridge",
        resource_id="abc12345",
    )
    assert new_id == "switch-2"
