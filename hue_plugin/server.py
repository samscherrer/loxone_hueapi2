"""FastAPI application exposing Hue resources to Loxone."""
from __future__ import annotations

from typing import Dict, Iterable, Optional

from fastapi import Body, Depends, FastAPI, HTTPException, Query, status
from pydantic import BaseModel, Field

from .config import (
    ConfigError,
    HueBridgeConfig,
    PluginConfig,
    ensure_bridge_id,
    load_config,
    save_config,
)
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


class BridgeConfigResponse(BaseModel):
    id: str
    name: Optional[str]
    bridge_ip: str
    application_key: str
    client_key: Optional[str]
    use_https: bool
    verify_tls: bool

    @classmethod
    def from_config(cls, config: HueBridgeConfig) -> "BridgeConfigResponse":
        return cls(
            id=config.id,
            name=config.name,
            bridge_ip=config.bridge_ip,
            application_key=config.application_key,
            client_key=config.client_key,
            use_https=config.use_https,
            verify_tls=config.verify_tls,
        )


class BridgeCreateRequest(BaseModel):
    bridge_ip: str = Field(..., description="IPv4/IPv6 or hostname of the bridge")
    application_key: str = Field(..., description="Hue application key for the bridge")
    name: Optional[str] = Field(default=None, description="Friendly name")
    client_key: Optional[str] = Field(default=None, description="Optional client key")
    use_https: bool = Field(default=True, description="Use HTTPS for requests")
    verify_tls: bool = Field(default=False, description="Verify TLS certificates")
    id: Optional[str] = Field(
        default=None,
        regex=r"^[a-z0-9\-]+$",
        description="Unique identifier (leave empty to auto-generate)",
    )


class BridgeUpdateRequest(BaseModel):
    bridge_ip: str = Field(..., description="IPv4/IPv6 or hostname of the bridge")
    application_key: str = Field(..., description="Hue application key for the bridge")
    name: Optional[str] = Field(default=None, description="Friendly name")
    client_key: Optional[str] = Field(default=None, description="Optional client key")
    use_https: bool = Field(default=True, description="Use HTTPS for requests")
    verify_tls: bool = Field(default=False, description="Verify TLS certificates")


def _load_plugin_config() -> PluginConfig:
    try:
        return load_config()
    except ConfigError as exc:
        raise HTTPException(status_code=500, detail=str(exc)) from exc


def get_client(bridge_id: Optional[str] = Query(default=None)) -> HueBridgeClient:
    try:
        plugin_config = load_config()
        bridge_config = plugin_config.get_bridge(bridge_id)
    except ConfigError as exc:
        status_code = 404 if bridge_id else 500
        raise HTTPException(status_code=status_code, detail=str(exc)) from exc

    return HueBridgeClient(bridge_config)


@app.get("/lights", response_model=list[HueResourceResponse])
def list_lights(
    client: HueBridgeClient = Depends(get_client),
) -> Iterable[HueResourceResponse]:
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
def list_scenes(
    client: HueBridgeClient = Depends(get_client),
) -> Iterable[HueResourceResponse]:
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
def list_rooms(
    client: HueBridgeClient = Depends(get_client),
) -> Iterable[HueResourceResponse]:
    try:
        rooms = client.get_rooms()
        return [HueResourceResponse.from_resource(room) for room in rooms]
    except HueBridgeError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc


@app.get("/config/bridges", response_model=list[BridgeConfigResponse])
def list_bridge_configs() -> Iterable[BridgeConfigResponse]:
    plugin_config = _load_plugin_config()
    return [BridgeConfigResponse.from_config(bridge) for bridge in plugin_config.bridges]


@app.post(
    "/config/bridges",
    response_model=BridgeConfigResponse,
    status_code=status.HTTP_201_CREATED,
)
def create_bridge_config(payload: BridgeCreateRequest = Body(...)) -> BridgeConfigResponse:
    plugin_config = _load_plugin_config()
    existing_ids = {bridge.id for bridge in plugin_config.bridges}

    bridge_id = payload.id or ensure_bridge_id(
        payload.name or payload.bridge_ip,
        existing_ids=existing_ids,
    )
    if bridge_id in existing_ids:
        raise HTTPException(
            status_code=409,
            detail=f"Bridge-ID '{bridge_id}' wird bereits verwendet.",
        )

    new_bridge = HueBridgeConfig(
        id=bridge_id,
        name=payload.name,
        bridge_ip=payload.bridge_ip,
        application_key=payload.application_key,
        client_key=payload.client_key,
        use_https=payload.use_https,
        verify_tls=payload.verify_tls,
    )

    plugin_config.bridges.append(new_bridge)
    save_config(plugin_config)
    return BridgeConfigResponse.from_config(new_bridge)


@app.put("/config/bridges/{bridge_id}", response_model=BridgeConfigResponse)
def update_bridge_config(
    bridge_id: str,
    payload: BridgeUpdateRequest = Body(...),
) -> BridgeConfigResponse:
    plugin_config = _load_plugin_config()

    for index, bridge in enumerate(plugin_config.bridges):
        if bridge.id == bridge_id:
            updated = HueBridgeConfig(
                id=bridge.id,
                name=payload.name,
                bridge_ip=payload.bridge_ip,
                application_key=payload.application_key,
                client_key=payload.client_key,
                use_https=payload.use_https,
                verify_tls=payload.verify_tls,
            )
            plugin_config.bridges[index] = updated
            save_config(plugin_config)
            return BridgeConfigResponse.from_config(updated)

    raise HTTPException(status_code=404, detail=f"Bridge '{bridge_id}' wurde nicht gefunden.")


@app.delete("/config/bridges/{bridge_id}", status_code=status.HTTP_204_NO_CONTENT)
def delete_bridge_config(bridge_id: str) -> None:
    plugin_config = _load_plugin_config()

    remaining = [bridge for bridge in plugin_config.bridges if bridge.id != bridge_id]
    if len(remaining) == len(plugin_config.bridges):
        raise HTTPException(status_code=404, detail=f"Bridge '{bridge_id}' wurde nicht gefunden.")

    if not remaining:
        raise HTTPException(
            status_code=400,
            detail="Es muss mindestens eine Bridge konfiguriert bleiben.",
        )

    plugin_config.bridges = remaining
    save_config(plugin_config)
