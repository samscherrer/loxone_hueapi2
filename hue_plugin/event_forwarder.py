from __future__ import annotations

import threading
import time
import json
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable, Dict, Iterable, Iterator, List, Optional, Tuple
from urllib.parse import quote

import requests
from requests import exceptions as requests_exc

from .config import (
    ConfigError,
    HueBridgeConfig,
    LoxoneSettings,
    PluginConfig,
    VirtualInputConfig,
    load_config,
    runtime_state_path,
)
from .hue_client import HueBridgeClient, HueBridgeError


def _log(message: str) -> None:
    print(f"[hue-event-forwarder] {message}", flush=True)


class EventStateStore:
    """Persist and expose the latest Hue → Loxone events."""

    def __init__(self, path: Path, max_events: int = 200) -> None:
        self._path = Path(path)
        self._max_events = max_events
        self._lock = threading.Lock()
        self._counter = int(time.time() * 1000)
        self._data: Dict[str, Any] = {"events": [], "states": {}}
        self._load()

    def _load(self) -> None:
        if not self._path.exists():
            return
        try:
            raw = self._path.read_text(encoding="utf-8")
            data = json.loads(raw)
        except (OSError, ValueError):  # pragma: no cover - best effort recovery
            return
        if not isinstance(data, dict):
            return
        events = data.get("events")
        states = data.get("states")
        if not isinstance(events, list):
            events = []
        if not isinstance(states, dict):
            states = {}
        if len(events) > self._max_events:
            events = events[-self._max_events :]
        self._data = {"events": events, "states": states}
        for event in events:
            event_id = event.get("event_id")
            if isinstance(event_id, int):
                self._counter = max(self._counter, event_id)

    def _next_event_id(self) -> int:
        self._counter += 1
        return self._counter

    def _persist_locked(self) -> None:
        try:
            self._path.parent.mkdir(parents=True, exist_ok=True)
            tmp_path = self._path.with_suffix(".tmp")
            with tmp_path.open("w", encoding="utf-8") as handle:
                json.dump(self._data, handle, ensure_ascii=False, indent=2)
                handle.write("\n")
            tmp_path.replace(self._path)
        except OSError as exc:  # pragma: no cover - best effort logging
            _log(f"Statusdatei konnte nicht aktualisiert werden: {exc}")

    def record(
        self,
        mapping: VirtualInputConfig,
        *,
        event_type: str,
        state: str,
        value: Optional[str],
        trigger: Optional[str] = None,
        delivered: bool = True,
        extra: Optional[Dict[str, Any]] = None,
    ) -> None:
        timestamp = datetime.now(timezone.utc).isoformat()
        value_str = "" if value is None else str(value)
        event: Dict[str, Any] = {
            "event_id": self._next_event_id(),
            "timestamp": timestamp,
            "mapping_id": mapping.id,
            "bridge_id": mapping.bridge_id,
            "resource_id": mapping.resource_id,
            "resource_type": mapping.resource_type,
            "virtual_input": mapping.virtual_input,
            "name": mapping.name,
            "event_type": event_type,
            "state": state,
            "value": value_str,
            "delivered": bool(delivered),
        }
        if trigger:
            event["trigger"] = trigger
        if extra:
            event["extra"] = extra

        with self._lock:
            states = self._data.setdefault("states", {})
            states[mapping.id] = dict(event)
            events = self._data.setdefault("events", [])
            events.append(event)
            if len(events) > self._max_events:
                del events[:-self._max_events]
            self._persist_locked()

    def clear(self) -> None:
        """Remove all persisted events and states."""

        with self._lock:
            self._data = {"events": [], "states": {}}
            self._persist_locked()


def _coerce_motion_state(value: Any) -> Optional[bool]:
    """Best-effort conversion of Hue motion payload values to booleans."""

    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        if value == 0:
            return False
        if value == 1:
            return True
    if isinstance(value, str):
        lowered = value.strip().lower()
        if lowered in {"true", "on", "1", "yes", "detected", "motion", "active"}:
            return True
        if lowered in {"false", "off", "0", "no", "clear", "inactive"}:
            return False
    if isinstance(value, dict):
        # Hue adds additional metadata levels depending on firmware version.
        for key in ("motion", "value", "status"):
            if key in value:
                coerced = _coerce_motion_state(value[key])
                if coerced is not None:
                    return coerced
        for candidate in value.values():
            coerced = _coerce_motion_state(candidate)
            if coerced is not None:
                return coerced
    return None


def extract_motion_state(entry: Dict[str, Any]) -> Optional[bool]:
    """Return the current motion state from an event payload."""

    motion = entry.get("motion")
    if not isinstance(motion, dict):
        return None
    report = motion.get("motion_report")
    if isinstance(report, dict):
        state = report.get("motion")
        coerced = _coerce_motion_state(state)
        if coerced is not None:
            return coerced
    # Some firmware revisions report the value directly on the resource.
    state = motion.get("motion")
    return _coerce_motion_state(state)


class LoxoneSender:
    """Helper that triggers virtual inputs on the Loxone Miniserver."""

    def __init__(self, settings: LoxoneSettings | None = None) -> None:
        self._lock = threading.Lock()
        self._base_url = ""
        self._method = "POST"
        if settings:
            self.update(settings)

    def update(self, settings: LoxoneSettings) -> None:
        base_url = (settings.base_url or "").strip()
        base_url = base_url.rstrip("/")
        method = (settings.event_method or "POST").strip().upper()
        if method not in {"GET", "POST"}:
            method = "POST"
        with self._lock:
            self._base_url = base_url
            self._method = method

    @property
    def available(self) -> bool:
        with self._lock:
            return bool(self._base_url)

    def send(self, virtual_input: str, value: str) -> None:
        with self._lock:
            base_url = self._base_url
            method = self._method
        if not base_url:
            raise RuntimeError("Keine Loxone-Basis-URL konfiguriert.")

        safe_input = quote(virtual_input, safe="")
        safe_value = quote(value, safe="")
        url = f"{base_url}/dev/sps/io/{safe_input}/{safe_value}"

        try:
            if method == "POST":
                response = requests.post(url, timeout=5)
            else:
                response = requests.get(url, timeout=5)
            response.raise_for_status()
        except requests_exc.RequestException as exc:
            raise RuntimeError(f"Anfrage an Loxone fehlgeschlagen: {exc}") from exc


class BridgeWorker(threading.Thread):
    """Per-bridge worker that listens for Hue events and forwards them."""

    def __init__(
        self,
        bridge_config: HueBridgeConfig,
        sender_provider: Callable[[], LoxoneSender],
        global_stop: threading.Event,
        state_store: EventStateStore,
    ) -> None:
        super().__init__(daemon=True, name=f"hue-forwarder-{bridge_config.id}")
        self._bridge_config = bridge_config
        self._client = HueBridgeClient(bridge_config)
        self._sender_provider = sender_provider
        self._global_stop = global_stop
        self._stop_event = threading.Event()
        self._lock = threading.Lock()
        self._lookup: Dict[Tuple[str, str], Tuple[VirtualInputConfig, ...]] = {}
        self._state_store = state_store
        self._state_lock = threading.Lock()
        self._last_motion_states: Dict[str, Optional[bool]] = {}

    @property
    def bridge_id(self) -> str:
        return self._bridge_config.id

    def matches(self, config: HueBridgeConfig) -> bool:
        return (
            self._bridge_config.bridge_ip == config.bridge_ip
            and self._bridge_config.application_key == config.application_key
            and self._bridge_config.use_https == config.use_https
            and self._bridge_config.verify_tls == config.verify_tls
        )

    def update_bridge(self, config: HueBridgeConfig) -> None:
        self._bridge_config = config
        self._client = HueBridgeClient(config)

    def update_mappings(self, mappings: Iterable[VirtualInputConfig]) -> None:
        with self._lock:
            grouped: Dict[Tuple[str, str], List[VirtualInputConfig]] = defaultdict(list)
            for mapping in mappings:
                key = (mapping.resource_id, mapping.resource_type)
                grouped[key].append(mapping)
            self._lookup = {key: tuple(items) for key, items in grouped.items()}
        with self._state_lock:
            active_motion_ids = {resource_id for (resource_id, rtype) in self._lookup if rtype == "motion"}
            stale_ids = [rid for rid in self._last_motion_states if rid not in active_motion_ids]
            for rid in stale_ids:
                del self._last_motion_states[rid]

    def stop(self) -> None:
        self._stop_event.set()

    def _active(self) -> bool:
        with self._lock:
            return bool(self._lookup)

    def run(self) -> None:  # pragma: no cover - long running thread
        backoff = 5.0
        poll_thread = threading.Thread(
            target=self._poll_loop,
            name=f"hue-forwarder-poll-{self._bridge_config.id}",
            daemon=True,
        )
        poll_thread.start()
        while not self._stop_event.is_set() and not self._global_stop.is_set():
            if not self._active():
                if self._stop_event.wait(timeout=5.0) or self._global_stop.is_set():
                    break
                continue

            sender = self._sender_provider()
            if not sender.available:
                if self._stop_event.wait(timeout=5.0) or self._global_stop.is_set():
                    break
                continue

            try:
                for payload in self._client.iter_events():
                    if self._stop_event.is_set() or self._global_stop.is_set():
                        poll_thread.join(timeout=5.0)
                        return
                    self._handle_payload(payload, sender)
            except HueBridgeError as exc:
                _log(
                    f"Event-Stream für Bridge '{self._bridge_config.id}' unterbrochen: {exc}"
                )
                if self._stop_event.wait(timeout=backoff) or self._global_stop.is_set():
                    break
                backoff = min(backoff * 2, 60.0)
            else:
                backoff = 5.0
        poll_thread.join(timeout=5.0)

    def _handle_payload(self, payload: Dict[str, object], sender: LoxoneSender) -> None:
        data = payload.get("data")
        if not isinstance(data, list):
            return

        with self._lock:
            lookup = self._lookup

        for entry in data:
            if not isinstance(entry, dict):
                continue
            rid = entry.get("id")
            rtype = entry.get("type")
            if not isinstance(rid, str) or not isinstance(rtype, str):
                continue
            key = (rid, rtype)
            mappings = lookup.get(key)
            if not mappings:
                continue
            for mapping in mappings:
                try:
                    self._dispatch_event(entry, mapping, sender)
                except RuntimeError as exc:
                    _log(
                        f"Weiterleitung für Bridge '{self._bridge_config.id}' fehlgeschlagen: {exc}"
                    )

    def _dispatch_event(
        self,
        entry: Dict[str, object],
        mapping: VirtualInputConfig,
        sender: LoxoneSender,
    ) -> None:
        rtype = mapping.resource_type
        if rtype == "button":
            self._handle_button_event(entry, mapping, sender)
        elif rtype == "motion":
            self._handle_motion_event(entry, mapping, sender)

    def _update_motion_state(self, resource_id: str, state: Optional[bool]) -> None:
        with self._state_lock:
            self._last_motion_states[resource_id] = state

    def _record_event(
        self,
        mapping: VirtualInputConfig,
        state: str,
        value: Optional[str],
        *,
        event_type: Optional[str] = None,
        trigger: Optional[str] = None,
        delivered: bool = True,
        extra: Optional[Dict[str, Any]] = None,
    ) -> None:
        if not self._state_store:
            return
        kind = event_type or mapping.resource_type
        self._state_store.record(
            mapping,
            event_type=kind,
            state=state,
            value=value,
            trigger=trigger,
            delivered=delivered,
            extra=extra,
        )

    def _handle_button_event(
        self,
        entry: Dict[str, object],
        mapping: VirtualInputConfig,
        sender: LoxoneSender,
    ) -> None:
        button = entry.get("button")
        if not isinstance(button, dict):
            return
        report = button.get("button_report")
        if not isinstance(report, dict):
            return
        event_name = report.get("event")
        if not isinstance(event_name, str):
            return
        if mapping.trigger and mapping.trigger != event_name:
            return
        sender.send(mapping.virtual_input, mapping.active_value)
        self._record_event(
            mapping,
            "active",
            mapping.active_value,
            event_type="button",
            trigger=event_name,
        )
        if mapping.reset_value is not None and mapping.reset_delay_ms > 0:
            timer = threading.Timer(
                mapping.reset_delay_ms / 1000.0,
                self._send_reset,
                args=(mapping, sender),
            )
            timer.daemon = True
            timer.start()

    def _handle_motion_event(
        self,
        entry: Dict[str, object],
        mapping: VirtualInputConfig,
        sender: LoxoneSender,
    ) -> None:
        state = extract_motion_state(entry)
        if state is True:
            sender.send(mapping.virtual_input, mapping.active_value)
            self._update_motion_state(mapping.resource_id, True)
            self._record_event(
                mapping,
                "active",
                mapping.active_value,
                event_type="motion",
                extra={"motion_state": True},
            )
        elif state is False:
            delivered = False
            value = mapping.inactive_value
            if value is not None:
                sender.send(mapping.virtual_input, value)
                delivered = True
            self._update_motion_state(mapping.resource_id, False)
            self._record_event(
                mapping,
                "inactive",
                value,
                event_type="motion",
                delivered=delivered,
                extra={"motion_state": False},
            )

    def _send_reset(self, mapping: VirtualInputConfig, sender: LoxoneSender) -> None:
        try:
            value = mapping.reset_value or "0"
            sender.send(mapping.virtual_input, value)
            self._record_event(
                mapping,
                "reset",
                value,
                event_type=mapping.resource_type,
                trigger="reset",
            )
        except RuntimeError as exc:
            _log(
                f"Reset-Weiterleitung für Bridge '{self._bridge_config.id}' fehlgeschlagen: {exc}"
            )

    def _poll_loop(self) -> None:
        while not self._stop_event.is_set() and not self._global_stop.is_set():
            if not self._active():
                if self._stop_event.wait(timeout=5.0) or self._global_stop.is_set():
                    break
                continue

            sender = self._sender_provider()
            if not sender.available:
                if self._stop_event.wait(timeout=5.0) or self._global_stop.is_set():
                    break
                continue

            self._poll_motion_states(sender)

            for _ in range(10):
                if self._stop_event.wait(timeout=1.0) or self._global_stop.is_set():
                    return

    def _poll_motion_states(self, sender: LoxoneSender) -> None:
        try:
            resources = list(self._client.get_motion_sensors())
        except HueBridgeError as exc:
            _log(
                f"Bewegungsmelder konnten nicht abgefragt werden (Bridge '{self._bridge_config.id}'): {exc}"
            )
            return

        with self._lock:
            lookup = self._lookup

        for resource in resources:
            key = (resource.id, "motion")
            mappings = lookup.get(key)
            if not mappings:
                continue

            state = extract_motion_state(resource.data)
            if state is None:
                continue

            with self._state_lock:
                previous = self._last_motion_states.get(resource.id)
                self._last_motion_states[resource.id] = state

            if previous is None and state is False:
                continue
            if previous is not None and previous is state:
                continue

            for mapping in mappings:
                if state is True:
                    sender.send(mapping.virtual_input, mapping.active_value)
                    self._record_event(
                        mapping,
                        "active",
                        mapping.active_value,
                        event_type="motion",
                        extra={"motion_state": True},
                    )
                else:
                    delivered = False
                    value = mapping.inactive_value
                    if value is not None:
                        sender.send(mapping.virtual_input, value)
                        delivered = True
                    self._record_event(
                        mapping,
                        "inactive",
                        value,
                        event_type="motion",
                        delivered=delivered,
                        extra={"motion_state": False},
                    )


class HueEventForwarder:
    """Coordinates bridge workers and keeps them in sync with the config."""

    def __init__(self, reload_interval: float = 30.0) -> None:
        self._reload_interval = reload_interval
        self._global_stop = threading.Event()
        self._sender = LoxoneSender()
        self._sender_lock = threading.Lock()
        self._workers: Dict[str, BridgeWorker] = {}
        self._state_store = EventStateStore(runtime_state_path())

    def _get_sender(self) -> LoxoneSender:
        with self._sender_lock:
            return self._sender

    def stop(self) -> None:
        self._global_stop.set()
        for worker in list(self._workers.values()):
            worker.stop()
            worker.join(timeout=5.0)
        self._workers.clear()

    def _sync_workers(self, config: PluginConfig) -> None:
        with self._sender_lock:
            self._sender.update(config.loxone)

        mappings_by_bridge: Dict[str, List[VirtualInputConfig]] = defaultdict(list)
        for mapping in config.virtual_inputs:
            mappings_by_bridge[mapping.bridge_id].append(mapping)

        # Remove workers for bridges that no longer have mappings
        for bridge_id, worker in list(self._workers.items()):
            if bridge_id not in mappings_by_bridge:
                worker.stop()
                worker.join(timeout=5.0)
                del self._workers[bridge_id]

        for bridge in config.bridges:
            mappings = mappings_by_bridge.get(bridge.id)
            worker = self._workers.get(bridge.id)
            if not mappings:
                if worker:
                    worker.stop()
                    worker.join(timeout=5.0)
                    del self._workers[bridge.id]
                continue

            if worker and not worker.matches(bridge):
                worker.stop()
                worker.join(timeout=5.0)
                worker = None

            if not worker:
                worker = BridgeWorker(
                    bridge,
                    self._get_sender,
                    self._global_stop,
                    self._state_store,
                )
                worker.start()
                self._workers[bridge.id] = worker

            worker.update_bridge(bridge)
            worker.update_mappings(mappings)

    def run_forever(self) -> None:  # pragma: no cover - integration path
        _log("Starte Hue-Event-Forwarder")
        try:
            while not self._global_stop.is_set():
                try:
                    config = load_config()
                except ConfigError as exc:
                    _log(f"Konfiguration konnte nicht geladen werden: {exc}")
                else:
                    self._sync_workers(config)
                if self._global_stop.wait(timeout=self._reload_interval):
                    break
        finally:
            self.stop()


def load_event_state(path: str | Path | None = None) -> Dict[str, Any]:
    """Load the persisted event state file."""

    resolved = Path(path) if path is not None else runtime_state_path()
    try:
        raw = resolved.read_text(encoding="utf-8")
    except FileNotFoundError:
        return {"events": [], "states": {}}
    except OSError:  # pragma: no cover - unexpected I/O errors
        return {"events": [], "states": {}}

    try:
        data = json.loads(raw)
    except ValueError:
        return {"events": [], "states": {}}

    events = data.get("events")
    states = data.get("states")
    if not isinstance(events, list):
        events = []
    if not isinstance(states, dict):
        states = {}
    return {"events": events, "states": states}


def main() -> int:  # pragma: no cover - CLI wrapper
    forwarder = HueEventForwarder()
    try:
        forwarder.run_forever()
    except KeyboardInterrupt:
        forwarder.stop()
    return 0


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
