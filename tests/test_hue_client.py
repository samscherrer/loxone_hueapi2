from typing import Dict

import pytest
import responses

import requests

from hue_plugin.config import HueBridgeConfig
from hue_plugin.hue_client import HueBridgeClient, HueBridgeError


@pytest.fixture()
def client() -> HueBridgeClient:
    config = HueBridgeConfig(
        id="test",
        bridge_ip="1.2.3.4",
        application_key="key",
        use_https=False,
        verify_tls=False,
    )
    return HueBridgeClient(config)


@responses.activate
def test_get_lights_success(client: HueBridgeClient) -> None:
    responses.add(
        responses.GET,
        "http://1.2.3.4/clip/v2/resource/light",
        json={"data": [{"id": "1", "type": "light", "metadata": {"name": "Test"}}]},
        status=200,
    )

    lights = list(client.get_lights())

    assert len(lights) == 1
    assert lights[0].id == "1"
    assert lights[0].metadata["name"] == "Test"


@responses.activate
def test_activate_scene_error(client: HueBridgeClient) -> None:
    responses.add(
        responses.PUT,
        "http://1.2.3.4/clip/v2/resource/scene/scene-id",
        json={"errors": [{"description": "failed"}]},
        status=200,
    )

    with pytest.raises(HueBridgeError):
        client.activate_scene("scene-id")


@responses.activate
def test_set_light_state_validates(client: HueBridgeClient) -> None:
    with pytest.raises(ValueError):
        client.set_light_state("1")

    with pytest.raises(ValueError):
        client.set_light_state("1", brightness=120)

    responses.add(
        responses.PUT,
        "http://1.2.3.4/clip/v2/resource/light/1",
        json={},
        status=200,
    )

    client.set_light_state("1", on=True, brightness=50)

    import json

    body = responses.calls[0].request.body
    if isinstance(body, bytes):
        body = body.decode()
    request_body: Dict[str, object] = json.loads(body)
    assert request_body == {"on": {"on": True}, "dimming": {"brightness": 50}}


def test_get_lights_ssl_error(monkeypatch: pytest.MonkeyPatch, client: HueBridgeClient) -> None:
    def raise_ssl(*args: object, **kwargs: object) -> None:
        raise requests.exceptions.SSLError("certificate verify failed")

    client._config.verify_tls = True
    monkeypatch.setattr(client._session, "request", raise_ssl)

    with pytest.raises(HueBridgeError) as excinfo:
        list(client.get_lights())

    assert "Zertifikat" in str(excinfo.value)


def test_get_lights_request_error(monkeypatch: pytest.MonkeyPatch, client: HueBridgeClient) -> None:
    def raise_request(*args: object, **kwargs: object) -> None:
        raise requests.exceptions.ConnectionError("boom")

    monkeypatch.setattr(client._session, "request", raise_request)

    with pytest.raises(HueBridgeError) as excinfo:
        list(client.get_lights())

    assert "Verbindung zur Hue Bridge" in str(excinfo.value)
