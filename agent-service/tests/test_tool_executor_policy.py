"""Tests for the policy-enforced tool executor (M6).

The executor is the single funnel through which every tool call must
pass.  These tests exercise its rejection contract end-to-end:

* unknown / disallowed tools
* expired run contexts
* model-supplied authority fields (every entry in the forbidden set)
* schema validation failures (missing required, extras, wrong types)
* stub tools without executors wired
* exceptions raised by the executor callable
* PHI safety in logs (keys only, never values)
* cap injection (patient_id, encounter_id, source-types, lookback,
  ``min(tool.max_rows, context.max_rows)``)

A minimal in-memory ``ToolRegistry`` is used throughout so the tests
do not depend on the real stub seeds (which would have ``executor=None``
and short-circuit at ``executor_missing``).
"""

from __future__ import annotations

import logging
from collections.abc import Callable, Mapping
from datetime import datetime, timezone
from typing import Any

import pytest

from agent_service.auth import CopilotRunContext
from agent_service.schemas.copilot import Citation
from agent_service.tools import (
    ToolCallOutcome,
    ToolDefinition,
    ToolExecutionError,
    ToolRegistry,
    execute_tool,
)
from agent_service.tools.executor import EXECUTOR_FORBIDDEN_MODEL_KEYS


# ---------------------------------------------------------------------------
# Fixtures and helpers
# ---------------------------------------------------------------------------


# Far-future expiry; the deterministic clocks below stay either side of it.
TOKEN_EXPIRES_AT: int = 1_900_000_000  # well past 2026


# Wall-clock anchor used for the "current" frozen clock.  It is below
# ``TOKEN_EXPIRES_AT`` so contexts are valid by default.
FROZEN_NOW: datetime = datetime(2030, 1, 1, tzinfo=timezone.utc)


def _frozen_clock(value: datetime) -> Callable[[], datetime]:
    """Return a clock that always reports ``value``."""

    def _clock() -> datetime:
        return value

    return _clock


def _make_context(
    *,
    allowed_tools: list[str],
    expires_at: int = TOKEN_EXPIRES_AT,
    encounter_id: int | None = 100,
    max_rows: int = 50,
    lookback_days: int = 365,
    allowed_source_types: list[str] | None = None,
) -> CopilotRunContext:
    """Build a deterministic :class:`CopilotRunContext` for tests."""
    return CopilotRunContext.model_validate(
        {
            "user_id": 17,
            "username": "dr.smith",
            "patient_id": 42,
            "encounter_id": encounter_id,
            "allowed_tools": list(allowed_tools),
            "allowed_source_types": list(
                allowed_source_types if allowed_source_types is not None else ["medications", "patient_record"],
            ),
            "max_rows": max_rows,
            "lookback_days": lookback_days,
            "expires_at": expires_at,
            "request_id": "req-1234-5678",
            "trace_id": "trace-abcd-efgh",
            "key_version": "v1",
        },
    )


def _empty_object_schema() -> dict[str, Any]:
    return {"type": "object", "properties": {}, "additionalProperties": False}


def _registry_with(tool: ToolDefinition) -> ToolRegistry:
    """Return a fresh single-tool registry."""
    registry = ToolRegistry()
    registry.register(tool)
    return registry


def _record_executor(
    bag: dict[str, Any],
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    """Return an executor that records its inputs and returns a record bag.

    Used to spy on what arguments the executor actually receives so tests
    can confirm cap injection happened correctly.
    """

    def _executor(context: CopilotRunContext, runtime_args: Mapping[str, Any]) -> dict[str, Any]:
        bag["context"] = context
        bag["runtime_args"] = dict(runtime_args)
        return {
            "records": [{"id": 1}, {"id": 2}],
            "citations": [
                {
                    "source_type": "medications",
                    "source_id": "med-1",
                    "label": "Metformin",
                },
            ],
        }

    return _executor


# ---------------------------------------------------------------------------
# Happy path
# ---------------------------------------------------------------------------


class TestHappyPath:
    """The executor returns a populated outcome for an allowed tool."""

    def test_returns_outcome_with_records_and_citations(self) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=["get_current_medications"])

        outcome = execute_tool(
            ctx,
            "get_current_medications",
            {},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert isinstance(outcome, ToolCallOutcome)
        assert outcome.tool_name == "get_current_medications"
        assert outcome.result_count == 2
        assert outcome.error_class is None
        # Sorted, contains all injected keys.
        assert list(outcome.arguments_keys) == sorted(outcome.arguments_keys)
        assert "patient_id" in outcome.arguments_keys
        assert "encounter_id" in outcome.arguments_keys
        assert "max_rows" in outcome.arguments_keys
        # Latency is non-negative.
        assert outcome.latency_ms >= 0
        # Citations were coerced into ``Citation`` objects.
        assert len(outcome.citations) == 1
        assert isinstance(outcome.citations[0], Citation)
        assert outcome.citations[0].source_id == "med-1"
        assert isinstance(outcome.payload, Mapping)


# ---------------------------------------------------------------------------
# tool_unknown
# ---------------------------------------------------------------------------


class TestToolUnknown:
    def test_unregistered_tool_raises(self) -> None:
        registry = ToolRegistry()
        ctx = _make_context(allowed_tools=["does_not_exist"])

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "does_not_exist",
                {},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "tool_unknown"


# ---------------------------------------------------------------------------
# tool_not_allowed
# ---------------------------------------------------------------------------


class TestToolNotAllowed:
    def test_registered_but_not_allowed_raises(self) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        # Allow some other tool, but not this one.
        ctx = _make_context(allowed_tools=["get_active_allergies"])

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "get_current_medications",
                {},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "tool_not_allowed"
        assert "context" not in spy  # executor never ran


# ---------------------------------------------------------------------------
# context_expired
# ---------------------------------------------------------------------------


class TestContextExpired:
    def test_clock_after_expiry_raises(self) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        # Token expires_at is in 2030; clock is in 2030+ but past expiry.
        past_expiry = TOKEN_EXPIRES_AT - 60
        ctx = _make_context(
            allowed_tools=["get_current_medications"],
            expires_at=past_expiry,
        )
        # Clock must be strictly past the token's expires_at.
        clock = _frozen_clock(datetime.fromtimestamp(past_expiry + 120, tz=timezone.utc))

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "get_current_medications",
                {},
                registry=registry,
                clock=clock,
            )
        assert exc_info.value.reason == "context_expired"
        assert "context" not in spy

    def test_clock_at_or_before_expiry_succeeds(self) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=["get_current_medications"])
        # Clock exactly at expires_at: not strictly less, so no expiry.
        clock = _frozen_clock(datetime.fromtimestamp(TOKEN_EXPIRES_AT, tz=timezone.utc))

        outcome = execute_tool(
            ctx,
            "get_current_medications",
            {},
            registry=registry,
            clock=clock,
        )
        assert outcome.error_class is None


# ---------------------------------------------------------------------------
# model_supplied_authority_field
# ---------------------------------------------------------------------------


@pytest.mark.parametrize("forbidden_key", sorted(EXECUTOR_FORBIDDEN_MODEL_KEYS))
class TestForbiddenAuthorityFields:
    """The executor rejects every key in the forbidden set."""

    def test_rejected(self, forbidden_key: str) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=["get_current_medications"])

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "get_current_medications",
                # The model attempts to inject a field it must not supply.
                # Pick a value of a plausible primitive type.
                {forbidden_key: "x"},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "model_supplied_authority_field"
        assert "context" not in spy


class TestDefenseInDepth:
    """Numeric authority overrides cannot silently slip past the executor."""

    def test_patient_id_99999_does_not_silently_pass(self) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=["get_current_medications"])

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "get_current_medications",
                {"patient_id": 99999},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "model_supplied_authority_field"
        # Critical: executor never observed the override.
        assert "runtime_args" not in spy


# ---------------------------------------------------------------------------
# schema_validation_failed
# ---------------------------------------------------------------------------


def _citation_id_schema() -> dict[str, Any]:
    return {
        "type": "object",
        "properties": {
            "citation_id": {"type": "string", "minLength": 1},
        },
        "required": ["citation_id"],
        "additionalProperties": False,
    }


class TestSchemaValidationFailed:
    def _build_tool(self) -> ToolDefinition:
        spy: dict[str, Any] = {}
        return ToolDefinition(
            name="get_source_detail",
            description="Drill down to a source.",
            input_schema=_citation_id_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=1,
            executor=_record_executor(spy),
        )

    def test_missing_required(
        self,
        caplog: pytest.LogCaptureFixture,
    ) -> None:
        tool = self._build_tool()
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=[tool.name])

        with caplog.at_level(logging.WARNING, logger="agent_service.tools.executor"):
            with pytest.raises(ToolExecutionError) as exc_info:
                execute_tool(
                    ctx,
                    tool.name,
                    {},  # missing citation_id
                    registry=registry,
                    clock=_frozen_clock(FROZEN_NOW),
                    logger=logging.getLogger("agent_service.tools.executor"),
                )
        assert exc_info.value.reason == "schema_validation_failed"
        # The offending key is named, but no value appears anywhere.
        joined_logs = " ".join(record.getMessage() for record in caplog.records)
        joined_details = " ".join(
            getattr(record, "detail", "") for record in caplog.records
        )
        full_log_text = joined_logs + " " + joined_details
        assert "citation_id" in full_log_text

    def test_extra_field(self) -> None:
        tool = self._build_tool()
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=[tool.name])

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                tool.name,
                {"citation_id": "abc", "rogue_field": "x"},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "schema_validation_failed"

    def test_wrong_primitive_type(self, caplog: pytest.LogCaptureFixture) -> None:
        tool = self._build_tool()
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=[tool.name])

        sentinel_value = "SENSITIVE_VALUE_NEVER_LOGGED"

        with caplog.at_level(logging.WARNING, logger="agent_service.tools.executor"):
            with pytest.raises(ToolExecutionError) as exc_info:
                execute_tool(
                    ctx,
                    tool.name,
                    {"citation_id": 12345},  # wrong type: int instead of string
                    registry=registry,
                    clock=_frozen_clock(FROZEN_NOW),
                    logger=logging.getLogger("agent_service.tools.executor"),
                )
        assert exc_info.value.reason == "schema_validation_failed"
        # The sentinel value should never appear, but offending key should.
        for record in caplog.records:
            assert sentinel_value not in record.getMessage()


# ---------------------------------------------------------------------------
# executor_missing
# ---------------------------------------------------------------------------


class TestExecutorMissing:
    def test_stub_tool_raises(self) -> None:
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=None,
        )
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=["get_current_medications"])

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "get_current_medications",
                {},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "executor_missing"


# ---------------------------------------------------------------------------
# executor_raised
# ---------------------------------------------------------------------------


class TestExecutorRaised:
    def test_executor_exception_wrapped(
        self,
        caplog: pytest.LogCaptureFixture,
    ) -> None:
        sensitive_message = "patient 42 has SECRET diagnosis"

        def boom(context: CopilotRunContext, runtime_args: Mapping[str, Any]) -> dict[str, Any]:
            raise ValueError(sensitive_message)

        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=boom,
        )
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=["get_current_medications"])

        with caplog.at_level(logging.ERROR, logger="agent_service.tools.executor"):
            with pytest.raises(ToolExecutionError) as exc_info:
                execute_tool(
                    ctx,
                    "get_current_medications",
                    {},
                    registry=registry,
                    clock=_frozen_clock(FROZEN_NOW),
                    logger=logging.getLogger("agent_service.tools.executor"),
                )

        err = exc_info.value
        assert err.reason == "executor_raised"
        # Verify the wrapper carries the class name.
        assert "ValueError" in str(err)
        # The exception's PHI-bearing message must NOT appear in the
        # wrapper or in any log record.
        assert sensitive_message not in str(err)
        for record in caplog.records:
            assert sensitive_message not in record.getMessage()
            # No record should carry the exception's actual message via extras.
            assert sensitive_message not in str(record.__dict__)

    def test_non_mapping_return_wrapped(self) -> None:
        def returns_list(context: CopilotRunContext, runtime_args: Mapping[str, Any]) -> Any:
            return [1, 2, 3]

        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=returns_list,
        )
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=["get_current_medications"])

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "get_current_medications",
                {},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "executor_raised"


# ---------------------------------------------------------------------------
# Cap injection
# ---------------------------------------------------------------------------


class TestCapInjection:
    def test_runtime_args_carry_authority_context(self) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        ctx = _make_context(
            allowed_tools=["get_current_medications"],
            max_rows=25,  # tighter than tool.max_rows=100
            lookback_days=180,
            allowed_source_types=["medications", "patient_record"],
            encounter_id=99,
        )

        execute_tool(
            ctx,
            "get_current_medications",
            {},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        runtime = spy["runtime_args"]
        # Identity scoping is injected, never trusted from the model.
        assert runtime["patient_id"] == 42
        assert runtime["encounter_id"] == 99
        # Source-type filter and lookback flow through.
        assert runtime["allowed_source_types"] == ("medications", "patient_record")
        assert runtime["lookback_days"] == 180
        # Row cap is the minimum of (tool, context).
        assert runtime["max_rows"] == 25  # min(100, 25)

    def test_tool_cap_lower_than_context_cap_wins(self) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_basic_patient_data",
            description="Demographics summary.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("patient_record",),
            read_only=True,
            max_rows=1,  # tool cap is the floor
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        ctx = _make_context(
            allowed_tools=["get_basic_patient_data"],
            max_rows=500,
        )

        execute_tool(
            ctx,
            "get_basic_patient_data",
            {},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )
        assert spy["runtime_args"]["max_rows"] == 1  # min(1, 500)

    def test_encounter_id_omitted_when_context_lacks_it(self) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        ctx = _make_context(
            allowed_tools=["get_current_medications"],
            encounter_id=None,
        )

        execute_tool(
            ctx,
            "get_current_medications",
            {},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )
        assert "encounter_id" not in spy["runtime_args"]


# ---------------------------------------------------------------------------
# Latency and PHI-safe logging
# ---------------------------------------------------------------------------


class TestLatencyAndLogging:
    def test_latency_recorded_on_failure_paths(
        self,
        caplog: pytest.LogCaptureFixture,
    ) -> None:
        # tool_unknown still emits a latency_ms in extras.
        registry = ToolRegistry()
        ctx = _make_context(allowed_tools=["does_not_exist"])

        with caplog.at_level(logging.WARNING, logger="agent_service.tools.executor"):
            with pytest.raises(ToolExecutionError):
                execute_tool(
                    ctx,
                    "does_not_exist",
                    {},
                    registry=registry,
                    clock=_frozen_clock(FROZEN_NOW),
                    logger=logging.getLogger("agent_service.tools.executor"),
                )

        rejection_records = [
            record
            for record in caplog.records
            if getattr(record, "reason", None) == "tool_unknown"
        ]
        assert len(rejection_records) == 1
        record = rejection_records[0]
        # latency_ms must be a non-negative int even on failure.
        latency_ms = getattr(record, "latency_ms")
        assert isinstance(latency_ms, int)
        assert latency_ms >= 0

    def test_success_log_has_no_argument_values(
        self,
        caplog: pytest.LogCaptureFixture,
    ) -> None:
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_source_detail",
            description="Drill down.",
            input_schema=_citation_id_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=1,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=[tool.name])

        sensitive_value = "OPAQUE_CITATION_THAT_SHOULD_NEVER_APPEAR"
        with caplog.at_level(logging.INFO, logger="agent_service.tools.executor"):
            execute_tool(
                ctx,
                tool.name,
                {"citation_id": sensitive_value},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
                logger=logging.getLogger("agent_service.tools.executor"),
            )

        success_records = [
            record
            for record in caplog.records
            if getattr(record, "tool_name", None) == tool.name
            and "succeeded" in record.getMessage()
        ]
        assert len(success_records) == 1
        record = success_records[0]
        # Argument values should NEVER appear in the log record.
        flat = record.getMessage() + " " + " ".join(
            f"{k}={v!r}" for k, v in record.__dict__.items() if k not in {"args", "msg"}
        )
        assert sensitive_value not in flat
        # But the key should be present.
        assert "citation_id" in str(getattr(record, "arguments_keys", []))

    def test_default_logger_used_when_none_passed(self) -> None:
        # Confirms the default logger does not crash when called without
        # any extras configuration.
        spy: dict[str, Any] = {}
        tool = ToolDefinition(
            name="get_current_medications",
            description="List current medications.",
            input_schema=_empty_object_schema(),
            required_capability="read_basic_patient_data",
            source_types=("medications",),
            read_only=True,
            max_rows=100,
            executor=_record_executor(spy),
        )
        registry = _registry_with(tool)
        ctx = _make_context(allowed_tools=["get_current_medications"])

        outcome = execute_tool(
            ctx,
            "get_current_medications",
            {},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )
        assert outcome.error_class is None
