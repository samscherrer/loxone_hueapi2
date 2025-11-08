"""Client for Philips Hue API v2 resources."""
from __future__ import annotations

import json
from dataclasses import dataclass
from typing import Any, Dict, Iterable, Iterator, List, Optional, Tuple

import requests
from requests import Response
from requests import exceptions as requests_exc

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
        if not config.verify_tls:
            try:  # pragma: no cover - best effort helper
                import urllib3

                urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
            except Exception:
                pass

    # -- high level resource helpers -------------------------------------------------
    def get_lights(self) -> Iterable[HueResource]:
        return self._list_resources("light")

    def get_scenes(self) -> Iterable[HueResource]:
        return self._list_resources("scene")

    def get_rooms(self) -> Iterable[HueResource]:
        return self._list_resources("room")

    def get_zones(self) -> Iterable[HueResource]:
        """Return all Hue zones."""

        return self._list_resources("zone")

    def get_grouped_lights(self) -> Iterable[HueResource]:
        """Return grouped lights for rooms or zones."""

        return self._list_resources("grouped_light")

    def get_buttons(self) -> Iterable[HueResource]:
        """Return button resources for Hue switches."""

        return self._list_resources("button")

    def get_motion_sensors(self) -> Iterable[HueResource]:
        """Return motion sensor resources."""

        return self._list_resources("motion")

    def get_devices(self) -> Iterable[HueResource]:
        """Return Hue devices."""

        return self._list_resources("device")

    def get_scene(self, scene_id: str) -> HueResource:
        """Return a single scene resource."""

        payload = self._get(f"scene/{scene_id}")
        data = payload.get("data", [])
        if not isinstance(data, list) or not data:
            raise HueBridgeError("Szene wurde nicht gefunden.")
        return HueResource.from_api(data[0])

    # -- mutating operations ---------------------------------------------------------
    def activate_scene(
        self,
        scene_id: str,
        *,
        target_rid: Optional[str] = None,
        target_rtype: Optional[str] = None,
        dynamics_duration: Optional[int] = None,
    ) -> None:
        body: _JSON = {"recall": {"action": "active"}}
        if target_rid and target_rtype:
            body["recall"]["target"] = {"rid": target_rid, "rtype": target_rtype}
        if dynamics_duration is not None:
            duration = max(0, min(int(dynamics_duration), 600000))
            if duration > 0:
                body["recall"]["dynamics"] = {"duration": duration}
        self._put(f"scene/{scene_id}", json=body)

    def deactivate_scene(
        self,
        scene_id: str,
        *,
        target_rid: Optional[str] = None,
        target_rtype: Optional[str] = None,
    ) -> None:
        group_rid = target_rid
        group_rtype = target_rtype

        if not group_rid:
            scene = self.get_scene(scene_id)
            group = scene.data.get("group")
            if isinstance(group, dict):
                group_rid = group.get("rid")
                group_rtype = group.get("rtype")

        if not group_rid:
            raise HueBridgeError(
                "Die Szene enthält keine Gruppeninformation. Bitte ein Ziel angeben."
            )

        grouped_light_id = self._resolve_grouped_light_id(group_rid, group_rtype)
        if not grouped_light_id:
            raise HueBridgeError(
                "Für das Ziel wurde kein grouped_light gefunden. Prüfe die Hue-Konfiguration."
            )

        self.set_grouped_light_state(grouped_light_id, on=False)

    def set_light_state(
        self,
        light_id: str,
        *,
        on: Optional[bool] = None,
        brightness: Optional[int] = None,
        color_xy: Optional[Tuple[float, float]] = None,
        temperature_mirek: Optional[int] = None,
        transition_ms: Optional[int] = None,
    ) -> None:
        body: _JSON = {}
        if on is not None:
            body.setdefault("on", {})["on"] = on
        if brightness is not None:
            if not 0 <= brightness <= 100:
                raise ValueError("Brightness must be between 0 and 100")
            body.setdefault("dimming", {})["brightness"] = brightness
        if color_xy is not None:
            x, y = color_xy
            if not 0 <= x <= 1 or not 0 <= y <= 1:
                raise ValueError("xy-Farbwerte müssen zwischen 0 und 1 liegen")
            body.setdefault("color", {}).setdefault("xy", {})
            body["color"]["xy"]["x"] = round(x, 4)
            body["color"]["xy"]["y"] = round(y, 4)
        if temperature_mirek is not None:
            mirek = int(temperature_mirek)
            if not 153 <= mirek <= 500:
                raise ValueError("Mirek muss zwischen 153 und 500 liegen")
            body.setdefault("color_temperature", {})["mirek"] = mirek
        if transition_ms is not None:
            body.setdefault("dynamics", {})["duration"] = max(0, int(transition_ms))

        if not body:
            raise ValueError("At least one state value must be provided")

        self._put(f"light/{light_id}", json=body)

    def set_grouped_light_state(
        self,
        grouped_light_id: str,
        *,
        on: Optional[bool] = None,
    ) -> None:
        body: _JSON = {}
        if on is not None:
            body.setdefault("on", {})["on"] = on

        if not body:
            raise ValueError("At least one state value must be provided")

        self._put(f"grouped_light/{grouped_light_id}", json=body)

    # -- low level helpers -----------------------------------------------------------
    def _list_resources(self, resource: str) -> Iterable[HueResource]:
        payload = self._get(resource)
        return [HueResource.from_api(item) for item in payload.get("data", [])]

    def _resolve_grouped_light_id(
        self,
        owner_rid: str,
        owner_rtype: Optional[str] = None,
    ) -> Optional[str]:
        for resource in self.get_grouped_lights():
            owner = resource.data.get("owner")
            if not isinstance(owner, dict):
                continue
            rid = owner.get("rid")
            rtype = owner.get("rtype")
            if rid != owner_rid:
                continue
            if owner_rtype and rtype != owner_rtype:
                continue
            return resource.id
        return None

    def _get(self, path: str) -> _JSON:
        response = self._request("GET", path)
        return self._handle_response(response)

    def _put(self, path: str, *, json: Optional[_JSON] = None) -> _JSON:
        response = self._request("PUT", path, json=json)
        return self._handle_response(response)

    def _request(self, method: str, path: str, *, json: Optional[_JSON] = None) -> Response:
        url = f"{self._config.base_url}/{path}"
        try:
            return self._session.request(
                method,
                url,
                json=json,
                verify=self._config.verify_tls,
                timeout=10,
            )
        except requests_exc.SSLError as exc:
            if self._config.verify_tls:
                hint = (
                    "TLS-Handshake mit der Hue Bridge ist fehlgeschlagen: "
                    "Zertifikat konnte nicht verifiziert werden. "
                    "Deaktiviere die Zertifikatsprüfung in der Bridge-"
                    "Konfiguration oder installiere das Hue-Stammzertifikat auf dem System."
                )
            else:
                hint = "TLS-Handshake mit der Hue Bridge ist fehlgeschlagen."
            raise HueBridgeError(hint) from exc
        except requests_exc.RequestException as exc:  # pragma: no cover - defensive
            raise HueBridgeError(f"Verbindung zur Hue Bridge fehlgeschlagen: {exc}") from exc

    def iter_events(self) -> Iterator[_JSON]:
        """Yield raw event payloads from the Hue event stream."""

        protocol = "https" if self._config.use_https else "http"
        url = f"{protocol}://{self._config.bridge_ip}/eventstream/clip/v2"
        headers = {"Accept": "text/event-stream"}

        while True:
            try:
                with self._session.get(
                    url,
                    headers=headers,
                    stream=True,
                    verify=self._config.verify_tls,
                    timeout=60,
                ) as response:
                    response.raise_for_status()
                    data_lines: List[str] = []
                    for raw_line in response.iter_lines(decode_unicode=True):
                        if raw_line is None:
                            continue
                        line = raw_line.strip()
                        if line == "":
                            if not data_lines:
                                continue
                            payload_str = "\n".join(data_lines)
                            data_lines = []
                            if not payload_str:
                                continue
                            try:
                                payload = json.loads(payload_str)
                            except ValueError:
                                continue
                            yield payload
                            continue
                        if line.startswith(":"):
                            continue
                        if line.startswith("data:"):
                            data_lines.append(line[5:].strip())
                    # Connection closed, loop again to reconnect
            except requests_exc.RequestException as exc:
                raise HueBridgeError(
                    f"Event-Stream konnte nicht aufgebaut werden: {exc}"
                ) from exc

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
