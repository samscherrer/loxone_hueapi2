from typing import Dict

import pytest
import responses

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
