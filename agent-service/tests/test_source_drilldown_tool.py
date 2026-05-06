"""Tests for the M11 source-drilldown tool (``get_source_detail``).

Covers the contract documented in ``Clinical Co-Pilot Migration to Python
Sidecar.md`` step M11:

* ``source_drilldown_tool_registry(repository)`` returns a fresh registry
  with exactly one tool, ``get_source_detail``.
* The tool's input schema does not advertise any forbidden authority
  field (no ``patient_id`` / ``encounter_id`` / SQL / filesystem keys).
* Happy path: the repository returns a typed
  :class:`EvidenceSourceDetail`, the executor yields a record bag with
  one record, one citation, and zero warnings.
* Body truncation: when the repository returns a body longer than
  :data:`SOURCE_DETAIL_BODY_MAX_CHARS`, the executor truncates the body
  in-place and surfaces a warning.  The accompanying citation snippet
  also reflects the truncation.
* Malformed ``source_id`` (wrong segment count) is rejected by the M6
  executor's schema-validation pass (``schema_validation_failed``).
* Disallowed source-type per-call: the parsed ``source_type`` is checked
  against ``context.allowed_source_types`` -- mismatches yield empty
  records with a typed warning.
* Wrong-patient / unknown source: M9 returns ``None``, the executor
  surfaces an empty record bag with a typed warning.
* Round-trip through ``execute_tool``: outcome carries citations and
  ``arguments_keys`` never exposes PHI.
"""

from __future__ import annotations

from collections.abc import Callable
from datetime import datetime, timezone
from typing import Any
from unittest.mock import MagicMock

import pytest

from agent_service.auth import CopilotRunContext
from agent_service.repository import OpenEmrReadRepository
from agent_service.schemas.evidence import EvidenceSourceDetail, EvidenceSourceType
from agent_service.tools import (
    SOURCE_DETAIL_BODY_MAX_CHARS,
    SOURCE_DETAIL_TOOL_NAME,
    ToolExecutionError,
    ToolRegistry,
    execute_tool,
    make_source_detail_tool,
    source_drilldown_tool_registry,
)


# ---------------------------------------------------------------------------
# Fixtures and helpers
# ---------------------------------------------------------------------------


# Far-future expiry so the executor's deterministic clock always sits before
# the token deadline.
TOKEN_EXPIRES_AT: int = 1_900_000_000

# Anchor "now" for deterministic clock injection.
FROZEN_NOW: datetime = datetime(2030, 1, 1, tzinfo=timezone.utc)

PATIENT_ID = 42

# Canonical source ID used across the happy-path tests.  The format is
# the same one the PHP normaliser emits and the M9 repository round-trips.
HAPPY_SOURCE_ID = "problem:lists:778"


def _frozen_clock(value: datetime) -> Callable[[], datetime]:
    """Return a deterministic clock for the executor."""

    def _clock() -> datetime:
        return value

    return _clock


def _make_context(
    *,
    allowed_tools: list[str] | None = None,
    allowed_source_types: list[str] | None = None,
    trace_id: str = "trace-source-drilldown",
    expires_at: int = TOKEN_EXPIRES_AT,
) -> CopilotRunContext:
    """Build a :class:`CopilotRunContext` covering the source-drilldown tool."""
    return CopilotRunContext.model_validate(
        {
            "user_id": 17,
            "username": "dr.smith",
            "patient_id": PATIENT_ID,
            "encounter_id": 100,
            "allowed_tools": list(
                allowed_tools
                if allowed_tools is not None
                else [SOURCE_DETAIL_TOOL_NAME],
            ),
            "allowed_source_types": list(
                allowed_source_types
                if allowed_source_types is not None
                else [t.value for t in EvidenceSourceType],
            ),
            "max_rows": 50,
            "lookback_days": 365,
            "expires_at": expires_at,
            "request_id": "req-source-drilldown",
            "trace_id": trace_id,
            "key_version": "v1",
        },
    )


def _mock_repository(
    return_value: EvidenceSourceDetail | None,
) -> MagicMock:
    """Build a ``MagicMock`` standing in for :class:`OpenEmrReadRepository`.

    ``spec`` is used so attempts to call methods other than
    ``get_source_detail`` fail loudly.
    """
    repo = MagicMock(spec=OpenEmrReadRepository)
    repo.get_source_detail.return_value = return_value
    return repo


def _make_detail(
    *,
    source_type: EvidenceSourceType = EvidenceSourceType.PROBLEM,
    source_id: str = HAPPY_SOURCE_ID,
    body: str = "Type: medical_problem | Long-standing",
    label: str = "Hypertension",
) -> EvidenceSourceDetail:
    """Build a typical :class:`EvidenceSourceDetail` for tests."""
    return EvidenceSourceDetail(
        source_id=source_id,
        source_type=source_type,
        label=label,
        body=body,
        occurred_at=datetime(2024, 5, 1, tzinfo=timezone.utc),
    )


# ---------------------------------------------------------------------------
# Registry shape
# ---------------------------------------------------------------------------


class TestRegistryShape:
    """The factory exposes exactly one tool, named ``get_source_detail``."""

    def test_returns_single_tool_registry(self) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        assert isinstance(registry, ToolRegistry)
        assert registry.list_names() == [SOURCE_DETAIL_TOOL_NAME]
        assert len(registry) == 1

    def test_each_call_returns_fresh_registry(self) -> None:
        repo = _mock_repository(None)
        first = source_drilldown_tool_registry(repo)
        second = source_drilldown_tool_registry(repo)
        assert first is not second

    def test_tool_metadata_is_correct(self) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        tool = registry.get(SOURCE_DETAIL_TOOL_NAME)
        assert tool.read_only is True
        assert tool.max_rows == 1
        assert tool.required_capability == "read_source_detail"
        assert tool.executor is not None

    def test_input_schema_has_no_forbidden_keys(self) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        tool = registry.get(SOURCE_DETAIL_TOOL_NAME)
        properties = tool.input_schema.get("properties", {})
        for forbidden in (
            "patient_id",
            "encounter_id",
            "document_id",
            "mrn",
            "path",
            "file_path",
            "sql",
            "query",
            "query_string",
        ):
            assert forbidden not in properties, (
                f"get_source_detail must not advertise {forbidden!r}"
            )

    def test_input_schema_requires_source_id_only(self) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        tool = registry.get(SOURCE_DETAIL_TOOL_NAME)
        schema = tool.input_schema
        assert schema["type"] == "object"
        assert schema["additionalProperties"] is False
        assert schema["required"] == ["source_id"]
        properties = schema["properties"]
        assert "source_id" in properties
        assert properties["source_id"]["type"] == "string"


# ---------------------------------------------------------------------------
# Happy path
# ---------------------------------------------------------------------------


class TestHappyPath:
    """When the repository returns a detail, the executor surfaces it."""

    def test_returns_record_citation_warnings_bag(self) -> None:
        detail = _make_detail()
        repo = _mock_repository(detail)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": HAPPY_SOURCE_ID},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.tool_name == SOURCE_DETAIL_TOOL_NAME
        assert outcome.error_class is None
        payload = outcome.payload
        assert isinstance(payload, dict)
        # Records: a single dump of the EvidenceSourceDetail.
        records = payload["records"]
        assert isinstance(records, list) and len(records) == 1
        record = records[0]
        assert record["source_id"] == HAPPY_SOURCE_ID
        assert record["source_type"] == EvidenceSourceType.PROBLEM.value
        assert record["label"] == "Hypertension"
        # No truncation: body matches the original.
        assert record["body"] == detail.body
        # Citations: one Citation pointing at the same source.
        assert len(outcome.citations) == 1
        citation = outcome.citations[0]
        assert citation.source_id == HAPPY_SOURCE_ID
        assert citation.source_type == EvidenceSourceType.PROBLEM.value
        assert citation.label == "Hypertension"
        # No warnings on the happy path.
        assert payload["warnings"] == []

    def test_repository_invoked_with_keyword_args(self) -> None:
        """The executor calls the repo with kw-only ``context`` and ``source_id``.

        M9's signature is ``get_source_detail(*, context, source_id)`` --
        the cross-patient guard relies on the repository receiving the
        same context the executor was invoked with.
        """
        detail = _make_detail()
        repo = _mock_repository(detail)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": HAPPY_SOURCE_ID},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        repo.get_source_detail.assert_called_once_with(
            context=ctx,
            source_id=HAPPY_SOURCE_ID,
        )

    def test_arguments_keys_does_not_leak_phi(self) -> None:
        """``arguments_keys`` records keys only -- never values."""
        detail = _make_detail()
        repo = _mock_repository(detail)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": HAPPY_SOURCE_ID},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        # The executor injects authority context keys; the model-supplied
        # ``source_id`` is the only model-facing key.
        assert "source_id" in outcome.arguments_keys
        # The literal source ID must NOT appear as a key.
        for key in outcome.arguments_keys:
            assert HAPPY_SOURCE_ID != key
        # Authority context (patient_id) is injected as a key.
        assert "patient_id" in outcome.arguments_keys


# ---------------------------------------------------------------------------
# Body truncation
# ---------------------------------------------------------------------------


class TestBodyTruncation:
    """Bodies longer than the cap are truncated with a typed warning."""

    def test_body_under_cap_is_preserved(self) -> None:
        body = "x" * (SOURCE_DETAIL_BODY_MAX_CHARS - 1)
        detail = _make_detail(body=body)
        repo = _mock_repository(detail)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": HAPPY_SOURCE_ID},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.payload["records"][0]["body"] == body
        assert outcome.payload["warnings"] == []

    def test_body_at_cap_is_preserved(self) -> None:
        body = "x" * SOURCE_DETAIL_BODY_MAX_CHARS
        detail = _make_detail(body=body)
        repo = _mock_repository(detail)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": HAPPY_SOURCE_ID},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.payload["records"][0]["body"] == body
        assert outcome.payload["warnings"] == []

    def test_body_over_cap_is_truncated_with_warning(self) -> None:
        body = "x" * (SOURCE_DETAIL_BODY_MAX_CHARS + 100)
        detail = _make_detail(body=body)
        repo = _mock_repository(detail)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": HAPPY_SOURCE_ID},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        truncated_body = outcome.payload["records"][0]["body"]
        assert len(truncated_body) == SOURCE_DETAIL_BODY_MAX_CHARS
        assert truncated_body == body[:SOURCE_DETAIL_BODY_MAX_CHARS]
        warnings = outcome.payload["warnings"]
        assert len(warnings) == 1
        assert "truncated" in warnings[0].lower()
        assert str(SOURCE_DETAIL_BODY_MAX_CHARS) in warnings[0]
        # Citation snippet reflects the truncation too.
        citation = outcome.citations[0]
        assert citation.snippet is not None
        assert len(citation.snippet) == SOURCE_DETAIL_BODY_MAX_CHARS


# ---------------------------------------------------------------------------
# Malformed source_id (M6 schema validation)
# ---------------------------------------------------------------------------


class TestMalformedSourceId:
    """Malformed ``source_id`` is rejected by the M6 schema validator.

    The structural validator in the executor only checks JSON Schema
    primitive ``type``, not regex ``pattern`` -- so malformed IDs reach
    the executor body, which surfaces them as an empty bag with a
    warning.  Schema-level rejections (wrong type / missing key) ARE
    enforced by the executor.
    """

    def test_missing_source_id_is_rejected_by_schema(self) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                SOURCE_DETAIL_TOOL_NAME,
                {},  # missing required key
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "schema_validation_failed"
        repo.get_source_detail.assert_not_called()

    def test_non_string_source_id_is_rejected_by_schema(self) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                SOURCE_DETAIL_TOOL_NAME,
                {"source_id": 123},  # wrong type
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "schema_validation_failed"
        repo.get_source_detail.assert_not_called()

    def test_extra_arguments_are_rejected(self) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                SOURCE_DETAIL_TOOL_NAME,
                {"source_id": HAPPY_SOURCE_ID, "extra": "value"},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "schema_validation_failed"

    def test_wrong_segment_count_returns_warning_bag(self) -> None:
        """Malformed source_ids that pass schema get a typed warning bag.

        The M6 structural validator does not enforce ``pattern``, so a
        string with the wrong segment count reaches the executor.  The
        executor parses the ID via the M9 helper and short-circuits with
        an empty bag.
        """
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": "no_colons_here"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )
        assert outcome.error_class is None
        assert outcome.payload["records"] == []
        assert outcome.citations == ()
        warnings = outcome.payload["warnings"]
        assert len(warnings) == 1
        assert "malformed" in warnings[0].lower()
        # Repository must not be called for malformed IDs.
        repo.get_source_detail.assert_not_called()


# ---------------------------------------------------------------------------
# Disallowed source-type per call
# ---------------------------------------------------------------------------


class TestDisallowedSourceType:
    """The executor enforces ``allowed_source_types`` per call."""

    def test_disallowed_source_type_returns_warning(self) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        # Run context only allows ``medication`` source rows; the model
        # asks for a ``document`` source.
        ctx = _make_context(allowed_source_types=["medication"])

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": "document:documents:99"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.error_class is None
        assert outcome.payload["records"] == []
        assert outcome.citations == ()
        warnings = outcome.payload["warnings"]
        assert len(warnings) == 1
        assert "not allowed" in warnings[0].lower()
        # Repository must NOT be called when the per-call source-type
        # check fails.
        repo.get_source_detail.assert_not_called()

    def test_allowed_source_type_reaches_repository(self) -> None:
        detail = _make_detail()
        repo = _mock_repository(detail)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context(allowed_source_types=["problem"])

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": HAPPY_SOURCE_ID},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.payload["records"][0]["source_id"] == HAPPY_SOURCE_ID
        repo.get_source_detail.assert_called_once()


# ---------------------------------------------------------------------------
# Wrong-patient / unknown source (M9 returns None)
# ---------------------------------------------------------------------------


class TestRepositoryReturnsNone:
    """When M9 returns ``None``, the executor surfaces a typed warning bag."""

    def test_unknown_source_returns_warning(self) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": HAPPY_SOURCE_ID},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.error_class is None
        assert outcome.payload["records"] == []
        assert outcome.citations == ()
        warnings = outcome.payload["warnings"]
        assert len(warnings) == 1
        # Warning conflates wrong-patient/unknown so the model cannot
        # distinguish; spot-check that one of the relevant fragments is
        # present.
        assert any(
            fragment in warnings[0].lower()
            for fragment in ("not found", "scope", "patient")
        )

    def test_wrong_patient_simulated_via_repository_none(self) -> None:
        """M9 enforces the cross-patient guard and returns ``None``.

        The tool layer simply trusts that ``None`` means "do not surface
        anything"; the test simulates this behaviour by returning
        ``None`` from the mocked repository.
        """
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            SOURCE_DETAIL_TOOL_NAME,
            {"source_id": "problem:lists:9999"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.payload["records"] == []
        repo.get_source_detail.assert_called_once_with(
            context=ctx,
            source_id="problem:lists:9999",
        )


# ---------------------------------------------------------------------------
# Forbidden authority fields (defense-in-depth via M6 executor)
# ---------------------------------------------------------------------------


class TestExecutorForbidsAuthorityFields:
    """The executor rejects every forbidden key for ``get_source_detail``."""

    @pytest.mark.parametrize(
        "forbidden_key",
        ["patient_id", "encounter_id", "document_id", "path", "file_path", "sql"],
    )
    def test_forbidden_keys_are_rejected(self, forbidden_key: str) -> None:
        repo = _mock_repository(None)
        registry = source_drilldown_tool_registry(repo)
        ctx = _make_context()

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                SOURCE_DETAIL_TOOL_NAME,
                {"source_id": HAPPY_SOURCE_ID, forbidden_key: "smuggled"},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "model_supplied_authority_field"
        repo.get_source_detail.assert_not_called()


# ---------------------------------------------------------------------------
# make_source_detail_tool (used by M13 composition)
# ---------------------------------------------------------------------------


class TestMakeSourceDetailTool:
    """The factory produces a ``ToolDefinition`` independently of the registry."""

    def test_factory_returns_tool_definition_with_executor(self) -> None:
        repo = _mock_repository(None)
        tool = make_source_detail_tool(repo)
        assert tool.name == SOURCE_DETAIL_TOOL_NAME
        assert callable(tool.executor)

    def test_factory_executor_calls_repository(self) -> None:
        detail = _make_detail()
        repo = _mock_repository(detail)
        tool = make_source_detail_tool(repo)
        ctx = _make_context()
        assert tool.executor is not None
        result = tool.executor(ctx, {"source_id": HAPPY_SOURCE_ID})
        assert isinstance(result, dict)
        assert len(result["records"]) == 1
        repo.get_source_detail.assert_called_once_with(
            context=ctx,
            source_id=HAPPY_SOURCE_ID,
        )
