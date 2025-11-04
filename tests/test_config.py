from pathlib import Path
import json

import pytest

from hue_plugin.config import ConfigError, HueConfig, load_config


def test_load_config_success(tmp_path: Path) -> None:
    config_path = tmp_path / "config.json"
    config_path.write_text(
        json.dumps(
            {
                "bridge_ip": "192.168.1.2",
                "application_key": "abc",
                "client_key": "client",
                "use_https": False,
                "verify_tls": True,
            }
        )
    )

    config = load_config(config_path)

    assert isinstance(config, HueConfig)
    assert config.bridge_ip == "192.168.1.2"
    assert config.application_key == "abc"
    assert config.client_key == "client"
    assert config.use_https is False
    assert config.verify_tls is True
    assert config.base_url == "http://192.168.1.2/clip/v2/resource"


def test_load_config_missing_file(tmp_path: Path) -> None:
    with pytest.raises(ConfigError):
        load_config(tmp_path / "missing.json")


def test_load_config_missing_required_value(tmp_path: Path) -> None:
    config_path = tmp_path / "config.json"
    config_path.write_text(json.dumps({"bridge_ip": "192.168.1.2"}))

    with pytest.raises(ConfigError):
        load_config(config_path)


def test_load_config_env_override(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    config_path = tmp_path / "config.json"
    config_path.write_text(
        json.dumps({"bridge_ip": "10.0.0.2", "application_key": "key"})
    )

    monkeypatch.setenv("HUE_PLUGIN_CONFIG", str(config_path))

    try:
        config = load_config()
    finally:
        monkeypatch.delenv("HUE_PLUGIN_CONFIG", raising=False)

    assert config.bridge_ip == "10.0.0.2"
    assert config.application_key == "key"
