"""FastAPI application exposing Hue resources to Loxone."""
from __future__ import annotations

from typing import Dict, Iterable, Optional

from fastapi import Depends, FastAPI, HTTPException
from pydantic import BaseModel, Field

from .config import ConfigError, load_config
from .hue_client import HueBridgeClient, HueBridgeError, HueResource

app = FastAPI(title="LoxBerry Hue API v2 bridge")


class LightStateRequest(BaseModel):
    on: Optional[bool] = Field(default=None, description="Switch the light on/off")
    brightness: Optional[int] = Field(
        default=None,
        ge=0,
        le=100,
        description="Brightness percentage (0-100)"
    )


class SceneActivationRequest(BaseModel):
    target_rid: Optional[str] = Field(default=None, description="Target resource id")
    target_rtype: Optional[str] = Field(default=None, description="Target resource type")


class HueResourceResponse(BaseModel):
    id: str
    type: str
    name: Optional[str]
    metadata: Dict[str, object]
    data: Dict[str, object]

    @classmethod
    def from_resource(cls, resource: HueResource) -> "HueResourceResponse":
        return cls(
            id=resource.id,
            type=resource.type,
            name=resource.metadata.get("name"),
            metadata=resource.metadata,
            data=resource.data,
        )


def get_client() -> HueBridgeClient:
    try:
        config = load_config()
    except ConfigError as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc

    return HueBridgeClient(config)


@app.get("/lights", response_model=list[HueResourceResponse])
def list_lights(client: HueBridgeClient = Depends(get_client)) -> Iterable[HueResourceResponse]:
    try:
        lights = client.get_lights()
        return [HueResourceResponse.from_resource(light) for light in lights]
    except HueBridgeError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc


@app.post("/lights/{light_id}/state", status_code=204)
def update_light_state(
    light_id: str,
    payload: LightStateRequest,
    client: HueBridgeClient = Depends(get_client),
) -> None:
    try:
        client.set_light_state(
            light_id,
            on=payload.on,
            brightness=payload.brightness,
        )
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    except HueBridgeError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc


@app.get("/scenes", response_model=list[HueResourceResponse])
def list_scenes(client: HueBridgeClient = Depends(get_client)) -> Iterable[HueResourceResponse]:
    try:
        scenes = client.get_scenes()
        return [HueResourceResponse.from_resource(scene) for scene in scenes]
    except HueBridgeError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc


@app.post("/scenes/{scene_id}/activate", status_code=204)
def activate_scene(
    scene_id: str,
    payload: SceneActivationRequest,
    client: HueBridgeClient = Depends(get_client),
) -> None:
    try:
        client.activate_scene(
            scene_id,
            target_rid=payload.target_rid,
            target_rtype=payload.target_rtype,
        )
    except HueBridgeError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc


@app.get("/rooms", response_model=list[HueResourceResponse])
def list_rooms(client: HueBridgeClient = Depends(get_client)) -> Iterable[HueResourceResponse]:
    try:
        rooms = client.get_rooms()
        return [HueResourceResponse.from_resource(room) for room in rooms]
    except HueBridgeError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc
