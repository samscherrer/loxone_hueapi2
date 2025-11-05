from pathlib import Path
import json

import pytest

from hue_plugin.config import (
    ConfigError,
    HueBridgeConfig,
    PluginConfig,
    ensure_bridge_id,
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
            ]
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
        ]
    )

    save_config(plugin_config, config_path)

    loaded = load_config(config_path)
    assert loaded.default_bridge.id == "test"
    assert loaded.default_bridge.name == "Test"


def test_ensure_bridge_id_generates_unique_values() -> None:
    existing = {"default", "default-1"}
    new_id = ensure_bridge_id("Default", existing_ids=existing)
    assert new_id == "default-2"
