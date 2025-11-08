import json
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


def test_cli_list_lights_includes_relationships(monkeypatch, tmp_path, capsys):
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

        def get_rooms(self):
            return [
                HueResource(
                    id="room-1",
                    type="room",
                    metadata={"name": "Wohnzimmer"},
                    data={"services": [{"rid": "light-1", "rtype": "light"}]},
                )
            ]

        def get_scenes(self):
            return [
                HueResource(
                    id="scene-1",
                    type="scene",
                    metadata={"name": "Relax"},
                    data={
                        "actions": [
                            {"target": {"rid": "light-1", "rtype": "light"}},
                        ]
                    },
                )
            ]

        def get_zones(self):  # pragma: no cover - not used in this test
            return []

        def get_buttons(self):  # pragma: no cover - not used in this test
            return []

        def get_motion_sensors(self):  # pragma: no cover - not used in this test
            return []

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
    item = payload["items"][0]
    assert item["id"] == "light-1"
    assert item["name"] == "Test Light"
    assert item["rooms"] == [{"id": "room-1", "name": "Wohnzimmer"}]
    assert item["scenes"] == [{"id": "scene-1", "name": "Relax"}]


def test_cli_list_scenes_includes_group(monkeypatch, tmp_path, capsys):
    config_path = write_config(tmp_path)

    class DummyClient:
        def __init__(self, config):
            assert config.id == "bridge-1"

        def get_scenes(self):
            return [
                HueResource(
                    id="scene-1",
                    type="scene",
                    metadata={"name": "Sunset"},
                    data={"group": {"rid": "room-1", "rtype": "room"}},
                )
            ]

        def get_rooms(self):
            return [
                HueResource(
                    id="room-1",
                    type="room",
                    metadata={"name": "Wohnzimmer"},
                    data={"services": []},
                )
            ]

        def get_zones(self):
            return []

        def get_lights(self):  # pragma: no cover - not expected here
            raise AssertionError

        def get_buttons(self):  # pragma: no cover - not used in this test
            return []

        def get_motion_sensors(self):  # pragma: no cover - not used in this test
            return []

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    exit_code = cli.main([
        "--config",
        str(config_path),
        "list-resources",
        "--type",
        "scenes",
        "--bridge-id",
        "bridge-1",
    ])

    assert exit_code == 0
    payload = json.loads(capsys.readouterr().out)
    item = payload["items"][0]
    assert item["group"] == {
        "rid": "room-1",
        "rtype": "room",
        "name": "Wohnzimmer",
    }


def test_cli_list_buttons_includes_device(monkeypatch, tmp_path, capsys):
    config_path = write_config(tmp_path)

    class DummyClient:
        def __init__(self, config):
            assert config.id == "bridge-1"

        def get_buttons(self):
            return [
                HueResource(
                    id="button-1",
                    type="button",
                    metadata={"name": "Taste"},
                    data={"owner": {"rid": "device-1"}},
                )
            ]

        def get_devices(self):
            return [
                HueResource(
                    id="device-1",
                    type="device",
                    metadata={"name": "Schalter"},
                    data={},
                )
            ]

        def get_lights(self):  # pragma: no cover - not used in this test
            return []

        def get_rooms(self):  # pragma: no cover - not used in this test
            return []

        def get_scenes(self):  # pragma: no cover - not used in this test
            return []

        def get_zones(self):  # pragma: no cover - not used in this test
            return []

        def get_motion_sensors(self):  # pragma: no cover - not used in this test
            return []

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    exit_code = cli.main([
        "--config",
        str(config_path),
        "list-resources",
        "--type",
        "buttons",
        "--bridge-id",
        "bridge-1",
    ])

    assert exit_code == 0
    payload = json.loads(capsys.readouterr().out)
    item = payload["items"][0]
    assert item["device"] == {"name": "Schalter", "id": "device-1"}


def test_cli_list_motions_reports_state(monkeypatch, tmp_path, capsys):
    config_path = write_config(tmp_path)

    class DummyClient:
        def __init__(self, config):
            assert config.id == "bridge-1"

        def get_motion_sensors(self):
            return [
                HueResource(
                    id="motion-1",
                    type="motion",
                    metadata={"name": "Bewegung"},
                    data={
                        "motion": {
                            "motion_report": {
                                "motion": {"motion": True, "motion_valid": True}
                            }
                        }
                    },
                )
            ]

        def get_lights(self):  # pragma: no cover - not used in this test
            return []

        def get_rooms(self):  # pragma: no cover - not used in this test
            return []

        def get_scenes(self):  # pragma: no cover - not used in this test
            return []

        def get_zones(self):  # pragma: no cover - not used in this test
            return []

        def get_buttons(self):  # pragma: no cover - not used in this test
            return []

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    exit_code = cli.main([
        "--config",
        str(config_path),
        "list-resources",
        "--type",
        "motions",
        "--bridge-id",
        "bridge-1",
    ])

    assert exit_code == 0
    payload = json.loads(capsys.readouterr().out)
    item = payload["items"][0]
    assert item["state"] is True


def test_cli_light_command(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)
    calls = []

    class DummyClient:
        def __init__(self, config):
            pass

        def set_light_state(
            self,
            light_id,
            *,
            on=None,
            brightness=None,
            color_xy=None,
            temperature_mirek=None,
            transition_ms=None,
        ):
            calls.append((light_id, on, brightness, color_xy, temperature_mirek, transition_ms))

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
    assert calls == [("lamp-1", True, 80, None, None, None)]


def test_cli_light_command_with_color(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)
    calls = []

    class DummyClient:
        def __init__(self, config):
            pass

        def set_light_state(
            self,
            light_id,
            *,
            on=None,
            brightness=None,
            color_xy=None,
            temperature_mirek=None,
            transition_ms=None,
        ):
            calls.append((light_id, on, brightness, color_xy, temperature_mirek, transition_ms))

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    args = Namespace(
        config=str(config_path),
        bridge_id="bridge-1",
        light_id="lamp-1",
        state=True,
        brightness=None,
        rgb="#ff0000",
        temperature=2700,
        mirek=None,
        transition=150,
    )

    result = cli.command_light_command(args)
    assert result == {"ok": True}
    assert len(calls) == 1
    _, on_value, brightness_value, color_xy, temperature_mirek, transition_ms = calls[0]
    assert on_value is True
    assert brightness_value is None
    assert transition_ms == 150
    assert temperature_mirek == pytest.approx(int(round(1_000_000 / 2700)))
    assert color_xy == pytest.approx(cli._rgb_to_xy((255, 0, 0)))


def test_cli_scene_command(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)
    calls = []

    class DummyClient:
        def __init__(self, config):
            pass

        def activate_scene(
            self,
            scene_id,
            *,
            target_rid=None,
            target_rtype=None,
            dynamics_duration=None,
        ):
            calls.append((scene_id, target_rid, target_rtype, dynamics_duration))

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    args = Namespace(
        config=str(config_path),
        bridge_id="bridge-1",
        scene_id="scene-1",
        target_rid="group-1",
        target_rtype="room",
        state=None,
    )

    result = cli.command_scene_command(args)
    assert result == {"ok": True}
    assert calls == [("scene-1", "group-1", "room", None)]


def test_cli_scene_command_with_transition(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)
    calls = []

    class DummyClient:
        def __init__(self, config):
            pass

        def activate_scene(
            self,
            scene_id,
            *,
            target_rid=None,
            target_rtype=None,
            dynamics_duration=None,
        ):
            calls.append((scene_id, dynamics_duration))

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    args = Namespace(
        config=str(config_path),
        bridge_id="bridge-1",
        scene_id="scene-1",
        target_rid=None,
        target_rtype=None,
        state=True,
        transition=200,
    )

    result = cli.command_scene_command(args)
    assert result == {"ok": True}
    assert calls == [("scene-1", 200)]


def test_cli_scene_command_off(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)
    calls = []

    class DummyClient:
        def __init__(self, config):
            pass

        def deactivate_scene(self, scene_id, *, target_rid=None, target_rtype=None):
            calls.append((scene_id, target_rid, target_rtype))

    monkeypatch.setattr(cli, "HueBridgeClient", DummyClient)

    args = Namespace(
        config=str(config_path),
        bridge_id="bridge-1",
        scene_id="scene-1",
        target_rid=None,
        target_rtype=None,
        state=False,
    )

    result = cli.command_scene_command(args)
    assert result == {"ok": True}
    assert calls == [("scene-1", None, None)]


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


def _write_virtual_input_config(path, *, include_inactive=True):
    data = json.loads(path.read_text())
    data["loxone"] = {
        "base_url": "http://loxone",  # pragma: no cover - structure only
        "command_method": "POST",
        "event_method": "POST",
        "command_scope": "public",
    }
    entry = {
        "id": "motion-input",
        "bridge_id": "bridge-1",
        "resource_id": "motion-1",
        "resource_type": "motion",
        "virtual_input": "VI.Motion",
        "active_value": "1",
    }
    if include_inactive:
        entry["inactive_value"] = "0"
    data["virtual_inputs"] = [entry]
    path.write_text(json.dumps(data))


def test_cli_forward_virtual_input_active(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)
    _write_virtual_input_config(config_path)

    calls = []

    class DummySender:
        def __init__(self, settings):
            assert settings.base_url == "http://loxone"

        def send(self, virtual_input, value):
            calls.append((virtual_input, value))

    monkeypatch.setattr(cli, "LoxoneSender", lambda settings: DummySender(settings))

    args = Namespace(
        config=str(config_path),
        virtual_input_id="motion-input",
        state="active",
        value=None,
    )

    result = cli.command_forward_virtual_input(args)

    assert result == {"ok": True}
    assert calls == [("VI.Motion", "1")]


def test_cli_forward_virtual_input_inactive_missing(monkeypatch, tmp_path):
    config_path = write_config(tmp_path)
    _write_virtual_input_config(config_path, include_inactive=False)

    class DummySender:
        def __init__(self, settings):
            pass

        def send(self, virtual_input, value):  # pragma: no cover - should not be called
            raise AssertionError("send should not be invoked")

    monkeypatch.setattr(cli, "LoxoneSender", lambda settings: DummySender(settings))

    args = Namespace(
        config=str(config_path),
        virtual_input_id="motion-input",
        state="inactive",
        value=None,
    )

    with pytest.raises(SystemExit) as exc:
        cli.command_forward_virtual_input(args)

    assert "kein Inaktiv-Wert" in str(exc.value)


def test_cli_virtual_input_events(tmp_path, capsys):
    config_path = write_config(tmp_path)
    state_file = config_path.parent / "runtime_state.json"
    payload = {
        "events": [
            {
                "event_id": 1,
                "timestamp": "2023-01-01T00:00:00Z",
                "mapping_id": "vi-1",
                "state": "active",
                "value": "1",
            },
            {
                "event_id": 2,
                "timestamp": "2023-01-01T00:01:00Z",
                "mapping_id": "vi-1",
                "state": "inactive",
                "value": "0",
            },
        ],
        "states": {
            "vi-1": {
                "state": "inactive",
                "timestamp": "2023-01-01T00:01:00Z",
                "value": "0",
            }
        },
    }
    state_file.write_text(json.dumps(payload))

    exit_code = cli.main(
        [
            "--config",
            str(config_path),
            "virtual-input-events",
            "--limit",
            "1",
        ]
    )

    assert exit_code == 0
    output = json.loads(capsys.readouterr().out)
    assert output["states"]["vi-1"]["state"] == "inactive"
    assert len(output["events"]) == 1
    assert output["events"][0]["state"] == "inactive"
    metadata = output.get("metadata")
    assert metadata["has_more"] is True
    assert metadata["next_before"] == 2
    assert metadata["latest_event_id"] == 2


def test_cli_virtual_input_events_filters(tmp_path):
    config_path = write_config(tmp_path)
    state_file = config_path.parent / "runtime_state.json"
    payload = {
        "events": [
            {"event_id": 1, "timestamp": "2023-01-01T00:00:00Z", "state": "inactive"},
            {"event_id": 2, "timestamp": "2023-02-01T00:00:00Z", "state": "active"},
            {"event_id": 3, "timestamp": "2023-02-02T00:00:00Z", "state": "reset"},
        ],
        "states": {},
    }
    state_file.write_text(json.dumps(payload))

    args = Namespace(config=str(config_path), limit=5, date="2023-02-01", before=None, after=None)
    result = cli.command_virtual_input_events(args)
    assert [event["event_id"] for event in result["events"]] == [2]

    args_before = Namespace(config=str(config_path), limit=5, date=None, before=3, after=None)
    result_before = cli.command_virtual_input_events(args_before)
    assert all(event["event_id"] < 3 for event in result_before["events"])


def test_cli_clear_virtual_events(tmp_path):
    config_path = write_config(tmp_path)
    state_file = config_path.parent / "runtime_state.json"
    state_file.write_text(json.dumps({"events": [{"event_id": 1}], "states": {"foo": {"state": "active"}}}))

    result = cli.command_clear_virtual_events(Namespace(config=str(config_path)))
    assert result == {"ok": True}

    cleared = json.loads(state_file.read_text())
    assert cleared == {"events": [], "states": {}}
