"""Policy-enforced tool executor for the sidecar chart copilot (M6).

This module is the **single funnel** through which every tool call must
pass.  The agent loop (M13) never invokes a registered tool directly --
it always calls :func:`execute_tool`, which:

1. Resolves the tool against an injected :class:`ToolRegistry`.
2. Verifies the tool is on the run context's ``allowed_tools`` allow-list.
3. Verifies the run context has not expired.
4. Rejects model-supplied authority fields (patient/encounter scoping,
   raw SQL, filesystem paths, identity claims).  These are injected from
   the verified ``CopilotRunContext`` -- the model is never allowed to
   name them.
5. Validates ``model_args`` against the tool's ``input_schema`` using a
   tiny pure-Python structural validator (the agent-service environment
   does not ship the ``jsonschema`` package).
6. Synthesises ``runtime_args`` by merging ``model_args`` with the
   authority context (``patient_id``, ``encounter_id``, source-type
   filters, lookback window, and the row-cap minimum of tool and
   context).  Injected values **always win** over model-supplied values.
7. Invokes the tool's executor and converts any raised exception into a
   structured ``executor_raised`` rejection -- the original message is
   never surfaced to callers (it may carry PHI).

Every rejection raises :class:`ToolExecutionError` with a typed
``reason`` so the agent loop and observability layers can pin failure
modes without inspecting message strings.

Logging is PHI-safe by construction: only **keys** of model arguments
are ever logged, never values; exception messages are logged at error
level for the exception class only, never the rendered message.
"""

from __future__ import annotations

import logging
import time
from collections.abc import Callable, Mapping
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Final, Literal

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.schemas.copilot import Citation
from agent_service.tools.registry import ToolNotFoundError, ToolRegistry

__all__ = [
    "EXECUTOR_FORBIDDEN_MODEL_KEYS",
    "ToolCallOutcome",
    "ToolExecutionError",
    "ToolExecutionReason",
    "execute_tool",
]


_LOGGER: Final[logging.Logger] = logging.getLogger("agent_service.tools.executor")


# Top-level keys the model is NEVER allowed to supply in ``model_args``.
# This is a strict superset of ``definition.FORBIDDEN_INPUT_KEYS`` because
# the executor enforces an additional layer of identity-claim defence:
# even if a tool definition is misconfigured to advertise ``user_id`` as
# a property, the executor still rejects model-supplied identity claims.
EXECUTOR_FORBIDDEN_MODEL_KEYS: Final[frozenset[str]] = frozenset(
    {
        "patient_id",
        "encounter_id",
        "document_id",
        "mrn",
        "path",
        "file_path",
        "sql",
        "query",
        "query_string",
        "user_id",
        "username",
    },
)


# Reason codes carried on every :class:`ToolExecutionError`.  ``Literal``
# is used so callers can match exhaustively.
ToolExecutionReason = Literal[
    "tool_unknown",
    "tool_not_allowed",
    "context_expired",
    "schema_validation_failed",
    "row_cap_exceeded",
    "lookback_cap_exceeded",
    "model_supplied_authority_field",
    "executor_missing",
    "executor_raised",
]


_PRIMITIVE_TYPE_VALIDATORS: Final[dict[str, tuple[type, ...]]] = {
    # ``bool`` is excluded from ``int``/``number`` because Python's
    # ``isinstance(True, int)`` is true; we handle this explicitly below.
    "string": (str,),
    "integer": (int,),
    "number": (int, float),
    "boolean": (bool,),
    "array": (list,),
    "object": (dict,),
    "null": (type(None),),
}


# ---------------------------------------------------------------------------
# Public types
# ---------------------------------------------------------------------------


@dataclass(frozen=True, slots=True)
class ToolCallOutcome:
    """Structured result of a single :func:`execute_tool` invocation.

    Attributes
    ----------
    tool_name
        Name of the registered tool that ran.
    arguments_keys
        Sorted tuple of the **keys** of the runtime arguments passed to
        the tool's executor.  Values are deliberately omitted to avoid
        PHI leakage in logs and observability sinks.
    result_count
        Number of rows in ``payload["records"]`` when the tool returns
        the canonical record-bag shape, or ``None`` otherwise.
    latency_ms
        Wall-clock duration of the executor call in milliseconds,
        measured with :func:`time.monotonic_ns`.  Populated even on
        executor failures so latency metrics never lie.
    error_class
        ``None`` on success.  On ``executor_raised``, the unqualified
        class name of the underlying exception.
    citations
        Tuple of :class:`Citation` objects extracted from
        ``payload["citations"]`` (empty when the tool does not emit
        citations).
    payload
        The executor's full structured return value.  Callers rebuild
        the ``ToolCallRecord`` wire entry from ``arguments_keys``,
        ``result_count``, ``latency_ms`` and ``error_class``.
    """

    tool_name: str
    arguments_keys: tuple[str, ...]
    result_count: int | None
    latency_ms: int
    error_class: str | None
    citations: tuple[Citation, ...]
    payload: Any


class ToolExecutionError(Exception):
    """Raised when :func:`execute_tool` rejects an invocation.

    The ``reason`` attribute is a typed discriminator so callers can
    branch on rejection class without parsing message strings.  Message
    strings are intentionally generic and never embed model-supplied
    values (only keys).
    """

    def __init__(self, reason: ToolExecutionReason, message: str) -> None:
        super().__init__(message)
        self.reason: ToolExecutionReason = reason


# ---------------------------------------------------------------------------
# Schema validation (pure-Python, no jsonschema dep)
# ---------------------------------------------------------------------------


def _validate_arguments_against_schema(
    args: Mapping[str, Any],
    schema: Mapping[str, Any],
) -> None:
    """Structurally validate ``args`` against ``schema``.

    Implements the subset of JSON Schema the registry produces:

    * ``type: object`` with optional ``properties``, ``required``,
      and ``additionalProperties: false`` (the registry default).
    * Per-property ``type`` is a single primitive name; we check
      ``isinstance`` and special-case ``bool``/``int`` so ``True`` is
      not silently accepted as an integer.

    Raises
    ------
    ToolExecutionError
        With reason ``schema_validation_failed`` and a message that
        names the offending **key** but never the value.
    """
    properties = schema.get("properties", {}) if isinstance(schema, Mapping) else {}
    if not isinstance(properties, Mapping):
        # Registry validation should have caught this at construction
        # time, but defend in depth.
        raise ToolExecutionError(
            "schema_validation_failed",
            "tool input_schema.properties must be an object",
        )

    required = schema.get("required", []) if isinstance(schema, Mapping) else []
    if not isinstance(required, list):
        raise ToolExecutionError(
            "schema_validation_failed",
            "tool input_schema.required must be a list",
        )

    additional_properties = schema.get("additionalProperties", True) if isinstance(schema, Mapping) else True

    # Required: every required key must be present in ``args``.
    for entry in required:
        if not isinstance(entry, str):
            continue
        if entry not in args:
            raise ToolExecutionError(
                "schema_validation_failed",
                f"missing required argument key: {entry!r}",
            )

    # Extras: when ``additionalProperties`` is False (the registry
    # default), unknown keys are rejected.
    if additional_properties is False:
        for key in args:
            if key not in properties:
                raise ToolExecutionError(
                    "schema_validation_failed",
                    f"unknown argument key: {key!r}",
                )

    # Per-property type checks for keys that are present.
    for key, value in args.items():
        prop_schema = properties.get(key)
        if not isinstance(prop_schema, Mapping):
            continue

        expected = prop_schema.get("type")
        if not isinstance(expected, str):
            # Skip if no primitive ``type`` constraint -- we keep the
            # validator deliberately conservative.
            continue

        validators = _PRIMITIVE_TYPE_VALIDATORS.get(expected)
        if validators is None:
            continue

        # ``bool`` should never satisfy ``integer`` / ``number`` even
        # though ``isinstance(True, int)`` is true.
        if expected in {"integer", "number"} and isinstance(value, bool):
            raise ToolExecutionError(
                "schema_validation_failed",
                f"argument key {key!r} must be of type {expected!r}",
            )

        if not isinstance(value, validators):
            raise ToolExecutionError(
                "schema_validation_failed",
                f"argument key {key!r} must be of type {expected!r}",
            )


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _utc_now() -> datetime:
    """Return the current time as a timezone-aware UTC :class:`datetime`."""
    return datetime.now(tz=timezone.utc)


def _coerce_citations(payload: Any) -> tuple[Citation, ...]:
    """Coerce ``payload["citations"]`` into a tuple of :class:`Citation`.

    Tools may return ``Citation`` instances directly or already-validated
    dicts (the latter is convenient when the executor delegates to a
    Pydantic-using repository).  Raw dicts are validated through
    ``Citation.model_validate`` so any malformed entry surfaces as a
    pydantic ``ValidationError`` -- which the caller wraps as
    ``executor_raised``.
    """
    if not isinstance(payload, Mapping):
        return ()
    raw = payload.get("citations", [])
    if raw is None:
        return ()
    if not isinstance(raw, list):
        return ()

    coerced: list[Citation] = []
    for entry in raw:
        if isinstance(entry, Citation):
            coerced.append(entry)
        elif isinstance(entry, Mapping):
            coerced.append(Citation.model_validate(dict(entry)))
        else:
            # Skip unknown shapes; a tool emitting non-citation entries
            # in the citations list is a programming error but not one
            # the executor should hide.
            continue
    return tuple(coerced)


def _result_count(payload: Any) -> int | None:
    """Return the ``records`` count when ``payload`` follows the bag shape."""
    if not isinstance(payload, Mapping):
        return None
    records = payload.get("records")
    if isinstance(records, list):
        return len(records)
    return None


def _now_ms() -> int:
    """Return a monotonic millisecond timestamp for latency measurement."""
    return time.monotonic_ns() // 1_000_000


# ---------------------------------------------------------------------------
# Public entry point
# ---------------------------------------------------------------------------


def execute_tool(
    context: CopilotRunContext,
    tool_name: str,
    model_args: Mapping[str, Any],
    *,
    registry: ToolRegistry,
    clock: Callable[[], datetime] | None = None,
    logger: logging.Logger | None = None,
) -> ToolCallOutcome:
    """Execute ``tool_name`` after enforcing every executor-level policy.

    Checks run in this order; the first failure raises
    :class:`ToolExecutionError` and short-circuits execution:

    1. ``tool_unknown`` -- ``tool_name`` is not registered.
    2. ``tool_not_allowed`` -- ``tool_name`` is not in
       ``context.allowed_tools``.
    3. ``context_expired`` -- ``context.expires_at`` is in the past.
    4. ``model_supplied_authority_field`` -- ``model_args`` contains any
       key in :data:`EXECUTOR_FORBIDDEN_MODEL_KEYS`.
    5. ``schema_validation_failed`` -- ``model_args`` does not match the
       tool's ``input_schema``.
    6. ``executor_missing`` -- the tool is a stub (``executor is None``).
    7. ``executor_raised`` -- the executor callable raised any
       exception, OR returned a non-mapping value.

    On success, an outcome is built with sorted argument keys, a
    record count derived from ``payload["records"]`` when present, and
    citations extracted from ``payload["citations"]``.

    Logging is PHI-safe: only keys, never values; exception messages are
    not logged on ``executor_raised`` (only the class name).
    """
    log = logger if logger is not None else _LOGGER
    clock_fn = clock if clock is not None else _utc_now
    started_ms = _now_ms()

    # 1. tool_unknown
    try:
        tool = registry.get(tool_name)
    except ToolNotFoundError as exc:
        latency_ms = _now_ms() - started_ms
        log.warning(
            "tool execution rejected",
            extra={
                "trace_id": context.trace_id,
                "tool_name": tool_name,
                "reason": "tool_unknown",
                "latency_ms": latency_ms,
            },
        )
        raise ToolExecutionError("tool_unknown", f"unknown tool: {tool_name!r}") from exc

    # 2. tool_not_allowed
    if tool.name not in context.allowed_tools:
        latency_ms = _now_ms() - started_ms
        log.warning(
            "tool execution rejected",
            extra={
                "trace_id": context.trace_id,
                "tool_name": tool.name,
                "reason": "tool_not_allowed",
                "latency_ms": latency_ms,
            },
        )
        raise ToolExecutionError(
            "tool_not_allowed",
            f"tool not allowed by run context: {tool.name!r}",
        )

    # 3. context_expired
    now_dt = clock_fn()
    if not isinstance(now_dt, datetime):
        # Defensive: bad clock injection should be loud, not silent.
        raise ToolExecutionError(
            "context_expired",
            "injected clock did not return a datetime",
        )
    if now_dt.tzinfo is None:
        now_dt = now_dt.replace(tzinfo=timezone.utc)
    now_ts = int(now_dt.timestamp())
    if context.expires_at < now_ts:
        latency_ms = _now_ms() - started_ms
        log.warning(
            "tool execution rejected",
            extra={
                "trace_id": context.trace_id,
                "tool_name": tool.name,
                "reason": "context_expired",
                "latency_ms": latency_ms,
            },
        )
        raise ToolExecutionError(
            "context_expired",
            "run context has expired",
        )

    # 4. model_supplied_authority_field
    if not isinstance(model_args, Mapping):
        raise ToolExecutionError(
            "schema_validation_failed",
            "model_args must be a mapping",
        )
    forbidden_present = sorted(key for key in model_args if key in EXECUTOR_FORBIDDEN_MODEL_KEYS)
    if forbidden_present:
        latency_ms = _now_ms() - started_ms
        log.warning(
            "tool execution rejected",
            extra={
                "trace_id": context.trace_id,
                "tool_name": tool.name,
                "reason": "model_supplied_authority_field",
                "forbidden_keys": forbidden_present,
                "latency_ms": latency_ms,
            },
        )
        raise ToolExecutionError(
            "model_supplied_authority_field",
            f"model_args may not supply authority fields: {forbidden_present!r}",
        )

    # 5. schema_validation_failed
    try:
        _validate_arguments_against_schema(model_args, tool.input_schema)
    except ToolExecutionError as exc:
        latency_ms = _now_ms() - started_ms
        log.warning(
            "tool execution rejected",
            extra={
                "trace_id": context.trace_id,
                "tool_name": tool.name,
                "reason": exc.reason,
                "detail": str(exc),
                "latency_ms": latency_ms,
            },
        )
        raise

    # 6. Cap injection -- merge ``model_args`` with authority-context fields.
    injected: dict[str, Any] = {
        "patient_id": context.patient_id,
        "allowed_source_types": tuple(context.allowed_source_types),
        "lookback_days": context.lookback_days,
        "max_rows": min(tool.max_rows, context.max_rows),
    }
    if context.encounter_id is not None:
        injected["encounter_id"] = context.encounter_id
    runtime_args: dict[str, Any] = {**dict(model_args), **injected}

    # ``row_cap_exceeded`` and ``lookback_cap_exceeded`` are reserved for
    # tool implementations to surface post-hoc overflow.  The executor
    # itself does not raise them.

    # 7. executor_missing
    if tool.executor is None:
        latency_ms = _now_ms() - started_ms
        log.warning(
            "tool execution rejected",
            extra={
                "trace_id": context.trace_id,
                "tool_name": tool.name,
                "reason": "executor_missing",
                "latency_ms": latency_ms,
            },
        )
        raise ToolExecutionError(
            "executor_missing",
            f"tool {tool.name!r} has no executor wired",
        )

    # 8. Invoke the executor.
    arguments_keys = tuple(sorted(runtime_args.keys()))
    call_started_ms = _now_ms()
    try:
        payload = tool.executor(context, runtime_args)
    except Exception as exc:  # noqa: BLE001 -- intentional broad catch
        latency_ms = _now_ms() - call_started_ms
        error_class = type(exc).__name__
        log.error(
            "tool execution raised",
            extra={
                "trace_id": context.trace_id,
                "tool_name": tool.name,
                "reason": "executor_raised",
                "error_class": error_class,
                "latency_ms": latency_ms,
                "arguments_keys": list(arguments_keys),
            },
        )
        raise ToolExecutionError(
            "executor_raised",
            f"tool {tool.name!r} executor raised {error_class}",
        ) from exc

    latency_ms = _now_ms() - call_started_ms

    # Contract: executors must return a mapping describing the result
    # bag.  A non-mapping return is a programming error in the tool
    # implementation; surface it as ``executor_raised`` so the agent
    # loop's failure handling branch is exercised.
    if not isinstance(payload, Mapping):
        log.warning(
            "tool execution returned non-mapping payload",
            extra={
                "trace_id": context.trace_id,
                "tool_name": tool.name,
                "reason": "executor_raised",
                "error_class": "TypeError",
                "latency_ms": latency_ms,
                "arguments_keys": list(arguments_keys),
            },
        )
        raise ToolExecutionError(
            "executor_raised",
            f"tool {tool.name!r} executor returned non-mapping payload",
        )

    citations = _coerce_citations(payload)
    result_count = _result_count(payload)

    log.info(
        "tool execution succeeded",
        extra={
            "trace_id": context.trace_id,
            "tool_name": tool.name,
            "result_count": result_count,
            "latency_ms": latency_ms,
            "arguments_keys": list(arguments_keys),
        },
    )

    return ToolCallOutcome(
        tool_name=tool.name,
        arguments_keys=arguments_keys,
        result_count=result_count,
        latency_ms=latency_ms,
        error_class=None,
        citations=citations,
        payload=payload,
    )
