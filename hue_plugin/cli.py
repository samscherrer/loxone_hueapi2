"""Command line helpers for interacting with Hue bridges."""
from __future__ import annotations

import argparse
import json
import sys
from collections import defaultdict
from datetime import date, datetime
from typing import Any, Dict, Iterable, List, Optional, Tuple

import math
import re

from .config import (
    ConfigError,
    HueBridgeConfig,
    PluginConfig,
    load_config,
    runtime_state_path,
)
from .event_forwarder import (
    EventStateStore,
    LoxoneSender,
    extract_motion_state,
    load_event_state,
)
from .hue_client import HueBridgeClient, HueBridgeError, HueResource


def _plugin_config(path: str | None) -> PluginConfig:
    try:
        return load_config(path)
    except ConfigError as exc:  # pragma: no cover - propagated as exit code
        raise SystemExit(str(exc)) from exc


def _bridge_config(config: PluginConfig, bridge_id: str | None) -> HueBridgeConfig:
    try:
        return config.get_bridge(bridge_id)
    except ConfigError as exc:  # pragma: no cover - propagated as exit code
        raise SystemExit(str(exc)) from exc


def _client(bridge: HueBridgeConfig) -> HueBridgeClient:
    return HueBridgeClient(bridge)


def _resource_to_dict(resource: HueResource) -> Dict[str, Any]:
    return {
        "id": resource.id,
        "type": resource.type,
        "name": resource.metadata.get("name"),
        "metadata": resource.metadata,
        "data": resource.data,
    }


def _parse_event_timestamp(value: Any) -> Optional[datetime]:
    if not isinstance(value, str) or not value:
        return None
    candidate = value.strip()
    if candidate.endswith("Z"):
        candidate = candidate[:-1] + "+00:00"
    try:
        timestamp = datetime.fromisoformat(candidate)
    except ValueError:
        return None
    if timestamp.tzinfo is None:
        return timestamp.astimezone()
    return timestamp


def _event_local_date(timestamp: Optional[datetime]) -> Optional[date]:
    if timestamp is None:
        return None
    return timestamp.astimezone().date()


def _parse_rgb_string(value: str) -> Optional[Tuple[int, int, int]]:
    cleaned = value.strip()
    if not cleaned:
        return None
    if cleaned.lower().startswith("rgb") and "(" in cleaned and ")" in cleaned:
        cleaned = cleaned[cleaned.find("(") + 1 : cleaned.rfind(")")]
    if cleaned.startswith("#"):
        cleaned = cleaned[1:]
    cleaned = cleaned.replace(";", ",").replace(":", ",").replace(" ", "")
    if re.fullmatch(r"[0-9a-fA-F]{6}", cleaned):
        r = int(cleaned[0:2], 16)
        g = int(cleaned[2:4], 16)
        b = int(cleaned[4:6], 16)
        return (r, g, b)
    if re.fullmatch(r"\d{1,3}(?:,\d{1,3}){2}", cleaned):
        parts = [int(part) for part in cleaned.split(",")]
        if all(0 <= part <= 255 for part in parts):
            return tuple(parts)  # type: ignore[return-value]
    return None


def _rgb_to_xy(rgb: Tuple[int, int, int]) -> Tuple[float, float]:
    r, g, b = rgb

    def gamma_correct(component: int) -> float:
        normalised = component / 255.0
        if normalised > 0.04045:
            return ((normalised + 0.055) / 1.055) ** 2.4
        return normalised / 12.92

    r_corr = gamma_correct(r)
    g_corr = gamma_correct(g)
    b_corr = gamma_correct(b)

    X = r_corr * 0.664511 + g_corr * 0.154324 + b_corr * 0.162028
    Y = r_corr * 0.283881 + g_corr * 0.668433 + b_corr * 0.047685
    Z = r_corr * 0.000088 + g_corr * 0.07231 + b_corr * 0.986039

    denom = X + Y + Z
    if denom == 0:
        return (0.0, 0.0)
    x = X / denom
    y = Y / denom
    return (round(x, 4), round(y, 4))


def _kelvin_to_mirek(kelvin: int) -> int:
    if kelvin <= 0:
        raise ValueError("Kelvin muss größer als 0 sein")
    mirek = int(round(1_000_000 / kelvin))
    return max(153, min(500, mirek))


def _resource_name(resource: HueResource) -> str | None:
    name = resource.metadata.get("name")
    return name if isinstance(name, str) else None


def command_test_connection(args: argparse.Namespace) -> Dict[str, Any]:
    config = _plugin_config(args.config)
    bridge = _bridge_config(config, args.bridge_id)
    client = _client(bridge)
    try:
        list(client.get_lights())
    except HueBridgeError as exc:
        raise SystemExit(str(exc)) from exc
    return {"ok": True}


def command_list_resources(args: argparse.Namespace) -> Dict[str, Any]:
    config = _plugin_config(args.config)
    bridge = _bridge_config(config, args.bridge_id)
    client = _client(bridge)

    getter = {
        "lights": client.get_lights,
        "scenes": client.get_scenes,
        "rooms": client.get_rooms,
        "buttons": client.get_buttons,
        "motions": client.get_motion_sensors,
    }[args.type]

    try:
        resources = list(getter())
    except HueBridgeError as exc:
        raise SystemExit(str(exc)) from exc

    items = [_resource_to_dict(item) for item in resources]

    if args.type == "scenes":
        group_lookup: Dict[str, Dict[str, str | None]] = {}
        for room in client.get_rooms():
            group_lookup[room.id] = {"name": _resource_name(room), "type": "room"}
        for zone in client.get_zones():
            group_lookup[zone.id] = {"name": _resource_name(zone), "type": "zone"}

        for resource, item in zip(resources, items):
            group = resource.data.get("group")
            details: Dict[str, Any] | None = None
            if isinstance(group, dict):
                rid = group.get("rid")
                rtype = group.get("rtype")
                details = {"rid": rid, "rtype": rtype, "name": None}
                if isinstance(rid, str) and rid in group_lookup:
                    resolved = group_lookup[rid]
                    details["name"] = resolved.get("name")
                    details["rtype"] = resolved.get("type") or rtype
            item["group"] = details

    if args.type == "lights":
        room_lookup: Dict[str, List[Dict[str, str | None]]] = defaultdict(list)
        for room in client.get_rooms():
            room_name = _resource_name(room)
            services = room.data.get("services")
            if not isinstance(services, list):
                continue
            for service in services:
                if not isinstance(service, dict):
                    continue
                if service.get("rtype") != "light":
                    continue
                rid = service.get("rid")
                if not isinstance(rid, str):
                    continue
                info = {"id": room.id, "name": room_name}
                if info not in room_lookup[rid]:
                    room_lookup[rid].append(info)

        scene_lookup: Dict[str, List[Dict[str, str | None]]] = defaultdict(list)
        for scene in client.get_scenes():
            scene_name = _resource_name(scene)
            actions = scene.data.get("actions")
            if not isinstance(actions, list):
                continue
            for action in actions:
                if not isinstance(action, dict):
                    continue
                target = action.get("target")
                if not isinstance(target, dict):
                    continue
                if target.get("rtype") != "light":
                    continue
                rid = target.get("rid")
                if not isinstance(rid, str):
                    continue
                info = {"id": scene.id, "name": scene_name}
                if info not in scene_lookup[rid]:
                    scene_lookup[rid].append(info)

        for resource, item in zip(resources, items):
            item["rooms"] = room_lookup.get(resource.id, [])
            item["scenes"] = scene_lookup.get(resource.id, [])

    if args.type == "buttons":
        device_lookup: Dict[str, Dict[str, Any]] = {}
        for device in client.get_devices():
            device_lookup[device.id] = {
                "name": _resource_name(device),
                "id": device.id,
            }
        for resource, item in zip(resources, items):
            owner = resource.data.get("owner")
            if isinstance(owner, dict):
                rid = owner.get("rid")
                if isinstance(rid, str) and rid in device_lookup:
                    item["device"] = device_lookup[rid]

    if args.type == "motions":
        for resource, item in zip(resources, items):
            state = extract_motion_state(resource.data)
            if state is not None:
                item["state"] = state

    return {"items": items}


def command_virtual_input_events(args: argparse.Namespace) -> Dict[str, Any]:
    state_path = runtime_state_path(args.config)
    data = load_event_state(state_path)
    events_raw = data.get("events")
    states = data.get("states")

    parsed: List[Tuple[Dict[str, Any], Optional[datetime]]] = []
    available_dates: set[str] = set()

    if isinstance(events_raw, list):
        for entry in events_raw:
            if not isinstance(entry, dict):
                continue
            timestamp = _parse_event_timestamp(entry.get("timestamp"))
            local_date = _event_local_date(timestamp)
            if local_date is not None:
                available_dates.add(local_date.isoformat())
            parsed.append((entry, timestamp))

    if not isinstance(states, dict):
        states = {}

    filter_date: Optional[date] = None
    if args.date:
        try:
            filter_date = datetime.strptime(args.date, "%Y-%m-%d").date()
        except ValueError as exc:
            raise SystemExit("Ungültiges Datum. Bitte YYYY-MM-DD verwenden.") from exc

    before_id = getattr(args, "before", None)
    after_id = getattr(args, "after", None)

    filtered: List[Tuple[Dict[str, Any], Optional[datetime]]] = []
    for entry, timestamp in parsed:
        event_id = entry.get("event_id")
        if before_id is not None and isinstance(event_id, int) and event_id >= before_id:
            continue
        if after_id is not None and isinstance(event_id, int) and event_id <= after_id:
            continue
        if filter_date is not None:
            local_date = _event_local_date(timestamp)
            if local_date != filter_date:
                continue
        filtered.append((entry, timestamp))

    filtered.sort(key=lambda pair: pair[0].get("event_id", 0), reverse=True)

    total_filtered = len(filtered)
    limit = getattr(args, "limit", None)
    limit_value = limit if isinstance(limit, int) and limit > 0 else None
    paginated = filtered
    has_more = False
    next_before: Optional[int] = None

    if limit_value is not None and total_filtered > limit_value:
        paginated = filtered[:limit_value]
        has_more = True
        event_ids = [
            entry.get("event_id")
            for entry, _ in paginated
            if isinstance(entry.get("event_id"), int)
        ]
        if event_ids:
            next_before = min(event_ids)

    latest_event_id = None
    if filtered:
        candidate = filtered[0][0].get("event_id")
        if isinstance(candidate, int):
            latest_event_id = candidate

    payload_events = [entry for entry, _ in paginated]

    metadata = {
        "has_more": has_more,
        "next_before": next_before,
        "before": before_id,
        "after": after_id,
        "limit": limit_value,
        "date": filter_date.isoformat() if filter_date else None,
        "available_dates": sorted(available_dates, reverse=True),
        "total_filtered": total_filtered,
        "latest_event_id": latest_event_id,
    }

    return {"events": payload_events, "states": states, "metadata": metadata}


def command_light_command(args: argparse.Namespace) -> Dict[str, Any]:
    config = _plugin_config(args.config)
    bridge = _bridge_config(config, args.bridge_id)
    client = _client(bridge)

    color_xy: Optional[Tuple[float, float]] = None
    if getattr(args, "xy", None):
        xy_values = args.xy
        if isinstance(xy_values, (list, tuple)) and len(xy_values) == 2:
            color_xy = (float(xy_values[0]), float(xy_values[1]))
    if getattr(args, "rgb", None):
        parsed = _parse_rgb_string(args.rgb)
        if parsed is None:
            raise SystemExit(
                "Ungültiger RGB-Wert. Verwende #RRGGBB oder R,G,B mit 0-255."
            )
        color_xy = _rgb_to_xy(parsed)

    temperature_mirek: Optional[int] = None
    if getattr(args, "mirek", None) is not None:
        temperature_mirek = int(args.mirek)
    elif getattr(args, "temperature", None) is not None:
        try:
            temperature_mirek = _kelvin_to_mirek(int(args.temperature))
        except ValueError as exc:
            raise SystemExit(str(exc)) from exc

    transition_ms: Optional[int] = None
    if getattr(args, "transition", None) is not None:
        transition_ms = max(0, int(args.transition))

    try:
        client.set_light_state(
            args.light_id,
            on=args.state,
            brightness=args.brightness,
            color_xy=color_xy,
            temperature_mirek=temperature_mirek,
            transition_ms=transition_ms,
        )
    except (ValueError, HueBridgeError) as exc:
        raise SystemExit(str(exc)) from exc

    return {"ok": True}


def command_scene_command(args: argparse.Namespace) -> Dict[str, Any]:
    config = _plugin_config(args.config)
    bridge = _bridge_config(config, args.bridge_id)
    client = _client(bridge)

    transition_ms: Optional[int] = 0
    if getattr(args, "transition", None) is not None:
        transition_ms = max(0, int(args.transition))

    try:
        state = True if args.state is None else args.state
        if state:
            client.activate_scene(
                args.scene_id,
                target_rid=args.target_rid,
                target_rtype=args.target_rtype,
                dynamics_duration=transition_ms,
            )
        else:
            client.deactivate_scene(
                args.scene_id,
                target_rid=args.target_rid,
                target_rtype=args.target_rtype,
            )
    except HueBridgeError as exc:
        raise SystemExit(str(exc)) from exc

    return {"ok": True}


def command_clear_virtual_events(args: argparse.Namespace) -> Dict[str, Any]:
    state_path = runtime_state_path(args.config)
    store = EventStateStore(state_path)
    store.clear()
    return {"ok": True}


_COMMANDS = {
    "test-connection": command_test_connection,
    "list-resources": command_list_resources,
    "virtual-input-events": command_virtual_input_events,
    "clear-virtual-events": command_clear_virtual_events,
    "light-command": command_light_command,
    "scene-command": command_scene_command,
}


def command_forward_virtual_input(args: argparse.Namespace) -> Dict[str, Any]:
    config = _plugin_config(args.config)
    try:
        mapping = next(entry for entry in config.virtual_inputs if entry.id == args.virtual_input_id)
    except StopIteration as exc:  # pragma: no cover - propagated as exit code
        raise SystemExit(f"Virtueller Eingang '{args.virtual_input_id}' wurde nicht gefunden.") from exc

    sender = LoxoneSender(config.loxone)

    if args.state == "custom":
        if args.value is None:
            raise SystemExit("Bitte einen Wert für den Testaufruf angeben.")
        value = args.value
    elif args.state == "inactive":
        if mapping.inactive_value is None:
            raise SystemExit("Für diesen Eingang ist kein Inaktiv-Wert hinterlegt.")
        value = mapping.inactive_value
    elif args.state == "reset":
        candidate = mapping.reset_value if mapping.reset_value is not None else mapping.inactive_value
        if candidate is None:
            raise SystemExit("Für diesen Eingang ist kein Reset-/Inaktiv-Wert hinterlegt.")
        value = candidate
    else:
        value = mapping.active_value

    try:
        sender.send(mapping.virtual_input, value)
    except RuntimeError as exc:  # pragma: no cover - propagated as exit code
        raise SystemExit(str(exc)) from exc

    return {"ok": True}


_COMMANDS["forward-virtual-input"] = command_forward_virtual_input


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Hue bridge helper commands")
    parser.add_argument(
        "--config",
        dest="config",
        default=None,
        help="Pfad zur Konfigurationsdatei (optional)",
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    parser_test = subparsers.add_parser("test-connection", help="Bridge-Verbindung testen")
    parser_test.add_argument("--bridge-id", dest="bridge_id", default=None)

    parser_list = subparsers.add_parser("list-resources", help="Ressourcen der Bridge laden")
    parser_list.add_argument("--bridge-id", dest="bridge_id", default=None)
    parser_list.add_argument(
        "--type",
        dest="type",
        choices=["lights", "scenes", "rooms", "buttons", "motions"],
        required=True,
    )

    parser_events = subparsers.add_parser(
        "virtual-input-events",
        help="Letzte Hue-Ereignisse und virtuelle Eingänge anzeigen",
    )
    parser_events.add_argument(
        "--limit",
        dest="limit",
        type=int,
        default=None,
        help="Optional: Anzahl der zurückgegebenen Ereignisse begrenzen",
    )
    parser_events.add_argument(
        "--date",
        dest="date",
        default=None,
        help="Optional: Ereignisse eines bestimmten Tages (YYYY-MM-DD)",
    )
    parser_events.add_argument(
        "--before",
        dest="before",
        type=int,
        default=None,
        help="Optional: Nur Ereignisse mit kleinerer ID berücksichtigen",
    )
    parser_events.add_argument(
        "--after",
        dest="after",
        type=int,
        default=None,
        help="Optional: Nur Ereignisse mit größerer ID berücksichtigen",
    )

    subparsers.add_parser(
        "clear-virtual-events",
        help="Gespeicherte Hue-Ereignisse und Zustände löschen",
    )

    parser_light = subparsers.add_parser("light-command", help="Lampenzustand setzen")
    parser_light.add_argument("--bridge-id", dest="bridge_id", default=None)
    parser_light.add_argument("--light-id", dest="light_id", required=True)
    state_group = parser_light.add_mutually_exclusive_group()
    state_group.add_argument("--on", dest="state", action="store_const", const=True)
    state_group.add_argument("--off", dest="state", action="store_const", const=False)
    parser_light.set_defaults(state=None)
    parser_light.add_argument(
        "--brightness",
        dest="brightness",
        type=int,
        default=None,
    )
    parser_light.add_argument("--rgb", dest="rgb", default=None)
    parser_light.add_argument(
        "--xy",
        dest="xy",
        nargs=2,
        type=float,
        default=None,
        metavar=("X", "Y"),
    )
    parser_light.add_argument(
        "--temperature",
        dest="temperature",
        type=int,
        default=None,
        help="Farbtemperatur in Kelvin",
    )
    parser_light.add_argument(
        "--mirek",
        dest="mirek",
        type=int,
        default=None,
        help="Direkter Mirek-Wert für die Farbtemperatur",
    )
    parser_light.add_argument(
        "--transition",
        dest="transition",
        type=int,
        default=None,
        help="Optional: Übergangszeit in Millisekunden",
    )

    parser_scene = subparsers.add_parser("scene-command", help="Szene aktivieren")
    parser_scene.add_argument("--bridge-id", dest="bridge_id", default=None)
    parser_scene.add_argument("--scene-id", dest="scene_id", required=True)
    parser_scene.add_argument("--target-rid", dest="target_rid", default=None)
    parser_scene.add_argument("--target-rtype", dest="target_rtype", default=None)
    scene_state = parser_scene.add_mutually_exclusive_group()
    scene_state.add_argument("--on", dest="state", action="store_const", const=True)
    scene_state.add_argument("--off", dest="state", action="store_const", const=False)
    parser_scene.set_defaults(state=None)
    parser_scene.add_argument(
        "--transition",
        dest="transition",
        type=int,
        default=None,
        help="Optional: Übergangszeit in Millisekunden für die Aktivierung",
    )

    parser_virtual = subparsers.add_parser(
        "forward-virtual-input", help="Virtuellen Loxone-Eingang testen"
    )
    parser_virtual.add_argument("--virtual-input-id", dest="virtual_input_id", required=True)
    parser_virtual.add_argument(
        "--state",
        dest="state",
        choices=["active", "inactive", "reset", "custom"],
        default="active",
    )
    parser_virtual.add_argument("--value", dest="value", default=None)

    return parser


def main(argv: Iterable[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    handler = _COMMANDS[args.command]
    payload = handler(args)
    json.dump(payload, sys.stdout, ensure_ascii=False)
    return 0


if __name__ == "__main__":  # pragma: no cover - manual invocation
    raise SystemExit(main())
