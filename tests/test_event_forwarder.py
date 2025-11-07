import pytest

from hue_plugin.config import LoxoneSettings
import json

import threading

import pytest

from hue_plugin.config import HueBridgeConfig, LoxoneSettings, VirtualInputConfig
from hue_plugin.event_forwarder import (
    EventStateStore,
    LoxoneSender,
    extract_motion_state,
    load_event_state,
)
from hue_plugin.event_forwarder import BridgeWorker
from hue_plugin.hue_client import HueResource


def test_sender_requires_base_url():
    sender = LoxoneSender(LoxoneSettings(base_url=None))
    with pytest.raises(RuntimeError):
        sender.send("VirtInput", "1")


def test_sender_posts(monkeypatch):
    calls = []

    class DummyResponse:
        def raise_for_status(self) -> None:
            return None

    def fake_post(url, timeout):
        calls.append((url, timeout))
        return DummyResponse()

    monkeypatch.setattr("hue_plugin.event_forwarder.requests.post", fake_post)

    sender = LoxoneSender(LoxoneSettings(base_url="http://user:pass@host", event_method="POST"))
    sender.send("Virt Input", "1")

    assert calls == [("http://user:pass@host/dev/sps/io/Virt%20Input/1", 5)]


def test_sender_get(monkeypatch):
    calls = []

    class DummyResponse:
        def raise_for_status(self) -> None:
            return None

    def fake_get(url, timeout):
        calls.append(url)
        return DummyResponse()

    monkeypatch.setattr("hue_plugin.event_forwarder.requests.get", fake_get)

    sender = LoxoneSender(LoxoneSettings(base_url="http://host", event_method="GET"))
    sender.send("Sensor", "0")

    assert calls == ["http://host/dev/sps/io/Sensor/0"]


@pytest.mark.parametrize(
    "payload,expected",
    [
        ({"motion": {"motion_report": {"motion": True}}}, True),
        (
            {
                "motion": {
                    "motion_report": {
                        "motion": {"motion": "true", "motion_valid": True}
                    }
                }
            },
            True,
        ),
        (
            {"motion": {"motion_report": {"motion": {"value": 0}}}},
            False,
        ),
        ({"motion": {"motion": "inactive"}}, False),
        ({"not_motion": {}}, None),
    ],
)
def test_extract_motion_state(payload, expected):
    assert extract_motion_state(payload) is expected


def test_event_state_store_records_and_trims(tmp_path):
    path = tmp_path / "state.json"
    store = EventStateStore(path, max_events=2)
    mapping = VirtualInputConfig(
        id="motion-1",
        bridge_id="bridge-1",
        resource_id="rid-1",
        resource_type="motion",
        virtual_input="VI.Motion",
        active_value="1",
        inactive_value="0",
        reset_value=None,
        reset_delay_ms=0,
    )

    store.record(mapping, event_type="motion", state="active", value="1", extra={"motion_state": True})
    store.record(
        mapping,
        event_type="motion",
        state="inactive",
        value="0",
        delivered=False,
        extra={"motion_state": False},
    )
    store.record(mapping, event_type="motion", state="reset", value="0", trigger="reset")

    payload = json.loads(path.read_text())
    assert len(payload["events"]) == 2
    assert payload["events"][-1]["state"] == "reset"
    assert payload["states"]["motion-1"]["state"] == "reset"


def test_load_event_state_handles_missing(tmp_path):
    missing = tmp_path / "does-not-exist.json"
    assert load_event_state(missing) == {"events": [], "states": {}}


def test_motion_poll_triggers_events(tmp_path):
    config = HueBridgeConfig(
        id="bridge-1",
        bridge_ip="192.0.2.1",
        application_key="abc",
    )
    store = EventStateStore(tmp_path / "state.json")

    events: list[tuple[str, str]] = []

    class DummySender:
        available = True

        def send(self, virtual_input: str, value: str) -> None:
            events.append((virtual_input, value))

    sender = DummySender()

    worker = BridgeWorker(
        config,
        sender_provider=lambda: sender,
        global_stop=threading.Event(),
        state_store=store,
    )

    mapping = VirtualInputConfig(
        id="motion-1",
        bridge_id="bridge-1",
        resource_id="rid-motion",
        resource_type="motion",
        virtual_input="VI.Motion",
        active_value="1",
        inactive_value="0",
        reset_value=None,
        reset_delay_ms=0,
    )
    worker.update_mappings([mapping])

    class DummyClient:
        def __init__(self) -> None:
            self.payloads: list[HueResource] = []

        def get_motion_sensors(self):
            return list(self.payloads)

    dummy_client = DummyClient()
    worker._client = dummy_client  # type: ignore[attr-defined]

    def make_motion(state: bool) -> HueResource:
        return HueResource(
            id="rid-motion",
            type="motion",
            metadata={},
            data={
                "motion": {
                    "motion_report": {
                        "motion": state,
                        "motion_valid": True,
                    }
                }
            },
        )

    dummy_client.payloads = [make_motion(False)]
    worker._poll_motion_states(sender)
    assert events == []

    dummy_client.payloads = [make_motion(True)]
    worker._poll_motion_states(sender)
    assert events == [("VI.Motion", "1")]

    dummy_client.payloads = [make_motion(False)]
    worker._poll_motion_states(sender)
    assert events[-1] == ("VI.Motion", "0")
