"""In-memory :class:`RunEvent` recorder used by the M22 eval suite.

The agent loop emits per-phase :class:`agent_service.observability.events.RunEvent`
documents through the injected
:class:`agent_service.observability.recorder.EventRecorder`. The 50-case
eval framework runs the loop offline, so we want a recorder that:

* implements the :class:`EventRecorder` :class:`Protocol` exactly, so it
  can be swapped in with no loop-side changes;
* keeps every event in memory in the order they were recorded so the
  rubric scorer can scan them; and
* exposes a stable :meth:`events` property so tests can pin the
  expected sequence without reaching into the internals.

We deliberately avoid re-using :class:`agent_service.observability.recorder.JsonlEventRecorder`
because the suite never wants to materialise a file -- the recorded
events are scoped to a single process and discarded after the rubric
pass.
"""

from __future__ import annotations

from typing import Final

from agent_service.observability.events import RunEvent
from agent_service.observability.recorder import EventRecorder


__all__ = ["RecordingEventRecorder"]


class RecordingEventRecorder:
    """Append-only in-memory event sink.

    Implements the :class:`EventRecorder` :class:`Protocol` so the agent
    loop can use it as a drop-in replacement for
    :class:`agent_service.observability.recorder.JsonlEventRecorder`.
    """

    __slots__ = ("_events",)

    def __init__(self) -> None:
        self._events: list[RunEvent] = []

    def record(self, event: RunEvent) -> None:
        """Persist *event* in append order."""
        self._events.append(event)

    @property
    def events(self) -> tuple[RunEvent, ...]:
        """Return every recorded event, in the order it was emitted."""
        return tuple(self._events)


# Re-export the protocol so callers importing the recorder also get the
# typing handle in one place.
_: Final[type] = EventRecorder  # noqa: F841 -- kept for downstream type checks
