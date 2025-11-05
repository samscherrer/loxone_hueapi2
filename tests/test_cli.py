import json
from argparse import Namespace

import pytest

from hue_plugin import cli
from hue_plugin.hue_client import HueResource


def write_config(tmp_path):
    data = {
        "bridges": [
            {
                "id": "bridge-1",
                "name": "Bridge",
                "bridge_ip": "1.2.3.4",
                "application_key": "key",
                "use_https": False,
                "verify_tls": False,
            }
        ]
    }
    path = tmp_path / "config.json"
    path.write_text(json.dumps(data))
    return path


def test_cli_list_resources(monkeypatch, tmp_path, capsys):
    config_path = write_config(tmp_path)

    class DummyClient:
        def __init__(self, config):
            assert config.id == "bridge-1"

        def get_lights(self):
            return [
                HueResource(
                    id="light-1",
                    type="light",
                    metadata={"name": "Test Light"},
                    data={"on": {"on": True}},
                )
            ]

        def get_scenes(self):  # pragma: no cover - not expected here
            raise AssertionError

        def get_rooms(self):  # pragma: no cover - not expected here
            raise AssertionError

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    exit_code = cli.main([
        "--config",
        str(config_path),
        "list-resources",
        "--type",
        "lights",
        "--bridge-id",
        "bridge-1",
    ])

    assert exit_code == 0
    payload = json.loads(capsys.readouterr().out)
    assert payload["items"][0]["id"] == "light-1"
    assert payload["items"][0]["name"] == "Test Light"


def test_cli_light_command(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)
    calls = []

    class DummyClient:
        def __init__(self, config):
            pass

        def set_light_state(self, light_id, *, on=None, brightness=None):
            calls.append((light_id, on, brightness))

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    args = Namespace(
        config=str(config_path),
        bridge_id="bridge-1",
        light_id="lamp-1",
        state=True,
        brightness=80,
    )

    result = cli.command_light_command(args)
    assert result == {"ok": True}
    assert calls == [("lamp-1", True, 80)]


def test_cli_scene_command(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)
    calls = []

    class DummyClient:
        def __init__(self, config):
            pass

        def activate_scene(self, scene_id, *, target_rid=None, target_rtype=None):
            calls.append((scene_id, target_rid, target_rtype))

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    args = Namespace(
        config=str(config_path),
        bridge_id="bridge-1",
        scene_id="scene-1",
        target_rid="group-1",
        target_rtype="room",
    )

    result = cli.command_scene_command(args)
    assert result == {"ok": True}
    assert calls == [("scene-1", "group-1", "room")]


def test_cli_test_connection_failure(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)

    class DummyClient:
        def __init__(self, config):
            pass

        def get_lights(self):
            raise cli.HueBridgeError("boom")

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    with pytest.raises(SystemExit) as exc:
        cli.command_test_connection(Namespace(config=str(config_path), bridge_id="bridge-1"))

    assert "boom" in str(exc.value)
