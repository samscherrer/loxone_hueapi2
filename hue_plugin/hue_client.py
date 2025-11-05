"""Client for Philips Hue API v2 resources."""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Iterable, Optional

import requests
from requests import Response

from .config import HueBridgeConfig

_JSON = Dict[str, Any]


@dataclass
class HueResource:
    """Generic Hue resource representation."""

    id: str
    type: str
    metadata: Dict[str, Any]
    data: Dict[str, Any]

    @classmethod
    def from_api(cls, payload: Dict[str, Any]) -> "HueResource":
        return cls(
            id=payload.get("id"),
            type=payload.get("type"),
            metadata=payload.get("metadata", {}),
            data={k: v for k, v in payload.items() if k not in {"id", "type", "metadata"}},
        )


class HueBridgeClient:
    """Wrapper around the Hue REST API v2."""

    def __init__(self, config: HueBridgeConfig) -> None:
        self._config = config
        self._session = requests.Session()
        self._session.headers.update({"hue-application-key": config.application_key})
        if config.client_key:
            self._session.headers.update({"hue-client-key": config.client_key})

    # -- high level resource helpers -------------------------------------------------
    def get_lights(self) -> Iterable[HueResource]:
        return self._list_resources("light")

    def get_scenes(self) -> Iterable[HueResource]:
        return self._list_resources("scene")

    def get_rooms(self) -> Iterable[HueResource]:
        return self._list_resources("room")

    # -- mutating operations ---------------------------------------------------------
    def activate_scene(
        self,
        scene_id: str,
        *,
        target_rid: Optional[str] = None,
        target_rtype: Optional[str] = None,
    ) -> None:
        body: _JSON = {"recall": {"action": "activate"}}
        if target_rid and target_rtype:
            body["recall"]["target"] = {"rid": target_rid, "rtype": target_rtype}
        self._put(f"scene/{scene_id}", json=body)

    def set_light_state(
        self,
        light_id: str,
        *,
        on: Optional[bool] = None,
        brightness: Optional[int] = None,
    ) -> None:
        body: _JSON = {}
        if on is not None:
            body.setdefault("on", {})["on"] = on
        if brightness is not None:
            if not 0 <= brightness <= 100:
                raise ValueError("Brightness must be between 0 and 100")
            body.setdefault("dimming", {})["brightness"] = brightness

        if not body:
            raise ValueError("At least one state value must be provided")

        self._put(f"light/{light_id}", json=body)

    # -- low level helpers -----------------------------------------------------------
    def _list_resources(self, resource: str) -> Iterable[HueResource]:
        payload = self._get(resource)
        return [HueResource.from_api(item) for item in payload.get("data", [])]

    def _get(self, path: str) -> _JSON:
        response = self._session.get(
            f"{self._config.base_url}/{path}",
            verify=self._config.verify_tls,
            timeout=10,
        )
        return self._handle_response(response)

    def _put(self, path: str, *, json: Optional[_JSON] = None) -> _JSON:
        response = self._session.put(
            f"{self._config.base_url}/{path}",
            json=json,
            verify=self._config.verify_tls,
            timeout=10,
        )
        return self._handle_response(response)

    def _handle_response(self, response: Response) -> _JSON:
        try:
            response.raise_for_status()
        except requests.HTTPError as exc:
            raise HueBridgeError.from_response(response) from exc

        data = response.json()
        if isinstance(data, dict) and data.get("errors"):
            raise HueBridgeError.from_errors(data["errors"])
        return data


class HueBridgeError(RuntimeError):
    """Raised when the Hue Bridge returns an error."""

    def __init__(self, message: str, *, errors: Optional[Iterable[_JSON]] = None) -> None:
        super().__init__(message)
        self.errors = list(errors or [])

    @classmethod
    def from_response(cls, response: Response) -> "HueBridgeError":
        try:
            payload = response.json()
        except ValueError:
            payload = None

        if isinstance(payload, dict) and payload.get("errors"):
            return cls.from_errors(payload["errors"])

        message = f"Hue bridge request failed with status {response.status_code}"
        return cls(message)

    @classmethod
    def from_errors(cls, errors: Iterable[_JSON]) -> "HueBridgeError":
        errors_list = list(errors)
        message = "; ".join(error.get("description", "Unknown error") for error in errors_list)
        return cls(message or "Hue bridge request returned errors", errors=errors_list)


__all__ = ["HueBridgeClient", "HueResource", "HueBridgeError"]
