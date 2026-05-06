"""Append-only sinks for :class:`RunEvent` documents (M16).

Two implementations are shipped:

* :class:`JsonlEventRecorder` -- one event per line in a ``.jsonl`` file.
  This is the production default.  Concurrent appends within a process
  are guarded by a ``threading.Lock``; cross-process concurrency relies
  on the OS atomicity guarantee for ``O_APPEND`` writes below
  ``PIPE_BUF`` (well above our event size).
* :class:`NullEventRecorder` -- no-op sink for tests that exercise the
  agent loop without caring about observability.

Callers depend on the :class:`EventRecorder` protocol so the backend
can be swapped without modifying the loop.
"""

from __future__ import annotations

import json
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Protocol, runtime_checkable

from agent_service.observability.events import RunEvent


def _parse_iso8601(value: str) -> datetime:
    """Parse an ISO-8601 timestamp; default to UTC if no zone is set.

    Mirrors :func:`agent_service.observability.storage._parse_iso8601` so
    the run-record and event sinks accept the same wire format.
    """
    parsed = datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed


# ---------------------------------------------------------------------------
# Protocol
# ---------------------------------------------------------------------------


@runtime_checkable
class EventRecorder(Protocol):
    """Append-only sink for :class:`RunEvent` documents.

    Implementations must be safe under concurrent ``record`` calls from
    multiple threads within a single process.
    """

    def record(self, event: RunEvent) -> None:
        """Persist a single event."""
        ...


# ---------------------------------------------------------------------------
# JSONL sink (default)
# ---------------------------------------------------------------------------


class JsonlEventRecorder:
    """Append-only JSONL backend mirroring :class:`JSONLStorage`.

    Events are serialised with the same ``separators=(",", ":")`` /
    ISO-8601 timestamp conventions as :class:`RunRecord` so a single set
    of tools (``jq``, ``grep``) works over both files.
    """

    def __init__(self, *, path: str | Path) -> None:
        self._path: Path = Path(path)
        self._lock: threading.Lock = threading.Lock()
        # Create the parent directory eagerly so the first ``record``
        # call does not race with directory creation.  The file itself
        # is created lazily on first append; reading from a never-
        # written sink is a deliberate no-op upstream.
        self._path.parent.mkdir(parents=True, exist_ok=True)

    @property
    def path(self) -> Path:
        """Filesystem path to the JSONL file."""
        return self._path

    def record(self, event: RunEvent) -> None:
        """Append one JSON-encoded event terminated with ``\\n``."""
        payload = event.model_dump()
        # Pydantic returns ``datetime`` objects unchanged; JSON-encode
        # them as ISO-8601 strings so the file remains stable across
        # readers (the run-record store uses the same convention).
        payload["occurred_at"] = event.occurred_at.isoformat()
        line = json.dumps(payload, separators=(",", ":"))
        with self._lock:
            with self._path.open("a", encoding="utf-8") as fh:
                fh.write(line)
                fh.write("\n")

    def load_all(self) -> list[RunEvent]:
        """Return every event currently in the file, in append order.

        Provided primarily for tests and ad-hoc inspection; the
        production code path only writes.  Blank lines are skipped;
        malformed JSON raises immediately so corruption is surfaced.
        """
        if not self._path.is_file():
            return []
        events: list[RunEvent] = []
        with self._path.open("r", encoding="utf-8") as fh:
            for lineno, raw in enumerate(fh, start=1):
                stripped = raw.strip()
                if not stripped:
                    continue
                try:
                    payload = json.loads(stripped)
                except json.JSONDecodeError as exc:
                    raise ValueError(
                        f"Invalid JSON in {self._path} on line {lineno}: {exc}",
                    ) from exc
                # ``RunEvent`` runs in ``strict=True`` mode, which forbids
                # the implicit string-to-datetime coercion Pydantic v2
                # otherwise allows.  The ``record`` path serialises
                # ``occurred_at`` as an ISO string, so we re-parse it
                # back into a ``datetime`` before validating.
                if isinstance(payload, dict) and isinstance(
                    payload.get("occurred_at"), str
                ):
                    payload["occurred_at"] = _parse_iso8601(
                        payload["occurred_at"],
                    )
                events.append(RunEvent.model_validate(payload))
        return events


# ---------------------------------------------------------------------------
# Null sink
# ---------------------------------------------------------------------------


class NullEventRecorder:
    """No-op recorder for unit tests that don't care about events."""

    def record(self, event: RunEvent) -> None:
        """Drop the event on the floor."""
        # Touch the argument so static analysers do not flag it as unused.
        del event


__all__ = [
    "EventRecorder",
    "JsonlEventRecorder",
    "NullEventRecorder",
]
