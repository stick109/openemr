"""Typed evidence record models for the sidecar chart copilot (M8).

Step M8 of ``Clinical Co-Pilot Migration to Python Sidecar.md`` ports the
PHP evidence shapes (``src/Services/Agent/Evidence/``) over to Pydantic v2
so the read-only evidence tools introduced in M10 / M11 / M12 can return
structurally validated, PHI-safe records that the answer builder (M14)
can iterate without re-shaping.

Design constraints
------------------
- **Strict by construction.** Every model uses
  ``ConfigDict(extra="forbid", strict=True, frozen=True)``. Unknown keys
  and silent type coercions both fail at validation time. Models are
  immutable once built (value-object semantics).
- **Patient identity stays in the run context.** Per the M3 token model,
  ``patient_id`` / ``encounter_id`` are authoritative on the
  :class:`agent_service.auth.copilot_run_context.CopilotRunContext` and
  are never carried as fields on individual records. Tools always
  receive their patient scope from the verified token, not from a record
  field that could be tampered with.
- **Citation IDs round-trip verbatim.** The PHP normalizer
  (``EvidencePacketNormalizer``) emits citation IDs of the shape
  ``<source_type>:<table>:<record_id>`` (see the parity fixtures under
  ``tests/fixtures/copilot_parity/``). The chart UI
  (``interface/patient_file/summary/agent_panel.js``) treats them as
  opaque strings -- so we keep them as plain ``str`` here and pass them
  through without re-derivation.
- **No bare ``Any`` and no bare ``dict[str, Any]``.** Every field has a
  precise native type, ``Literal[...]`` for closed-set strings, and
  ``Enum`` where the closed set is shared across modules.
- **Discriminated union of records.** ``EvidenceRecord`` is a
  Pydantic-discriminated union over ``record_type``; deserialization
  picks the right subtype automatically and PHPStan-style exhaustiveness
  is preserved for downstream ``match`` statements.

The :class:`EvidenceEnvelope` returned by every read-only evidence tool
mirrors the PHP packet shape (``records`` / ``sources`` / ``checked_evidence``
plus tool metadata) but in a strictly typed form: callers can rely on
``EvidenceEnvelope.records`` being a homogeneous tuple of typed
:class:`PatientDemographics` / :class:`MedicationRecord` / etc., and on
``EvidenceEnvelope.sources`` being a tuple of the existing
:class:`agent_service.schemas.copilot.Citation` model (the wire-contract
citation introduced in M2 -- not redefined here).
"""

from __future__ import annotations

from datetime import date, datetime
from enum import Enum
from typing import Annotated, Literal, Union

from pydantic import BaseModel, ConfigDict, Field

from agent_service.schemas.copilot import Citation


# ---------------------------------------------------------------------------
# Shared model configuration
# ---------------------------------------------------------------------------


_STRICT_FROZEN = ConfigDict(extra="forbid", strict=True, frozen=True)


# ---------------------------------------------------------------------------
# Source-type taxonomy
# ---------------------------------------------------------------------------


class EvidenceSourceType(str, Enum):
    """Closed-set source taxonomy used across evidence tools and citations.

    Values track the strings already emitted by the PHP layer so the
    chart UI's citation links round-trip unchanged. New values must be
    added to **both** sides (``EvidencePacketNormalizer::sourceId``
    construction and this enum) to preserve parity.
    """

    DEMOGRAPHICS = "demographics"
    ADDRESS = "address"
    TELECOM = "telecom"
    EMPLOYER = "employer"
    MEDICATION = "medication"
    MEDICATION_REVIEW = "medication_review"
    ALLERGY = "allergy"
    ALLERGY_REVIEW = "allergy_review"
    PROBLEM = "problem"
    RESULT = "result"
    ENCOUNTER = "encounter"
    DOCUMENT = "document"


# ---------------------------------------------------------------------------
# Closed-set status / reliability literals
# ---------------------------------------------------------------------------


# These mirror the strings produced by ``SqlEvidenceRecordRepository`` and
# accepted by ``AgentEvidenceResponseBuilder::certainty`` /
# ``EvidencePacketNormalizer``. Keeping them as ``Literal`` aliases avoids a
# proliferation of single-use enums while still preventing typos.
RecordStatus = Literal[
    "active",
    "inactive",
    "available",
    "reviewed",
    "stopped",
    "on_hold",
    "completed",
    "unknown",
]
"""Closed set of record-level status strings produced by the PHP repo."""


CertaintyMarker = Literal[
    "active",
    "inactive",
    "unknown",
    "conflicting",
    "not_found",
    "not_checked",
    "supported",
    "source_record",
]
"""Closed set of certainty markers consumed by the verifier."""


# ---------------------------------------------------------------------------
# Per-source typed records
# ---------------------------------------------------------------------------


class PatientDemographics(BaseModel):
    """Patient demographic record (one per patient).

    Mirrors ``SqlEvidenceRecordRepository::mapDemographicsRecord``. Patient
    identity (``pid`` / ``uuid``) lives on the run context, so this model
    only carries clinically displayable fields.
    """

    model_config = _STRICT_FROZEN

    record_type: Literal["patient_demographics"] = "patient_demographics"
    citation_id: Annotated[
        str,
        Field(min_length=1, description="UI-visible citation ID (round-trips PHP)."),
    ]
    age: int | None = Field(default=None, ge=0, le=200, description="Age in years.")
    sex: Literal["male", "female", "other", "unknown"] = Field(
        default="unknown",
        description="Administrative sex; mirrors the PHP option list.",
    )
    preferred_language: str | None = Field(
        default=None,
        description="Patient's preferred language label (free text).",
    )
    pronouns: str | None = Field(
        default=None,
        description="Self-reported pronouns when recorded.",
    )
    primary_provider_npi: str | None = Field(
        default=None,
        description="NPI of the primary provider, when recorded.",
    )


class MedicationRecord(BaseModel):
    """Active medication-list / prescription record.

    Mirrors ``SqlEvidenceRecordRepository::mapMedicationRecord``. The
    ``status`` field is the deterministic verifier marker
    (``active`` / ``inactive`` / ``unknown``) -- the verbatim row status
    from the database is normalised by the PHP layer before reaching here.
    """

    model_config = _STRICT_FROZEN

    record_type: Literal["medication"] = "medication"
    citation_id: Annotated[
        str,
        Field(min_length=1, description="UI-visible citation ID (round-trips PHP)."),
    ]
    name: Annotated[str, Field(min_length=1, description="Medication display name.")]
    rxnorm_code: str | None = Field(default=None, description="RxNorm code if coded.")
    dose: str | None = Field(default=None, description="Free-text dose, e.g. '10 mg'.")
    route: str | None = Field(default=None, description="Route of administration.")
    schedule: str | None = Field(default=None, description="Dosing schedule / sig.")
    start_date: date | None = Field(default=None, description="Begdate from lists.")
    stop_date: date | None = Field(default=None, description="Enddate from lists.")
    status: Literal["active", "inactive", "stopped", "on_hold", "unknown"] = Field(
        description="Normalised activity flag from the medication list.",
    )


class AllergyRecord(BaseModel):
    """Patient allergy / adverse reaction record.

    Mirrors ``SqlEvidenceRecordRepository::mapAllergyRecord``.
    """

    model_config = _STRICT_FROZEN

    record_type: Literal["allergy"] = "allergy"
    citation_id: Annotated[
        str,
        Field(min_length=1, description="UI-visible citation ID (round-trips PHP)."),
    ]
    allergen: Annotated[str, Field(min_length=1, description="Allergen display name.")]
    coded_allergen: str | None = Field(default=None, description="Coded allergen, if any.")
    reaction: str | None = Field(default=None, description="Reaction description.")
    severity: Literal["mild", "moderate", "severe", "unknown"] = Field(
        default="unknown",
        description="Reaction severity bucket.",
    )
    verification_status: Literal["confirmed", "unconfirmed", "refuted", "unknown"] = Field(
        default="unknown",
        description="FHIR-aligned verification status.",
    )
    onset_date: date | None = Field(default=None, description="Date of first reaction.")
    status: Literal["active", "inactive", "unknown"] = Field(
        description="Activity flag for the allergy list entry.",
    )


class ProblemRecord(BaseModel):
    """Patient problem-list entry.

    Mirrors active rows from the OpenEMR ``lists`` table where
    ``type = 'medical_problem'``.
    """

    model_config = _STRICT_FROZEN

    record_type: Literal["problem"] = "problem"
    citation_id: Annotated[
        str,
        Field(min_length=1, description="UI-visible citation ID (round-trips PHP)."),
    ]
    title: Annotated[str, Field(min_length=1, description="Problem display name.")]
    icd10_code: str | None = Field(default=None, description="ICD-10 code, if coded.")
    snomed_code: str | None = Field(default=None, description="SNOMED-CT code, if coded.")
    onset_date: date | None = Field(default=None, description="Begdate from lists.")
    resolved_date: date | None = Field(default=None, description="Enddate from lists.")
    status: Literal["active", "inactive", "resolved", "unknown"] = Field(
        description="Activity / resolution flag for the problem list entry.",
    )


class ResultRecord(BaseModel):
    """Discrete lab / observation result.

    Mirrors discrete observation rows surfaced by
    ``SqlEvidenceRecordRepository`` for ``recent_events`` and
    ``changed_since_last_visit`` lookups.
    """

    model_config = _STRICT_FROZEN

    record_type: Literal["result"] = "result"
    citation_id: Annotated[
        str,
        Field(min_length=1, description="UI-visible citation ID (round-trips PHP)."),
    ]
    name: Annotated[str, Field(min_length=1, description="Result / analyte name.")]
    loinc_code: str | None = Field(default=None, description="LOINC code, if coded.")
    value: str | None = Field(
        default=None,
        description="Result value as displayed (units kept in ``unit``).",
    )
    unit: str | None = Field(default=None, description="Result unit, when known.")
    reference_range: str | None = Field(
        default=None,
        description="Reference range string, when available.",
    )
    abnormal_flag: Literal["normal", "low", "high", "critical", "unknown"] = Field(
        default="unknown",
        description="Normalised abnormal flag from the procedure_result row.",
    )
    observed_at: datetime | None = Field(
        default=None,
        description="When the observation was recorded.",
    )
    status: Literal["final", "preliminary", "amended", "cancelled", "unknown"] = Field(
        default="unknown",
        description="Result lifecycle status.",
    )


class EventRecord(BaseModel):
    """Encounter / procedure / clinical-event record.

    Mirrors ``SqlEvidenceRecordRepository::mapEncounterEventRecord`` and
    its sibling event mappers. Encounters and procedures share the same
    shape at this layer; the originating table is encoded in the
    citation ID, not the model.
    """

    model_config = _STRICT_FROZEN

    record_type: Literal["event"] = "event"
    citation_id: Annotated[
        str,
        Field(min_length=1, description="UI-visible citation ID (round-trips PHP)."),
    ]
    title: Annotated[str, Field(min_length=1, description="Event display title.")]
    event_type: Literal["encounter", "procedure", "lab", "vital", "note", "other"] = Field(
        description="Coarse event taxonomy used by the answer builder.",
    )
    occurred_at: datetime | None = Field(
        default=None,
        description="When the event was recorded.",
    )
    encounter_id: int | None = Field(
        default=None,
        gt=0,
        description="OpenEMR encounter ID this event belongs to, if any.",
    )
    summary: str | None = Field(
        default=None,
        description="Short, bounded summary text shown in the citation list.",
    )
    status: Literal["available", "completed", "cancelled", "unknown"] = Field(
        default="available",
        description="Event lifecycle status.",
    )


# ---------------------------------------------------------------------------
# Discriminated union of evidence records
# ---------------------------------------------------------------------------


EvidenceRecord = Annotated[
    Union[
        PatientDemographics,
        MedicationRecord,
        AllergyRecord,
        ProblemRecord,
        ResultRecord,
        EventRecord,
    ],
    Field(discriminator="record_type"),
]
"""Pydantic-discriminated union over ``record_type``.

Deserialising a heterogeneous list of records picks the right subtype by
the ``record_type`` tag. Downstream code can then ``match`` on
``record_type`` exhaustively (PHPStan-style: every branch enumerates one
of the literal tags above).
"""


# ---------------------------------------------------------------------------
# Source detail (drilldown payload)
# ---------------------------------------------------------------------------


class EvidenceSourceDetail(BaseModel):
    """Bounded source-drilldown payload returned by ``get_source_detail``.

    Mirrors the bounded view emitted by the PHP ``show_source`` flow
    (``AgentEvidenceResponseBuilder::sourceRequiredResponse`` plus
    ``EvidencePacketNormalizer::normalize`` for the resolved row). The
    ``body`` field is capped by the M11 tool layer; this model enforces
    only the structural shape.
    """

    model_config = _STRICT_FROZEN

    source_id: Annotated[
        str,
        Field(min_length=1, description="Citation ID being inspected."),
    ]
    source_type: EvidenceSourceType = Field(
        description="Source taxonomy bucket the row belongs to.",
    )
    label: Annotated[
        str,
        Field(min_length=1, description="Human-readable label for the source."),
    ]
    body: str = Field(
        description="Bounded display body for the source row (capped by M11).",
    )
    occurred_at: datetime | None = Field(
        default=None,
        description="When the underlying row was recorded, if applicable.",
    )
    parent_record_id: str | None = Field(
        default=None,
        description="Citation ID of a parent record, e.g. an encounter for a procedure.",
    )


# ---------------------------------------------------------------------------
# Scope summary and envelope
# ---------------------------------------------------------------------------


class ScopeSummary(BaseModel):
    """Per-call scope describing the bounded read the tool actually performed.

    The answer builder uses this to render ``checked_evidence`` strings
    and to surface honest "we only looked at X" disclosures to clinicians.
    """

    model_config = _STRICT_FROZEN

    patient_id_present: bool = Field(
        description="Whether the run context carried a patient ID at execution time.",
    )
    encounter_id_present: bool = Field(
        description="Whether the run context carried an encounter ID at execution time.",
    )
    lookback_days_used: int | None = Field(
        default=None,
        ge=0,
        description="Effective lookback window applied (days); ``null`` if not date-bounded.",
    )
    max_rows_used: Annotated[
        int,
        Field(ge=0, description="Effective row cap applied (the tool/context minimum)."),
    ]
    truncated: bool = Field(
        description="True when the underlying query returned more rows than the cap.",
    )
    source_types_checked: tuple[EvidenceSourceType, ...] = Field(
        default=(),
        description="Source-type buckets actually consulted during this read.",
    )


class EvidenceEnvelope(BaseModel):
    """Normalised result envelope returned by every read-only evidence tool.

    This is the structural contract M10 / M11 / M12 honour and that the
    M14 answer builder iterates. The shape mirrors the PHP packet:

    * ``records``: typed clinical rows (discriminated union).
    * ``sources``: PHI-safe wire-contract :class:`Citation` objects --
      reuses the existing M2 model rather than redefining citations.
    * ``tool_name``: which tool produced this envelope (e.g.
      ``get_current_medications``).
    * ``warnings``: human-readable caveats the answer builder may surface.
    * ``checked_scope``: bounded-read disclosure for the UI.
    """

    model_config = _STRICT_FROZEN

    records: tuple[EvidenceRecord, ...] = Field(
        description="Typed clinical rows; empty tuple when no records were found.",
    )
    sources: tuple[Citation, ...] = Field(
        description="Citations backing the records; one per cited row.",
    )
    tool_name: Annotated[
        str,
        Field(min_length=1, description="Name of the tool that produced this envelope."),
    ]
    warnings: tuple[str, ...] = Field(
        default=(),
        description="Human-readable caveats (e.g. 'evidence may be incomplete').",
    )
    checked_scope: ScopeSummary = Field(
        description="Bounded-read disclosure for the UI / answer builder.",
    )


# ---------------------------------------------------------------------------
# Public re-exports
# ---------------------------------------------------------------------------


__all__ = [
    "AllergyRecord",
    "CertaintyMarker",
    "EventRecord",
    "EvidenceEnvelope",
    "EvidenceRecord",
    "EvidenceSourceDetail",
    "EvidenceSourceType",
    "MedicationRecord",
    "PatientDemographics",
    "ProblemRecord",
    "RecordStatus",
    "ResultRecord",
    "ScopeSummary",
]
