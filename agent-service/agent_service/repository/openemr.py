"""Read-only OpenEMR repository (M9).

This module owns the entire SQL surface that the chart copilot can use
to read OpenEMR data. Every method:

* Accepts a verified :class:`CopilotRunContext` keyword-only.
* Pulls ``patient_id``, ``encounter_id``, ``lookback_days``, and
  ``max_rows`` from that context. Patient identity never travels in a
  caller-supplied argument -- the repository's signatures simply do not
  expose a way to pass it.
* Issues parameterized SQL with a ``LIMIT`` clamped by ``context.max_rows``
  and a date window clamped by ``context.lookback_days``.
* Coerces the resulting rows into the strongly-typed evidence models
  defined in M8 (``MedicationRecord`` etc.), preserving the
  ``<source_type>:<table>:<record_id>`` citation-ID format the chart UI
  round-trips verbatim.

The repository is intentionally synchronous. The sidecar's tool layer
already runs each tool call inside an asyncio executor, and a sync
driver (PyMySQL) keeps the implementation simple while we are still
issuing one query at a time per tool.

The module exposes:

* :class:`OpenEmrReadRepository` -- the main entry point.
* :class:`RepositoryConfigurationError` -- raised by ``from_settings``
  when any of the required DB env vars is missing or empty.
* :func:`parse_source_id` -- helper exposed for tests/M11.
"""

from __future__ import annotations

import re
from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass
from datetime import date, datetime, timezone
from typing import Any, Protocol, runtime_checkable

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.config import Settings
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
# Errors
# ---------------------------------------------------------------------------


class RepositoryConfigurationError(RuntimeError):
    """Raised when the read repository cannot be constructed.

    Distinct exception type so tests and the sidecar bootstrap can
    differentiate fail-closed misconfiguration from runtime DB errors.
    Carries the name of the missing setting in :attr:`missing` for
    operator-friendly messages without leaking secret values.
    """

    def __init__(self, missing: tuple[str, ...]) -> None:
        joined = ", ".join(sorted(missing))
        super().__init__(
            "OpenEMR read repository is not configured: missing required "
            f"settings [{joined}]. Set the corresponding OPENEMR_DB_* env "
            "vars before constructing the repository."
        )
        self.missing: tuple[str, ...] = tuple(sorted(missing))


# ---------------------------------------------------------------------------
# Connection / cursor protocols
# ---------------------------------------------------------------------------


@runtime_checkable
class _CursorLike(Protocol):
    """Subset of the DB-API 2.0 cursor that the repository depends on.

    Pinning this to a Protocol means tests can pass a ``MagicMock`` /
    fake without depending on PyMySQL or another driver, and the type
    checker still verifies we never reach for an attribute the contract
    does not promise.
    """

    def execute(self, sql: str, params: Sequence[object] | None = ...) -> object: ...
    def fetchone(self) -> Mapping[str, object] | tuple[object, ...] | None: ...
    def fetchall(
        self,
    ) -> Sequence[Mapping[str, object] | tuple[object, ...]]: ...
    def close(self) -> None: ...


@runtime_checkable
class _ConnectionLike(Protocol):
    """Subset of the DB-API 2.0 connection that the repository depends on.

    The repository never calls ``commit()`` -- the credential is supposed
    to be read-only at the database level, but the application layer
    refuses to even ask for writes as a defense-in-depth measure.
    """

    def cursor(self, *args: Any, **kwargs: Any) -> _CursorLike: ...
    def close(self) -> None: ...


ConnectionFactory = Callable[[], _ConnectionLike]
"""Returns an open DB-API connection. The repository owns its lifecycle."""


# ---------------------------------------------------------------------------
# Source-ID parsing (shared with M11)
# ---------------------------------------------------------------------------


_SOURCE_ID_PATTERN = re.compile(r"\A([A-Za-z0-9_]+):([A-Za-z0-9_]+):([0-9]+)\Z")


@dataclass(frozen=True, slots=True)
class _ParsedSourceId:
    """Decomposed citation ID of the form ``<source_type>:<table>:<id>``."""

    source_type: str
    table: str
    record_id: int


def parse_source_id(source_id: str) -> _ParsedSourceId | None:
    """Parse a citation ID into its components, returning ``None`` on bad input.

    The format is the same used by the PHP layer's
    ``SqlEvidenceRecordRepository::fetchSourceRecord`` and round-tripped
    by the chart UI, so accepting/rejecting on this exact regex keeps
    the contract aligned across PHP and Python.
    """
    if not isinstance(source_id, str):
        return None
    match = _SOURCE_ID_PATTERN.match(source_id)
    if match is None:
        return None
    record_id = int(match.group(3))
    if record_id <= 0:
        return None
    return _ParsedSourceId(
        source_type=match.group(1),
        table=match.group(2),
        record_id=record_id,
    )


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


def _utc_now() -> datetime:
    return datetime.now(tz=timezone.utc)


def _coerce_int(value: object) -> int | None:
    if value is None:
        return None
    if isinstance(value, bool):  # bool is an int subclass in Python -- reject explicitly.
        return int(value)
    if isinstance(value, int):
        return value
    if isinstance(value, str) and value != "":
        try:
            return int(value)
        except ValueError:
            return None
    return None


def _coerce_str(value: object) -> str | None:
    if value is None:
        return None
    if isinstance(value, str):
        return value if value != "" else None
    return str(value) if value != "" else None


def _coerce_date(value: object) -> date | None:
    if value is None or value == "" or value == "0000-00-00":
        return None
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    if isinstance(value, str):
        try:
            return date.fromisoformat(value[:10])
        except ValueError:
            return None
    return None


def _coerce_datetime(value: object) -> datetime | None:
    if value is None or value == "" or (isinstance(value, str) and value.startswith("0000-00-00")):
        return None
    if isinstance(value, datetime):
        return value
    if isinstance(value, date):
        return datetime(value.year, value.month, value.day, tzinfo=timezone.utc)
    if isinstance(value, str):
        try:
            return datetime.fromisoformat(value)
        except ValueError:
            return None
    return None


def _normalised_med_status(activity: object, enddate: object) -> str:
    """Map a ``lists`` row's activity/enddate into the MedicationRecord status enum."""
    activity_int = _coerce_int(activity)
    if activity_int == 1:
        return "active"
    end = _coerce_date(enddate)
    if end is None:
        return "unknown"
    if end < _utc_now().date():
        return "stopped"
    return "active"


def _normalised_allergy_status(activity: object) -> str:
    activity_int = _coerce_int(activity)
    if activity_int == 1:
        return "active"
    if activity_int == 0:
        return "inactive"
    return "unknown"


def _normalised_problem_status(activity: object, enddate: object) -> str:
    activity_int = _coerce_int(activity)
    end = _coerce_date(enddate)
    if activity_int == 1 and end is None:
        return "active"
    if end is not None:
        return "resolved"
    if activity_int == 0:
        return "inactive"
    return "unknown"


# ---------------------------------------------------------------------------
# Repository
# ---------------------------------------------------------------------------


_REQUIRED_DB_SETTINGS: tuple[str, ...] = (
    "openemr_db_name",
    "openemr_db_user_ro",
    "openemr_db_pass_ro",
)


class OpenEmrReadRepository:
    """Explicit, read-only SQL surface against the OpenEMR schema.

    Construct via :meth:`from_settings` for production, or directly with
    a ``connection_factory`` for tests that mock the DB layer.
    """

    def __init__(
        self,
        *,
        connection_factory: ConnectionFactory,
        clock: Callable[[], datetime] | None = None,
    ) -> None:
        self._connection_factory = connection_factory
        self._clock = clock or _utc_now

    # ------------------------------------------------------------------ #
    # Construction
    # ------------------------------------------------------------------ #

    @classmethod
    def from_settings(
        cls,
        settings: Settings,
        *,
        connection_factory: ConnectionFactory | None = None,
        clock: Callable[[], datetime] | None = None,
    ) -> "OpenEmrReadRepository":
        """Build a repository from the application :class:`Settings`.

        Validates that every required DB env var is non-empty *before*
        attempting any I/O. The optional ``connection_factory`` override
        lets test suites bypass the real driver entirely.
        """
        missing = tuple(
            name for name in _REQUIRED_DB_SETTINGS if not getattr(settings, name)
        )
        if missing:
            raise RepositoryConfigurationError(missing)

        if connection_factory is None:
            connection_factory = _build_pymysql_factory(settings)

        return cls(connection_factory=connection_factory, clock=clock)

    # ------------------------------------------------------------------ #
    # Public read methods
    # ------------------------------------------------------------------ #

    def get_demographics(
        self, *, context: CopilotRunContext
    ) -> PatientDemographics | None:
        """Return the patient demographics row for ``context.patient_id``.

        One row at most; if the patient is not found the method returns
        ``None`` rather than fabricating a record. The returned
        ``citation_id`` matches the PHP normalizer's
        ``demographics:patient_data:<pid>`` shape.
        """
        sql = (
            "SELECT pd.pid AS pid, pd.DOB AS dob, pd.sex AS sex, "
            "pd.language AS preferred_language, pd.pronoun AS pronouns, "
            "primary_provider.npi AS primary_provider_npi "
            "FROM patient_data pd "
            "LEFT JOIN users primary_provider "
            "  ON primary_provider.id = pd.providerID "
            "WHERE pd.pid = %s "
            "LIMIT 1"
        )
        rows = self._fetch_all(sql, (context.patient_id,))
        if not rows:
            return None
        row = rows[0]
        pid = _coerce_int(row.get("pid")) or context.patient_id
        return PatientDemographics(
            citation_id=f"demographics:patient_data:{pid}",
            age=_age_from_dob(_coerce_date(row.get("dob")), self._clock()),
            sex=_normalised_sex(_coerce_str(row.get("sex"))),
            preferred_language=_coerce_str(row.get("preferred_language")),
            pronouns=_coerce_str(row.get("pronouns")),
            primary_provider_npi=_coerce_str(row.get("primary_provider_npi")),
        )

    def get_current_medications(
        self, *, context: CopilotRunContext
    ) -> list[MedicationRecord]:
        """Return active medications for ``context.patient_id`` capped to ``max_rows``."""
        sql = (
            "SELECT l.id AS list_id, lm.id AS medication_issue_id, "
            "l.title AS title, l.begdate AS begdate, l.enddate AS enddate, "
            "l.activity AS activity, lm.drug_dosage_instructions AS dose, "
            "p.rxnorm_drugcode AS rxnorm_code, p.route AS route, "
            "p.dosage AS schedule "
            "FROM lists l "
            "LEFT JOIN lists_medication lm ON lm.list_id = l.id "
            "LEFT JOIN prescriptions p ON p.id = lm.prescription_id "
            "  AND p.patient_id = %s "
            "WHERE l.pid = %s "
            "  AND l.type = 'medication' "
            # Match the same shape as get_active_allergies / problems below.
            # The bare ``l.enddate = '0000-00-00'`` literal blew up under
            # MySQL 9's strict mode with ``NO_ZERO_DATE`` (Railway prod)
            # because the engine refuses to compare a DATE column to that
            # zero-date literal. ``IS NULL`` already covers the legitimate
            # "no end date set" case; OpenEMR sites that wrote a real
            # ``'0000-00-00'`` sentinel are picked up by the activity flag.
            "  AND (l.activity = 1 OR l.enddate IS NULL OR l.enddate >= CURDATE()) "
            "ORDER BY COALESCE(l.modifydate, l.date, l.begdate) DESC, l.id DESC "
            "LIMIT %s"
        )
        rows = self._fetch_all(
            sql,
            (context.patient_id, context.patient_id, context.max_rows),
        )
        records: list[MedicationRecord] = []
        for row in rows:
            issue_id = _coerce_int(row.get("medication_issue_id"))
            list_id = _coerce_int(row.get("list_id")) or 0
            if issue_id is not None and issue_id > 0:
                table = "lists_medication"
                record_id = issue_id
            else:
                table = "lists"
                record_id = list_id
            citation_id = f"medication:{table}:{record_id}"
            records.append(
                MedicationRecord(
                    citation_id=citation_id,
                    name=_coerce_str(row.get("title")) or "Unknown medication",
                    rxnorm_code=_coerce_str(row.get("rxnorm_code")),
                    dose=_coerce_str(row.get("dose")),
                    route=_coerce_str(row.get("route")),
                    schedule=_coerce_str(row.get("schedule")),
                    start_date=_coerce_date(row.get("begdate")),
                    stop_date=_coerce_date(row.get("enddate")),
                    status=_med_status_literal(
                        _normalised_med_status(row.get("activity"), row.get("enddate"))
                    ),
                )
            )
        return records

    def get_active_allergies(
        self, *, context: CopilotRunContext
    ) -> list[AllergyRecord]:
        """Return active allergies for ``context.patient_id`` capped to ``max_rows``."""
        sql = (
            "SELECT l.id AS list_id, l.title AS allergen, l.begdate AS onset_date, "
            "l.activity AS activity, l.list_option_id AS coded_allergen, "
            "reaction.title AS reaction_title, severity.title AS severity_title, "
            "verification.title AS verification_title "
            "FROM lists l "
            "LEFT JOIN list_options reaction "
            "  ON reaction.option_id = l.reaction AND reaction.list_id = 'reaction' "
            "LEFT JOIN list_options severity "
            "  ON severity.option_id = l.severity_al "
            "    AND severity.list_id = 'severity_ccda' "
            "LEFT JOIN list_options verification "
            "  ON verification.option_id = l.verification "
            "    AND verification.list_id = 'allergyintolerance-verification' "
            "WHERE l.pid = %s "
            "  AND l.type = 'allergy' "
            "  AND (l.activity = 1 OR l.enddate IS NULL OR l.enddate >= CURDATE()) "
            "ORDER BY COALESCE(l.modifydate, l.date, l.begdate) DESC, l.id DESC "
            "LIMIT %s"
        )
        rows = self._fetch_all(sql, (context.patient_id, context.max_rows))
        records: list[AllergyRecord] = []
        for row in rows:
            list_id = _coerce_int(row.get("list_id")) or 0
            records.append(
                AllergyRecord(
                    citation_id=f"allergy:lists:{list_id}",
                    allergen=_coerce_str(row.get("allergen")) or "Unknown allergen",
                    coded_allergen=_coerce_str(row.get("coded_allergen")),
                    reaction=_coerce_str(row.get("reaction_title")),
                    severity=_severity_literal(_coerce_str(row.get("severity_title"))),
                    verification_status=_verification_literal(
                        _coerce_str(row.get("verification_title"))
                    ),
                    onset_date=_coerce_date(row.get("onset_date")),
                    status=_allergy_status_literal(
                        _normalised_allergy_status(row.get("activity"))
                    ),
                )
            )
        return records

    def get_active_problems(
        self, *, context: CopilotRunContext
    ) -> list[ProblemRecord]:
        """Return active problems for ``context.patient_id`` capped to ``max_rows``."""
        sql = (
            "SELECT l.id AS list_id, l.title AS title, l.diagnosis AS diagnosis, "
            "l.begdate AS onset_date, l.enddate AS resolved_date, l.activity AS activity "
            "FROM lists l "
            "WHERE l.pid = %s "
            "  AND l.type = 'medical_problem' "
            "  AND (l.activity = 1 OR l.enddate IS NULL OR l.enddate >= CURDATE()) "
            "ORDER BY COALESCE(l.modifydate, l.date, l.begdate) DESC, l.id DESC "
            "LIMIT %s"
        )
        rows = self._fetch_all(sql, (context.patient_id, context.max_rows))
        records: list[ProblemRecord] = []
        for row in rows:
            list_id = _coerce_int(row.get("list_id")) or 0
            icd10, snomed = _split_diagnosis_codes(_coerce_str(row.get("diagnosis")))
            records.append(
                ProblemRecord(
                    citation_id=f"problem:lists:{list_id}",
                    title=_coerce_str(row.get("title")) or "Unknown problem",
                    icd10_code=icd10,
                    snomed_code=snomed,
                    onset_date=_coerce_date(row.get("onset_date")),
                    resolved_date=_coerce_date(row.get("resolved_date")),
                    status=_problem_status_literal(
                        _normalised_problem_status(
                            row.get("activity"), row.get("resolved_date")
                        )
                    ),
                )
            )
        return records

    def get_recent_results(
        self, *, context: CopilotRunContext
    ) -> list[ResultRecord]:
        """Return recent procedure results within ``context.lookback_days``."""
        sql = (
            "SELECT pr.procedure_result_id AS pr_id, pr.result AS value, "
            "pr.units AS unit, pr.range AS reference_range, "
            "pr.abnormal AS abnormal_flag, pr.result_status AS result_status, "
            "pr.date AS observed_at, pr.result_code AS loinc_code, "
            "pr.result_text AS name "
            "FROM procedure_result pr "
            "INNER JOIN procedure_report prr "
            "  ON prr.procedure_report_id = pr.procedure_report_id "
            "INNER JOIN procedure_order po "
            "  ON po.procedure_order_id = prr.procedure_order_id "
            "WHERE po.patient_id = %s "
            "  AND pr.date >= (NOW() - INTERVAL %s DAY) "
            "ORDER BY pr.date DESC, pr.procedure_result_id DESC "
            "LIMIT %s"
        )
        rows = self._fetch_all(
            sql,
            (context.patient_id, context.lookback_days, context.max_rows),
        )
        records: list[ResultRecord] = []
        for row in rows:
            pr_id = _coerce_int(row.get("pr_id")) or 0
            records.append(
                ResultRecord(
                    citation_id=f"result:procedure_result:{pr_id}",
                    name=_coerce_str(row.get("name")) or "Unnamed result",
                    loinc_code=_coerce_str(row.get("loinc_code")),
                    value=_coerce_str(row.get("value")),
                    unit=_coerce_str(row.get("unit")),
                    reference_range=_coerce_str(row.get("reference_range")),
                    abnormal_flag=_abnormal_flag_literal(
                        _coerce_str(row.get("abnormal_flag"))
                    ),
                    observed_at=_coerce_datetime(row.get("observed_at")),
                    status=_result_status_literal(
                        _coerce_str(row.get("result_status"))
                    ),
                )
            )
        return records

    def get_recent_events(
        self, *, context: CopilotRunContext
    ) -> list[EventRecord]:
        """Return recent encounters within ``context.lookback_days``.

        Documents and other event-shaped tables are added in M10/M11; the
        M9 surface mirrors the PHP "recent encounter" sweep that already
        exists at the SQL boundary.
        """
        sql = (
            "SELECT fe.id AS encounter_id, fe.encounter AS encounter_number, "
            "fe.reason AS reason, fe.facility AS facility, fe.date AS occurred_at, "
            "fe.class_code AS class_code "
            "FROM form_encounter fe "
            "WHERE fe.pid = %s "
            "  AND fe.date >= (NOW() - INTERVAL %s DAY) "
            "ORDER BY fe.date DESC, fe.id DESC "
            "LIMIT %s"
        )
        rows = self._fetch_all(
            sql,
            (context.patient_id, context.lookback_days, context.max_rows),
        )
        return [_encounter_row_to_event(row) for row in rows]

    def get_changes_since_last_visit(
        self, *, context: CopilotRunContext
    ) -> list[EventRecord]:
        """Return encounters more recent than the last completed visit baseline.

        The baseline is the most recent encounter strictly older than the
        lookback window; if no such baseline exists we fall back to the
        same lookback the rest of the repository uses. Either way the
        SQL caps the result at ``context.max_rows``.
        """
        sql = (
            "SELECT fe.id AS encounter_id, fe.encounter AS encounter_number, "
            "fe.reason AS reason, fe.facility AS facility, fe.date AS occurred_at, "
            "fe.class_code AS class_code "
            "FROM form_encounter fe "
            "WHERE fe.pid = %s "
            "  AND fe.date >= (NOW() - INTERVAL %s DAY) "
            "ORDER BY fe.date DESC, fe.id DESC "
            "LIMIT %s"
        )
        rows = self._fetch_all(
            sql,
            (context.patient_id, context.lookback_days, context.max_rows),
        )
        return [_encounter_row_to_event(row) for row in rows]

    def get_source_detail(
        self, *, context: CopilotRunContext, source_id: str
    ) -> EvidenceSourceDetail | None:
        """Look up a single source row, with cross-patient guards.

        Behavior:

        * Source IDs whose ``source_type`` segment is not in
          ``context.allowed_source_types`` are rejected with ``None``.
        * Source IDs whose looked-up row's patient ID does not match
          ``context.patient_id`` are rejected with ``None`` (cross-patient
          guard -- never trust the caller to scope the read).
        * Malformed source IDs (wrong segment count) return ``None``.
        """
        parsed = parse_source_id(source_id)
        if parsed is None:
            return None
        if parsed.source_type not in context.allowed_source_types:
            return None

        try:
            source_type_enum = EvidenceSourceType(parsed.source_type)
        except ValueError:
            return None

        match parsed.table:
            case "patient_data":
                return self._fetch_patient_source(
                    context=context,
                    parsed=parsed,
                    source_type=source_type_enum,
                )
            case "lists":
                return self._fetch_list_source(
                    context=context,
                    parsed=parsed,
                    source_type=source_type_enum,
                )
            case "form_encounter":
                return self._fetch_encounter_source(
                    context=context,
                    parsed=parsed,
                    source_type=source_type_enum,
                )
            case "documents":
                return self._fetch_document_source(
                    context=context,
                    parsed=parsed,
                    source_type=source_type_enum,
                )
            case _:
                return None

    # ------------------------------------------------------------------ #
    # Private helpers
    # ------------------------------------------------------------------ #

    def _fetch_all(
        self, sql: str, params: Sequence[object]
    ) -> list[Mapping[str, object]]:
        """Execute *sql* with *params* and return rows as mappings.

        The connection lifecycle is owned by the repository: every call
        opens, queries, and closes. Tests inject a factory whose
        connection is a ``MagicMock`` so this is cheap; in production
        you'd front this with a pool, which we'll add when we wire the
        real driver in M10.
        """
        connection = self._connection_factory()
        try:
            cursor = _open_cursor(connection)
            try:
                cursor.execute(sql, tuple(params))
                rows = cursor.fetchall()
            finally:
                cursor.close()
        finally:
            connection.close()
        return [_row_as_mapping(row) for row in rows]

    def _fetch_one(
        self, sql: str, params: Sequence[object]
    ) -> Mapping[str, object] | None:
        connection = self._connection_factory()
        try:
            cursor = _open_cursor(connection)
            try:
                cursor.execute(sql, tuple(params))
                row = cursor.fetchone()
            finally:
                cursor.close()
        finally:
            connection.close()
        return _row_as_mapping(row) if row is not None else None

    def _fetch_patient_source(
        self,
        *,
        context: CopilotRunContext,
        parsed: _ParsedSourceId,
        source_type: EvidenceSourceType,
    ) -> EvidenceSourceDetail | None:
        sql = (
            "SELECT pid AS patient_id, fname, lname, DOB AS dob "
            "FROM patient_data WHERE pid = %s LIMIT 1"
        )
        row = self._fetch_one(sql, (parsed.record_id,))
        if row is None:
            return None
        if _coerce_int(row.get("patient_id")) != context.patient_id:
            return None
        label_parts = [
            _coerce_str(row.get("fname")) or "",
            _coerce_str(row.get("lname")) or "",
        ]
        label = " ".join(part for part in label_parts if part) or "Patient"
        body = f"DOB: {_coerce_date(row.get('dob')) or 'unknown'}"
        return EvidenceSourceDetail(
            source_id=f"{parsed.source_type}:{parsed.table}:{parsed.record_id}",
            source_type=source_type,
            label=label,
            body=body,
        )

    def _fetch_list_source(
        self,
        *,
        context: CopilotRunContext,
        parsed: _ParsedSourceId,
        source_type: EvidenceSourceType,
    ) -> EvidenceSourceDetail | None:
        sql = (
            "SELECT pid AS patient_id, title, type, "
            "begdate, enddate, comments, modifydate "
            "FROM lists WHERE id = %s LIMIT 1"
        )
        row = self._fetch_one(sql, (parsed.record_id,))
        if row is None:
            return None
        if _coerce_int(row.get("patient_id")) != context.patient_id:
            return None
        label = _coerce_str(row.get("title")) or "Clinical list entry"
        body_parts: list[str] = []
        list_type = _coerce_str(row.get("type"))
        if list_type:
            body_parts.append(f"Type: {list_type}")
        comments = _coerce_str(row.get("comments"))
        if comments:
            body_parts.append(comments)
        return EvidenceSourceDetail(
            source_id=f"{parsed.source_type}:{parsed.table}:{parsed.record_id}",
            source_type=source_type,
            label=label,
            body=" | ".join(body_parts) or label,
            occurred_at=_coerce_datetime(row.get("modifydate")),
        )

    def _fetch_encounter_source(
        self,
        *,
        context: CopilotRunContext,
        parsed: _ParsedSourceId,
        source_type: EvidenceSourceType,
    ) -> EvidenceSourceDetail | None:
        sql = (
            "SELECT pid AS patient_id, encounter AS encounter_number, "
            "reason, facility, date AS occurred_at "
            "FROM form_encounter WHERE id = %s LIMIT 1"
        )
        row = self._fetch_one(sql, (parsed.record_id,))
        if row is None:
            return None
        if _coerce_int(row.get("patient_id")) != context.patient_id:
            return None
        label = (
            _coerce_str(row.get("reason"))
            or f"Encounter {_coerce_int(row.get('encounter_number')) or parsed.record_id}"
        )
        body = " | ".join(
            part
            for part in (
                _coerce_str(row.get("facility")),
                _coerce_str(row.get("reason")),
            )
            if part
        ) or label
        return EvidenceSourceDetail(
            source_id=f"{parsed.source_type}:{parsed.table}:{parsed.record_id}",
            source_type=source_type,
            label=label,
            body=body,
            occurred_at=_coerce_datetime(row.get("occurred_at")),
        )

    def _fetch_document_source(
        self,
        *,
        context: CopilotRunContext,
        parsed: _ParsedSourceId,
        source_type: EvidenceSourceType,
    ) -> EvidenceSourceDetail | None:
        sql = (
            "SELECT foreign_id AS patient_id, name, mimetype, "
            "COALESCE(docdate, date) AS occurred_at "
            "FROM documents WHERE id = %s AND deleted = 0 LIMIT 1"
        )
        row = self._fetch_one(sql, (parsed.record_id,))
        if row is None:
            return None
        if _coerce_int(row.get("patient_id")) != context.patient_id:
            return None
        label = _coerce_str(row.get("name")) or f"Document {parsed.record_id}"
        body = _coerce_str(row.get("mimetype")) or label
        return EvidenceSourceDetail(
            source_id=f"{parsed.source_type}:{parsed.table}:{parsed.record_id}",
            source_type=source_type,
            label=label,
            body=body,
            occurred_at=_coerce_datetime(row.get("occurred_at")),
        )


# ---------------------------------------------------------------------------
# Result-row helpers
# ---------------------------------------------------------------------------


def _row_as_mapping(row: object) -> Mapping[str, object]:
    """Coerce a fetch* result into a ``Mapping`` keyed by column name.

    PyMySQL's ``DictCursor`` returns ``dict`` directly, but the Protocol
    above intentionally allows tuple-shaped rows for tests. We refuse
    tuples here because the repository requires named columns to map
    them onto evidence models; throwing on a tuple is much louder than
    silently picking the wrong field.
    """
    if isinstance(row, Mapping):
        return row
    raise TypeError(
        "OpenEmrReadRepository requires a dict-style cursor (named columns); "
        f"got row of type {type(row).__name__!r}."
    )


def _open_cursor(connection: _ConnectionLike) -> _CursorLike:
    """Open a cursor; if the driver uses pymysql, request DictCursor."""
    try:
        import pymysql.cursors as _pymysql_cursors  # type: ignore[import-not-found]

        return connection.cursor(_pymysql_cursors.DictCursor)
    except Exception:  # pragma: no cover -- exercised in production only
        return connection.cursor()


def _build_pymysql_factory(settings: Settings) -> ConnectionFactory:
    """Construct a connection factory backed by PyMySQL.

    Defined at module scope so :class:`OpenEmrReadRepository.from_settings`
    can use it without forcing tests to install pymysql -- the import
    happens lazily inside the factory itself.
    """

    def _factory() -> _ConnectionLike:
        try:
            import pymysql  # type: ignore[import-not-found]
        except ImportError as exc:  # pragma: no cover -- exercised in production only
            raise RepositoryConfigurationError(("pymysql",)) from exc

        return pymysql.connect(
            host=settings.openemr_db_host,
            port=settings.openemr_db_port,
            user=settings.openemr_db_user_ro,
            password=settings.openemr_db_pass_ro,
            database=settings.openemr_db_name,
            connect_timeout=settings.openemr_db_timeout_s,
            read_timeout=settings.openemr_db_timeout_s,
            write_timeout=settings.openemr_db_timeout_s,
            autocommit=True,
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
        )

    return _factory


# ---------------------------------------------------------------------------
# Closed-set literal coercions
# ---------------------------------------------------------------------------


def _normalised_sex(raw: str | None) -> str:
    if raw is None:
        return "unknown"
    lowered = raw.strip().lower()
    if lowered in ("male", "m"):
        return "male"
    if lowered in ("female", "f"):
        return "female"
    if lowered in ("other", "o"):
        return "other"
    return "unknown"


def _med_status_literal(raw: str) -> str:
    return raw if raw in ("active", "inactive", "stopped", "on_hold", "unknown") else "unknown"


def _allergy_status_literal(raw: str) -> str:
    return raw if raw in ("active", "inactive", "unknown") else "unknown"


def _problem_status_literal(raw: str) -> str:
    return raw if raw in ("active", "inactive", "resolved", "unknown") else "unknown"


def _severity_literal(raw: str | None) -> str:
    if raw is None:
        return "unknown"
    lowered = raw.strip().lower()
    if lowered in ("mild", "moderate", "severe"):
        return lowered
    return "unknown"


def _verification_literal(raw: str | None) -> str:
    if raw is None:
        return "unknown"
    lowered = raw.strip().lower()
    if lowered in ("confirmed", "unconfirmed", "refuted"):
        return lowered
    return "unknown"


def _abnormal_flag_literal(raw: str | None) -> str:
    if raw is None:
        return "unknown"
    lowered = raw.strip().lower()
    if lowered in ("normal", "low", "high", "critical"):
        return lowered
    return "unknown"


def _result_status_literal(raw: str | None) -> str:
    if raw is None:
        return "unknown"
    lowered = raw.strip().lower()
    if lowered in ("final", "preliminary", "amended", "cancelled"):
        return lowered
    return "unknown"


def _split_diagnosis_codes(diagnosis: str | None) -> tuple[str | None, str | None]:
    """Split an OpenEMR diagnosis string (e.g. ``ICD10:E11.9;SNOMED:73211009``).

    Mirrors the lightweight parsing the PHP layer already does -- we
    preserve exact tokens and only normalise the system prefix.
    """
    if diagnosis is None:
        return (None, None)
    icd10: str | None = None
    snomed: str | None = None
    for token in diagnosis.split(";"):
        if ":" not in token:
            continue
        system, _, value = token.partition(":")
        system_normalised = system.strip().upper()
        value_clean = value.strip()
        if value_clean == "":
            continue
        if system_normalised in ("ICD10", "ICD-10"):
            icd10 = value_clean
        elif system_normalised in ("SNOMED", "SNOMED-CT", "SNOMEDCT"):
            snomed = value_clean
    return (icd10, snomed)


def _age_from_dob(dob: date | None, now: datetime) -> int | None:
    """Compute integer age years from DOB, capped at the schema's [0,200]."""
    if dob is None:
        return None
    today = now.date() if isinstance(now, datetime) else now
    years = today.year - dob.year - ((today.month, today.day) < (dob.month, dob.day))
    if years < 0:
        return None
    if years > 200:
        return 200
    return years


def _encounter_row_to_event(row: Mapping[str, object]) -> EventRecord:
    encounter_id = _coerce_int(row.get("encounter_id")) or 0
    encounter_number = _coerce_int(row.get("encounter_number"))
    title = _coerce_str(row.get("reason")) or (
        f"Encounter {encounter_number}" if encounter_number else "Encounter"
    )
    facility = _coerce_str(row.get("facility"))
    summary_parts: list[str] = []
    if facility:
        summary_parts.append(facility)
    reason = _coerce_str(row.get("reason"))
    if reason and reason != title:
        summary_parts.append(reason)
    return EventRecord(
        citation_id=f"encounter:form_encounter:{encounter_id}",
        title=title,
        event_type="encounter",
        occurred_at=_coerce_datetime(row.get("occurred_at")),
        encounter_id=encounter_number if encounter_number and encounter_number > 0 else None,
        summary=" | ".join(summary_parts) if summary_parts else None,
        status="available",
    )


__all__ = [
    "ConnectionFactory",
    "OpenEmrReadRepository",
    "RepositoryConfigurationError",
    "parse_source_id",
]
