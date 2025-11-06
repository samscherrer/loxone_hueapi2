import pytest

from hue_plugin.config import LoxoneSettings
from hue_plugin.event_forwarder import LoxoneSender


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
