"""Tests for the M10 read-only patient evidence tools.

These tests pin down the M10 contract enumerated in the migration plan:

* :func:`patient_evidence_tool_registry` constructs a registry whose
  five tools wrap the M9 :class:`OpenEmrReadRepository` methods.
* Each executor returns the canonical bag shape
  ``{"records", "citations", "warnings", "scope"}``.
* Empty repository results yield a typed missingness warning that
  matches the PHP ``AgentIntentPlaceholderResponseBuilder`` vocabulary.
* Each repository call receives the patient ID via the verified
  :class:`CopilotRunContext` rather than a model-supplied argument.
* Tools refuse when ``context.allowed_source_types`` does not cover the
  tool's source taxonomy (defense-in-depth on top of M6's
  ``tool_not_allowed`` check).
* Round-tripping the executor's output through M6's
  :func:`execute_tool` produces a well-formed :class:`ToolCallOutcome`
  whose ``arguments_keys`` carry no PHI.
* M6 still rejects model-supplied authority fields (``patient_id``)
  even when called against an M10 tool.
"""

from __future__ import annotations

from collections.abc import Callable
from datetime import date, datetime, timezone
from typing import Any
from unittest.mock import MagicMock

import pytest

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.repository.openemr import OpenEmrReadRepository
from agent_service.schemas.copilot import Citation
from agent_service.schemas.evidence import (
    AllergyRecord,
    EventRecord,
    EvidenceSourceType,
    MedicationRecord,
    PatientDemographics,
    ScopeSummary,
)
from agent_service.tools import (
    PATIENT_EVIDENCE_TOOL_NAMES,
    ToolCallOutcome,
    ToolExecutionError,
    execute_tool,
    patient_evidence_tool_registry,
)


# ---------------------------------------------------------------------------
# Test fixtures and helpers
# ---------------------------------------------------------------------------


PATIENT_ID = 42
TOKEN_EXPIRES_AT = 2_000_000_000  # well past 2026
FROZEN_NOW = datetime(2030, 1, 1, tzinfo=timezone.utc)


def _frozen_clock(value: datetime) -> Callable[[], datetime]:
    def _clock() -> datetime:
        return value

    return _clock


def _make_context(
    *,
    allowed_tools: list[str] | None = None,
    allowed_source_types: list[str] | None = None,
    encounter_id: int | None = 100,
    max_rows: int = 50,
    lookback_days: int = 365,
) -> CopilotRunContext:
    return CopilotRunContext(
        user_id=17,
        username="dr.smith",
        patient_id=PATIENT_ID,
        encounter_id=encounter_id,
        allowed_tools=list(allowed_tools or list(PATIENT_EVIDENCE_TOOL_NAMES)),
        allowed_source_types=list(
            allowed_source_types
            if allowed_source_types is not None
            else [t.value for t in EvidenceSourceType]
            + [
                "patient_record",
                "medications",
                "allergies",
                "problems",
                "vitals",
                "labs",
                "encounters",
                "procedures",
            ]
        ),
        max_rows=max_rows,
        lookback_days=lookback_days,
        expires_at=TOKEN_EXPIRES_AT,
        request_id="req-test",
        trace_id="trace-test",
        key_version="v1",
    )


def _make_repository_mock() -> MagicMock:
    """Return a ``MagicMock`` typed as an :class:`OpenEmrReadRepository`."""
    return MagicMock(spec=OpenEmrReadRepository)


def _demographics_record() -> PatientDemographics:
    return PatientDemographics(
        citation_id="demographics:patient_data:42",
        age=45,
        sex="female",
        preferred_language="english",
        pronouns="she/her",
        primary_provider_npi="1234567890",
    )


def _medication_records() -> list[MedicationRecord]:
    return [
        MedicationRecord(
            citation_id="medication:lists_medication:901",
            name="Lisinopril 10 mg tablet",
            rxnorm_code="314076",
            dose="10 mg PO daily",
            route="oral",
            schedule="qd",
            start_date=date(2024, 1, 1),
            stop_date=None,
            status="active",
        ),
        MedicationRecord(
            citation_id="medication:lists_medication:902",
            name="Metformin 500 mg tablet",
            rxnorm_code="860975",
            dose="500 mg PO bid",
            route="oral",
            schedule="bid",
            start_date=date(2023, 6, 1),
            stop_date=None,
            status="active",
        ),
    ]


def _allergy_records() -> list[AllergyRecord]:
    return [
        AllergyRecord(
            citation_id="allergy:lists:701",
            allergen="Penicillin",
            coded_allergen=None,
            reaction="hives",
            severity="moderate",
            verification_status="confirmed",
            onset_date=date(2020, 5, 1),
            status="active",
        )
    ]


def _event_records() -> list[EventRecord]:
    return [
        EventRecord(
            citation_id="encounter:form_encounter:1234",
            title="Office visit",
            event_type="encounter",
            occurred_at=datetime(2025, 12, 1, 10, 0, tzinfo=timezone.utc),
            encounter_id=10,
            summary="Main Clinic",
            status="available",
        )
    ]


# ---------------------------------------------------------------------------
# Registry shape
# ---------------------------------------------------------------------------


class TestRegistryShape:
    def test_registry_has_all_five_tool_names(self) -> None:
        repo = _make_repository_mock()
        registry = patient_evidence_tool_registry(repo)
        names = registry.list_names()
        assert sorted(names) == sorted(
            [
                "get_active_allergies",
                "get_basic_patient_data",
                "get_changes_since_last_visit",
                "get_current_medications",
                "get_recent_events",
            ]
        )

    def test_registry_returns_fresh_instance_per_call(self) -> None:
        repo = _make_repository_mock()
        a = patient_evidence_tool_registry(repo)
        b = patient_evidence_tool_registry(repo)
        assert a is not b

    def test_each_tool_has_executor_wired(self) -> None:
        repo = _make_repository_mock()
        registry = patient_evidence_tool_registry(repo)
        for name in registry.list_names():
            tool = registry.get(name)
            assert tool.executor is not None
            assert tool.read_only is True
            # No forbidden authority keys leaked into the model surface.
            for property_name in tool.input_schema.get("properties", {}):
                assert property_name not in {
                    "patient_id",
                    "encounter_id",
                    "document_id",
                }

    def test_module_canonical_tool_names_match_registry(self) -> None:
        assert sorted(PATIENT_EVIDENCE_TOOL_NAMES) == sorted(
            [
                "get_active_allergies",
                "get_basic_patient_data",
                "get_changes_since_last_visit",
                "get_current_medications",
                "get_recent_events",
            ]
        )


# ---------------------------------------------------------------------------
# get_basic_patient_data
# ---------------------------------------------------------------------------


class TestBasicPatientData:
    def test_happy_path_returns_records_and_citations(self) -> None:
        repo = _make_repository_mock()
        repo.get_demographics.return_value = _demographics_record()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_basic_patient_data")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert isinstance(payload, dict)
        assert isinstance(payload["records"], list)
        assert len(payload["records"]) == 1
        assert isinstance(payload["records"][0], PatientDemographics)
        assert payload["records"][0].citation_id == "demographics:patient_data:42"
        assert isinstance(payload["citations"], list)
        assert len(payload["citations"]) == 1
        assert isinstance(payload["citations"][0], Citation)
        assert payload["citations"][0].source_id == "demographics:patient_data:42"
        assert payload["citations"][0].source_type == "demographics"
        assert payload["warnings"] == []
        assert isinstance(payload["scope"], ScopeSummary)
        assert payload["scope"].patient_id_present is True
        assert payload["scope"].lookback_days_used is None  # demographics: snapshot

        # Repository was called with context= keyword only -- not patient_id.
        repo.get_demographics.assert_called_once()
        call_kwargs = repo.get_demographics.call_args.kwargs
        assert "patient_id" not in call_kwargs
        assert call_kwargs["context"] is ctx

    def test_empty_returns_missingness_warning(self) -> None:
        repo = _make_repository_mock()
        repo.get_demographics.return_value = None
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_basic_patient_data")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert payload["citations"] == []
        assert len(payload["warnings"]) == 1
        assert "not found in checked evidence" in payload["warnings"][0]
        assert "demographics" in payload["warnings"][0].lower()
        assert isinstance(payload["scope"], ScopeSummary)

    def test_refuses_when_source_type_disallowed(self) -> None:
        repo = _make_repository_mock()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_basic_patient_data")
        # Allowed source types do NOT include patient_record.
        ctx = _make_context(allowed_source_types=["medications"])

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert payload["citations"] == []
        assert len(payload["warnings"]) == 1
        assert "refusing tool call" in payload["warnings"][0]
        # Repository was never called -- short-circuited at the source-type check.
        repo.get_demographics.assert_not_called()


# ---------------------------------------------------------------------------
# get_current_medications
# ---------------------------------------------------------------------------


class TestCurrentMedications:
    def test_happy_path_returns_records_and_citations(self) -> None:
        repo = _make_repository_mock()
        repo.get_current_medications.return_value = _medication_records()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_current_medications")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert len(payload["records"]) == 2
        for record in payload["records"]:
            assert isinstance(record, MedicationRecord)
        assert len(payload["citations"]) == 2
        for citation in payload["citations"]:
            assert isinstance(citation, Citation)
            assert citation.source_type == "medication"
        assert payload["warnings"] == []

        repo.get_current_medications.assert_called_once()
        call_kwargs = repo.get_current_medications.call_args.kwargs
        assert "patient_id" not in call_kwargs
        assert call_kwargs["context"] is ctx

    def test_empty_returns_missingness_warning(self) -> None:
        repo = _make_repository_mock()
        repo.get_current_medications.return_value = []
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_current_medications")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert payload["citations"] == []
        assert len(payload["warnings"]) == 1
        assert (
            payload["warnings"][0]
            == "Current medication records were not found in checked evidence."
        )

    def test_refuses_when_source_type_disallowed(self) -> None:
        repo = _make_repository_mock()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_current_medications")
        ctx = _make_context(allowed_source_types=["allergies"])

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert "refusing tool call" in payload["warnings"][0]
        repo.get_current_medications.assert_not_called()


# ---------------------------------------------------------------------------
# get_active_allergies
# ---------------------------------------------------------------------------


class TestActiveAllergies:
    def test_happy_path_returns_records_and_citations(self) -> None:
        repo = _make_repository_mock()
        repo.get_active_allergies.return_value = _allergy_records()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_active_allergies")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert len(payload["records"]) == 1
        assert isinstance(payload["records"][0], AllergyRecord)
        assert payload["records"][0].allergen == "Penicillin"
        assert len(payload["citations"]) == 1
        assert payload["citations"][0].source_type == "allergy"
        assert payload["warnings"] == []

        repo.get_active_allergies.assert_called_once()
        call_kwargs = repo.get_active_allergies.call_args.kwargs
        assert "patient_id" not in call_kwargs
        assert call_kwargs["context"] is ctx

    def test_empty_returns_missingness_warning(self) -> None:
        repo = _make_repository_mock()
        repo.get_active_allergies.return_value = []
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_active_allergies")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert payload["citations"] == []
        assert (
            payload["warnings"][0]
            == "Current allergy records were not found in checked evidence."
        )

    def test_refuses_when_source_type_disallowed(self) -> None:
        repo = _make_repository_mock()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_active_allergies")
        ctx = _make_context(allowed_source_types=["medications"])

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert "refusing tool call" in payload["warnings"][0]
        repo.get_active_allergies.assert_not_called()


# ---------------------------------------------------------------------------
# get_recent_events
# ---------------------------------------------------------------------------


class TestRecentEvents:
    def test_happy_path_returns_records_and_citations(self) -> None:
        repo = _make_repository_mock()
        repo.get_recent_events.return_value = _event_records()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_recent_events")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert len(payload["records"]) == 1
        assert isinstance(payload["records"][0], EventRecord)
        assert payload["records"][0].title == "Office visit"
        assert len(payload["citations"]) == 1
        assert payload["citations"][0].source_type == "encounter"
        assert payload["warnings"] == []

        repo.get_recent_events.assert_called_once()
        call_kwargs = repo.get_recent_events.call_args.kwargs
        assert "patient_id" not in call_kwargs
        assert call_kwargs["context"] is ctx

    def test_empty_returns_missingness_warning(self) -> None:
        repo = _make_repository_mock()
        repo.get_recent_events.return_value = []
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_recent_events")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert payload["citations"] == []
        assert (
            payload["warnings"][0]
            == "Recent encounter events were not found in checked evidence."
        )

    def test_refuses_when_source_type_disallowed(self) -> None:
        repo = _make_repository_mock()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_recent_events")
        # Allowed source types do not include any of encounters/labs/vitals/procedures.
        ctx = _make_context(allowed_source_types=["medications"])

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert "refusing tool call" in payload["warnings"][0]
        repo.get_recent_events.assert_not_called()


# ---------------------------------------------------------------------------
# get_changes_since_last_visit
# ---------------------------------------------------------------------------


class TestChangesSinceLastVisit:
    def test_happy_path_returns_records_and_citations(self) -> None:
        repo = _make_repository_mock()
        repo.get_changes_since_last_visit.return_value = _event_records()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_changes_since_last_visit")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert len(payload["records"]) == 1
        assert isinstance(payload["records"][0], EventRecord)
        assert len(payload["citations"]) == 1
        assert payload["warnings"] == []

        repo.get_changes_since_last_visit.assert_called_once()
        call_kwargs = repo.get_changes_since_last_visit.call_args.kwargs
        assert "patient_id" not in call_kwargs
        assert call_kwargs["context"] is ctx

    def test_empty_returns_missingness_warning(self) -> None:
        repo = _make_repository_mock()
        repo.get_changes_since_last_visit.return_value = []
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_changes_since_last_visit")
        ctx = _make_context()

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert payload["citations"] == []
        assert (
            payload["warnings"][0]
            == "No chart changes were found in checked evidence since the last visit."
        )

    def test_refuses_when_source_type_disallowed(self) -> None:
        repo = _make_repository_mock()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_changes_since_last_visit")
        # No overlap with the allowed list.
        ctx = _make_context(allowed_source_types=["encounters"])

        payload = tool.executor(ctx, {})

        assert payload["records"] == []
        assert "refusing tool call" in payload["warnings"][0]
        repo.get_changes_since_last_visit.assert_not_called()


# ---------------------------------------------------------------------------
# Round-trip through the M6 executor
# ---------------------------------------------------------------------------


class TestRoundTripWithExecutor:
    def test_outcome_is_well_formed_for_get_current_medications(self) -> None:
        repo = _make_repository_mock()
        repo.get_current_medications.return_value = _medication_records()
        registry = patient_evidence_tool_registry(repo)
        ctx = _make_context()

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
        assert len(outcome.citations) == 2
        for citation in outcome.citations:
            assert isinstance(citation, Citation)
        # arguments_keys carries only sorted KEYS -- no PHI.
        assert list(outcome.arguments_keys) == sorted(outcome.arguments_keys)
        for key in outcome.arguments_keys:
            assert isinstance(key, str)
        # ``patient_id`` is injected by the executor (M6) but should still
        # be present in the runtime args (and therefore in arguments_keys).
        assert "patient_id" in outcome.arguments_keys
        assert "encounter_id" in outcome.arguments_keys

    def test_outcome_for_basic_patient_data_has_one_record(self) -> None:
        repo = _make_repository_mock()
        repo.get_demographics.return_value = _demographics_record()
        registry = patient_evidence_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            "get_basic_patient_data",
            {},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.result_count == 1
        assert len(outcome.citations) == 1

    def test_executor_rejects_model_supplied_patient_id(self) -> None:
        """M6 forbids ``patient_id`` from model_args even for M10 tools."""
        repo = _make_repository_mock()
        registry = patient_evidence_tool_registry(repo)
        ctx = _make_context()

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "get_current_medications",
                {"patient_id": 99999},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "model_supplied_authority_field"
        repo.get_current_medications.assert_not_called()

    def test_executor_rejects_disallowed_tool(self) -> None:
        repo = _make_repository_mock()
        registry = patient_evidence_tool_registry(repo)
        # Run context allows a different tool name.
        ctx = _make_context(allowed_tools=["get_basic_patient_data"])

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "get_current_medications",
                {},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "tool_not_allowed"

    def test_outcome_payload_carries_scope_summary(self) -> None:
        repo = _make_repository_mock()
        repo.get_active_allergies.return_value = _allergy_records()
        registry = patient_evidence_tool_registry(repo)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            "get_active_allergies",
            {},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert isinstance(outcome.payload, dict)
        assert isinstance(outcome.payload["scope"], ScopeSummary)
        assert outcome.payload["scope"].patient_id_present is True
        # Allergies use the lookback window.
        assert outcome.payload["scope"].lookback_days_used == 365


# ---------------------------------------------------------------------------
# Pydantic-model-shape checks
# ---------------------------------------------------------------------------


class TestEvidenceModelShapes:
    @pytest.mark.parametrize(
        ("tool_name", "fixture_factory", "expected_type"),
        [
            (
                "get_basic_patient_data",
                _demographics_record,
                PatientDemographics,
            ),
        ],
    )
    def test_basic_patient_data_record_is_pydantic_model(
        self,
        tool_name: str,
        fixture_factory: Callable[[], Any],
        expected_type: type,
    ) -> None:
        repo = _make_repository_mock()
        repo.get_demographics.return_value = fixture_factory()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get(tool_name)
        payload = tool.executor(_make_context(), {})

        assert isinstance(payload["records"][0], expected_type)

    def test_medications_records_are_medication_models(self) -> None:
        repo = _make_repository_mock()
        repo.get_current_medications.return_value = _medication_records()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_current_medications")
        payload = tool.executor(_make_context(), {})

        for record in payload["records"]:
            assert isinstance(record, MedicationRecord)

    def test_allergies_records_are_allergy_models(self) -> None:
        repo = _make_repository_mock()
        repo.get_active_allergies.return_value = _allergy_records()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_active_allergies")
        payload = tool.executor(_make_context(), {})

        for record in payload["records"]:
            assert isinstance(record, AllergyRecord)

    def test_recent_events_records_are_event_models(self) -> None:
        repo = _make_repository_mock()
        repo.get_recent_events.return_value = _event_records()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_recent_events")
        payload = tool.executor(_make_context(), {})

        for record in payload["records"]:
            assert isinstance(record, EventRecord)

    def test_changes_since_last_visit_records_are_event_models(self) -> None:
        repo = _make_repository_mock()
        repo.get_changes_since_last_visit.return_value = _event_records()
        registry = patient_evidence_tool_registry(repo)
        tool = registry.get("get_changes_since_last_visit")
        payload = tool.executor(_make_context(), {})

        for record in payload["records"]:
            assert isinstance(record, EventRecord)
