"""Tests for the M9 read-only OpenEMR repository.

These tests pin down the security-critical properties enumerated in
``Clinical Co-Pilot Migration to Python Sidecar.md`` step M9:

* Patient identity always comes from the verified
  :class:`CopilotRunContext` -- there is no parameter on any read method
  that accepts a patient ID, so a tool author cannot smuggle one in.
* SQL is explicit per data class: row caps and date windows are passed
  as bound parameters at the SQL boundary.
* Construction without all required DB env vars fails closed with a
  typed :class:`RepositoryConfigurationError`.
* ``get_source_detail`` rejects mismatched ``source_type`` / patient_id
  / malformed source IDs with ``None`` and never leaks cross-patient data.

Connection access is faked end-to-end with ``MagicMock`` and a custom
``FakeCursor`` that records every executed statement, so the suite runs
on the host without a MySQL server.
"""

from __future__ import annotations

import inspect
from collections.abc import Sequence
from datetime import date, datetime, timezone
from typing import Any
from unittest.mock import MagicMock

import pytest

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.config import Settings
from agent_service.repository import (
    OpenEmrReadRepository,
    RepositoryConfigurationError,
    parse_source_id,
)
from agent_service.schemas.evidence import (
    AllergyRecord,
    EventRecord,
    EvidenceSourceDetail,
    EvidenceSourceType,
    MedicationRecord,
    PatientDemographics,
    ProblemRecord,
    ResultRecord,
)


# ---------------------------------------------------------------------------
# Test fixtures and helpers
# ---------------------------------------------------------------------------


PATIENT_ID = 42
OTHER_PATIENT_ID = 7777


def _make_settings(
    *,
    db_name: str = "openemr_test",
    db_user: str = "ro_user",
    db_pass: str = "ro_pass",
) -> Settings:
    """Build a fully populated Settings for repository construction."""
    return Settings(
        agent_shared_secret="test-secret",
        openai_api_key="",
        cohere_api_key="",
        honeycomb_api_key="",
        debug=False,
        log_level="INFO",
        openemr_db_host="localhost",
        openemr_db_port=3306,
        openemr_db_name=db_name,
        openemr_db_user_ro=db_user,
        openemr_db_pass_ro=db_pass,
        openemr_db_timeout_s=5,
    )


def _make_context(
    *,
    patient_id: int = PATIENT_ID,
    encounter_id: int | None = 100,
    max_rows: int = 50,
    lookback_days: int = 365,
    allowed_source_types: list[str] | None = None,
) -> CopilotRunContext:
    return CopilotRunContext(
        user_id=17,
        username="dr.smith",
        patient_id=patient_id,
        encounter_id=encounter_id,
        allowed_tools=[
            "get_basic_patient_data",
            "get_current_medications",
            "get_active_allergies",
            "get_recent_events",
        ],
        allowed_source_types=allowed_source_types
        or [t.value for t in EvidenceSourceType],
        max_rows=max_rows,
        lookback_days=lookback_days,
        expires_at=2_000_000_000,
        request_id="req-test",
        trace_id="trace-test",
        key_version="v1",
    )


class FakeCursor:
    """Records SQL/params and returns canned rows.

    DictCursor-compatible: ``fetchall`` / ``fetchone`` return mappings.
    """

    def __init__(self, rows: Sequence[dict[str, Any]] | None = None) -> None:
        self.rows = list(rows or [])
        self.executed: list[tuple[str, tuple[Any, ...]]] = []

    def execute(self, sql: str, params: Sequence[Any] | None = None) -> None:
        self.executed.append((sql, tuple(params or ())))

    def fetchall(self) -> list[dict[str, Any]]:
        return list(self.rows)

    def fetchone(self) -> dict[str, Any] | None:
        return self.rows[0] if self.rows else None

    def close(self) -> None:
        return None


def _factory_returning(cursor: FakeCursor):
    """Build a connection_factory whose cursor() returns *cursor*."""
    connection = MagicMock()
    connection.cursor = MagicMock(return_value=cursor)
    connection.close = MagicMock()

    def _factory():
        return connection

    return _factory, connection


# ---------------------------------------------------------------------------
# Construction / fail-closed behaviour
# ---------------------------------------------------------------------------


class TestRepositoryConstruction:
    def test_from_settings_with_valid_settings_succeeds(self) -> None:
        settings = _make_settings()
        repo = OpenEmrReadRepository.from_settings(
            settings, connection_factory=lambda: MagicMock()
        )
        assert isinstance(repo, OpenEmrReadRepository)

    def test_from_settings_raises_when_db_name_missing(self) -> None:
        settings = _make_settings(db_name="")
        with pytest.raises(RepositoryConfigurationError) as excinfo:
            OpenEmrReadRepository.from_settings(
                settings, connection_factory=lambda: MagicMock()
            )
        assert "openemr_db_name" in excinfo.value.missing

    def test_from_settings_raises_when_user_missing(self) -> None:
        settings = _make_settings(db_user="")
        with pytest.raises(RepositoryConfigurationError) as excinfo:
            OpenEmrReadRepository.from_settings(
                settings, connection_factory=lambda: MagicMock()
            )
        assert "openemr_db_user_ro" in excinfo.value.missing

    def test_from_settings_raises_when_password_missing(self) -> None:
        settings = _make_settings(db_pass="")
        with pytest.raises(RepositoryConfigurationError) as excinfo:
            OpenEmrReadRepository.from_settings(
                settings, connection_factory=lambda: MagicMock()
            )
        assert "openemr_db_pass_ro" in excinfo.value.missing

    def test_from_settings_lists_all_missing_at_once(self) -> None:
        settings = _make_settings(db_name="", db_user="", db_pass="")
        with pytest.raises(RepositoryConfigurationError) as excinfo:
            OpenEmrReadRepository.from_settings(
                settings, connection_factory=lambda: MagicMock()
            )
        missing = excinfo.value.missing
        assert "openemr_db_name" in missing
        assert "openemr_db_user_ro" in missing
        assert "openemr_db_pass_ro" in missing


# ---------------------------------------------------------------------------
# Defense-in-depth: signature shape
# ---------------------------------------------------------------------------


class TestRepositorySignatureSafety:
    """Type-signature level guarantees that patient_id cannot be passed.

    Even if a tool author tried to call ``repo.get_active_allergies(
    patient_id=..., context=...)`` the binding would fail at call time
    because no method declares ``patient_id`` as a parameter.
    """

    @pytest.mark.parametrize(
        "method_name",
        [
            "get_demographics",
            "get_current_medications",
            "get_active_allergies",
            "get_active_problems",
            "get_recent_results",
            "get_recent_events",
            "get_changes_since_last_visit",
        ],
    )
    def test_method_signatures_have_no_patient_id_parameter(
        self, method_name: str
    ) -> None:
        method = getattr(OpenEmrReadRepository, method_name)
        signature = inspect.signature(method)
        param_names = set(signature.parameters.keys())
        assert "patient_id" not in param_names
        assert "encounter_id" not in param_names
        assert "lookback_days" not in param_names
        assert "max_rows" not in param_names
        # The only public input must be ``context`` (plus ``self``).
        assert "context" in param_names

    def test_get_source_detail_signature_takes_only_source_id_and_context(
        self,
    ) -> None:
        signature = inspect.signature(OpenEmrReadRepository.get_source_detail)
        param_names = set(signature.parameters.keys()) - {"self"}
        assert param_names == {"context", "source_id"}

    def test_methods_reject_unexpected_kwargs_at_call_time(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        context = _make_context()
        with pytest.raises(TypeError):
            # Attempt to smuggle in a patient_id; method has no such param.
            repo.get_active_allergies(  # type: ignore[call-arg]
                context=context, patient_id=OTHER_PATIENT_ID
            )


# ---------------------------------------------------------------------------
# Patient ID always sourced from the context
# ---------------------------------------------------------------------------


class TestPatientIdAlwaysFromContext:
    def test_get_demographics_uses_context_patient_id(self) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "pid": PATIENT_ID,
                    "dob": date(1980, 1, 15),
                    "sex": "Female",
                    "preferred_language": "english",
                    "pronouns": "she/her",
                    "primary_provider_npi": "1234567890",
                }
            ]
        )
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        record = repo.get_demographics(context=_make_context())

        assert isinstance(record, PatientDemographics)
        assert record.citation_id == f"demographics:patient_data:{PATIENT_ID}"
        assert record.sex == "female"
        sql, params = cursor.executed[0]
        assert "WHERE pd.pid = %s" in sql
        assert params == (PATIENT_ID,)

    def test_get_current_medications_uses_context_patient_id_twice(
        self,
    ) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "list_id": 501,
                    "medication_issue_id": 901,
                    "title": "Lisinopril 10 mg tablet",
                    "begdate": date(2024, 1, 1),
                    "enddate": None,
                    "activity": 1,
                    "rxnorm_code": "314076",
                    "dose": "10 mg PO daily",
                    "route": "oral",
                    "schedule": "qd",
                }
            ]
        )
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        records = repo.get_current_medications(context=_make_context(max_rows=15))

        assert len(records) == 1
        med = records[0]
        assert isinstance(med, MedicationRecord)
        assert med.citation_id == "medication:lists_medication:901"
        assert med.status == "active"
        sql, params = cursor.executed[0]
        # The PHP source-of-truth joins prescriptions on patient_id AND lists
        # on pid -- both come from context.patient_id.
        assert params == (PATIENT_ID, PATIENT_ID, 15)
        assert "LIMIT %s" in sql

    def test_get_active_allergies_uses_context_patient_id(self) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "list_id": 902,
                    "allergen": "Peanut",
                    "onset_date": date(2010, 6, 1),
                    "activity": 1,
                    "coded_allergen": "256349002",
                    "reaction_title": "Hives",
                    "severity_title": "Moderate",
                    "verification_title": "Confirmed",
                }
            ]
        )
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        records = repo.get_active_allergies(context=_make_context(max_rows=20))

        assert len(records) == 1
        allergy = records[0]
        assert isinstance(allergy, AllergyRecord)
        assert allergy.citation_id == "allergy:lists:902"
        assert allergy.severity == "moderate"
        assert allergy.verification_status == "confirmed"
        sql, params = cursor.executed[0]
        assert params == (PATIENT_ID, 20)
        assert "WHERE l.pid = %s" in sql

    def test_get_active_problems_uses_context_patient_id(self) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "list_id": 778,
                    "title": "Type 2 Diabetes Mellitus",
                    "diagnosis": "ICD10:E11.9;SNOMED:73211009",
                    "onset_date": date(2018, 4, 12),
                    "resolved_date": None,
                    "activity": 1,
                }
            ]
        )
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        records = repo.get_active_problems(context=_make_context(max_rows=10))

        assert len(records) == 1
        problem = records[0]
        assert isinstance(problem, ProblemRecord)
        assert problem.citation_id == "problem:lists:778"
        assert problem.icd10_code == "E11.9"
        assert problem.snomed_code == "73211009"
        assert problem.status == "active"
        sql, params = cursor.executed[0]
        assert params == (PATIENT_ID, 10)


# ---------------------------------------------------------------------------
# Row caps and lookback windows
# ---------------------------------------------------------------------------


class TestRowCapsAndLookback:
    def test_row_cap_is_passed_as_LIMIT_parameter(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        repo.get_current_medications(context=_make_context(max_rows=10))

        sql, params = cursor.executed[0]
        assert "LIMIT %s" in sql
        # LIMIT placeholder is the last parameter in the medications query.
        assert params[-1] == 10

    def test_lookback_days_applied_to_recent_events(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        repo.get_recent_events(context=_make_context(lookback_days=30, max_rows=25))

        sql, params = cursor.executed[0]
        assert "INTERVAL %s DAY" in sql
        assert params == (PATIENT_ID, 30, 25)

    def test_lookback_days_applied_to_recent_results(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        repo.get_recent_results(context=_make_context(lookback_days=90, max_rows=12))

        sql, params = cursor.executed[0]
        assert "INTERVAL %s DAY" in sql
        assert params == (PATIENT_ID, 90, 12)

    def test_changes_since_last_visit_uses_lookback_window(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        repo.get_changes_since_last_visit(
            context=_make_context(lookback_days=14, max_rows=5)
        )

        sql, params = cursor.executed[0]
        assert "INTERVAL %s DAY" in sql
        assert params == (PATIENT_ID, 14, 5)


# ---------------------------------------------------------------------------
# Coercion: rows -> typed Pydantic models
# ---------------------------------------------------------------------------


class TestRowCoercion:
    def test_demographics_row_coerced_to_PatientDemographics(self) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "pid": PATIENT_ID,
                    "dob": date(1985, 7, 1),
                    "sex": "M",
                    "preferred_language": "Spanish",
                    "pronouns": "he/him",
                    "primary_provider_npi": "9876543210",
                }
            ]
        )
        factory, _ = _factory_returning(cursor)

        # Pin "now" so the age calculation is deterministic.
        clock_value = datetime(2025, 7, 1, tzinfo=timezone.utc)
        repo = OpenEmrReadRepository(
            connection_factory=factory, clock=lambda: clock_value
        )
        record = repo.get_demographics(context=_make_context())

        assert record is not None
        assert record.age == 40
        assert record.sex == "male"
        assert record.preferred_language == "Spanish"
        assert record.primary_provider_npi == "9876543210"

    def test_demographics_returns_none_when_no_row(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        assert repo.get_demographics(context=_make_context()) is None

    def test_recent_results_row_coerced(self) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "pr_id": 1100,
                    "value": "5.7",
                    "unit": "%",
                    "reference_range": "4.0-5.6",
                    "abnormal_flag": "high",
                    "result_status": "final",
                    "observed_at": datetime(2024, 12, 1, 10, 0, tzinfo=timezone.utc),
                    "loinc_code": "4548-4",
                    "name": "Hemoglobin A1c",
                }
            ]
        )
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        records = repo.get_recent_results(context=_make_context())

        assert len(records) == 1
        result = records[0]
        assert isinstance(result, ResultRecord)
        assert result.citation_id == "result:procedure_result:1100"
        assert result.name == "Hemoglobin A1c"
        assert result.loinc_code == "4548-4"
        assert result.abnormal_flag == "high"
        assert result.status == "final"
        assert result.value == "5.7"

    def test_recent_events_encounter_row_coerced(self) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "encounter_id": 2031,
                    "encounter_number": 12,
                    "reason": "Annual physical",
                    "facility": "Main Clinic",
                    "occurred_at": datetime(2025, 1, 5, 9, 30, tzinfo=timezone.utc),
                    "class_code": "AMB",
                }
            ]
        )
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        records = repo.get_recent_events(context=_make_context())

        assert len(records) == 1
        event = records[0]
        assert isinstance(event, EventRecord)
        assert event.citation_id == "encounter:form_encounter:2031"
        assert event.title == "Annual physical"
        assert event.event_type == "encounter"
        assert event.encounter_id == 12

    def test_medication_falls_back_to_lists_table_when_no_issue_row(self) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "list_id": 555,
                    "medication_issue_id": None,
                    "title": "Atorvastatin 20 mg",
                    "begdate": date(2024, 9, 1),
                    "enddate": None,
                    "activity": 1,
                    "rxnorm_code": None,
                    "dose": None,
                    "route": None,
                    "schedule": None,
                }
            ]
        )
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        records = repo.get_current_medications(context=_make_context())
        assert len(records) == 1
        assert records[0].citation_id == "medication:lists:555"


# ---------------------------------------------------------------------------
# get_source_detail: cross-patient guard, allowed_source_types, malformed
# ---------------------------------------------------------------------------


class TestGetSourceDetail:
    def test_rejects_source_id_with_disallowed_source_type(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        context = _make_context(allowed_source_types=["medication"])

        result = repo.get_source_detail(
            context=context,
            source_id="encounter:form_encounter:2031",
        )

        assert result is None
        # No SQL was executed -- the rejection must happen before any DB call.
        assert cursor.executed == []

    def test_rejects_malformed_source_id(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        context = _make_context()

        for bogus in [
            "",
            "not-a-source-id",
            "two:segments",
            "four:segments:here:wrong",
            "medication:lists_medication:abc",
            "medication:lists_medication:0",
            "medication:lists_medication:-1",
        ]:
            result = repo.get_source_detail(context=context, source_id=bogus)
            assert result is None, f"expected None for bogus source_id={bogus!r}"
        assert cursor.executed == []

    def test_rejects_when_record_belongs_to_other_patient(self) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "patient_id": OTHER_PATIENT_ID,
                    "title": "Cross-patient leaked record",
                    "type": "medical_problem",
                    "begdate": date(2024, 1, 1),
                    "enddate": None,
                    "comments": "should not surface",
                    "modifydate": datetime(2024, 1, 1, tzinfo=timezone.utc),
                }
            ]
        )
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        context = _make_context()

        result = repo.get_source_detail(
            context=context,
            source_id="problem:lists:778",
        )

        assert result is None

    def test_returns_detail_when_patient_matches(self) -> None:
        cursor = FakeCursor(
            rows=[
                {
                    "patient_id": PATIENT_ID,
                    "title": "Hypertension",
                    "type": "medical_problem",
                    "begdate": date(2020, 1, 1),
                    "enddate": None,
                    "comments": "Long-standing",
                    "modifydate": datetime(2024, 5, 1, tzinfo=timezone.utc),
                }
            ]
        )
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        context = _make_context()

        detail = repo.get_source_detail(
            context=context,
            source_id="problem:lists:778",
        )

        assert isinstance(detail, EvidenceSourceDetail)
        assert detail.source_type == EvidenceSourceType.PROBLEM
        assert detail.label == "Hypertension"
        assert "medical_problem" in detail.body
        sql, params = cursor.executed[0]
        # The query is keyed off the record_id, but the patient_id check
        # happens after the row comes back -- the test ensures the row
        # we got back is verified against context.patient_id.
        assert params == (778,)

    def test_unknown_table_returns_none(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, _ = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        context = _make_context(
            allowed_source_types=[t.value for t in EvidenceSourceType]
        )
        result = repo.get_source_detail(
            context=context,
            source_id="medication:unknown_table:42",
        )
        assert result is None

    def test_parse_source_id_helper(self) -> None:
        parsed = parse_source_id("medication:lists_medication:42")
        assert parsed is not None
        assert parsed.source_type == "medication"
        assert parsed.table == "lists_medication"
        assert parsed.record_id == 42

        assert parse_source_id("medication:lists_medication:0") is None
        assert parse_source_id("medication:lists_medication") is None
        assert parse_source_id("") is None


# ---------------------------------------------------------------------------
# Connection lifecycle
# ---------------------------------------------------------------------------


class TestConnectionLifecycle:
    def test_each_query_opens_and_closes_one_connection(self) -> None:
        cursor = FakeCursor(rows=[])
        factory, connection = _factory_returning(cursor)
        repo = OpenEmrReadRepository(connection_factory=factory)
        repo.get_active_allergies(context=_make_context())
        connection.close.assert_called_once()
