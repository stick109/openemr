"""Pydantic v2 models for structured lab-PDF extraction results.

A ``LabPdf`` groups one or more ``LabResult`` rows that were extracted from a
single uploaded PDF.  Every result carries a mandatory ``source_citation``
linking it back to the exact page and bounding box in the original document.
"""

from __future__ import annotations

from enum import StrEnum
from typing import Annotated

from pydantic import BaseModel, Field

from agent_service.schemas.citation import SourceCitation


class AbnormalFlag(StrEnum):
    """Flags indicating whether a lab value is within the reference range."""

    NORMAL = "normal"
    HIGH = "high"
    LOW = "low"
    CRITICAL_HIGH = "critical_high"
    CRITICAL_LOW = "critical_low"
    ABNORMAL = "abnormal"


class LabResult(BaseModel):
    """A single test row extracted from a lab report PDF."""

    test_name: Annotated[str, Field(min_length=1, description="Name of the lab test")]
    value: Annotated[str, Field(min_length=1, description="Reported value (kept as string to preserve formatting)")]
    unit: Annotated[str, Field(min_length=1, description="Unit of measurement (e.g. g/dL, mmol/L)")]
    reference_range: Annotated[str, Field(min_length=1, description="Reference range as printed on the report")]
    collection_date: Annotated[str, Field(min_length=1, description="Specimen collection date (ISO 8601 preferred)")]
    abnormal_flag: AbnormalFlag = Field(description="Whether the value is within normal limits")
    source_citation: SourceCitation = Field(description="PDF location this result was extracted from")


class LabPdf(BaseModel):
    """Top-level extraction result for a lab-report PDF."""

    results: Annotated[
        list[LabResult],
        Field(min_length=1, description="Extracted lab results (at least one required)"),
    ]
    extraction_confidence: Annotated[
        float,
        Field(ge=0.0, le=1.0, description="Model confidence in the overall extraction"),
    ]
    patient_name: str | None = Field(default=None, description="Patient name if found on the report")
    ordering_provider: str | None = Field(default=None, description="Ordering provider if found on the report")
    lab_name: str | None = Field(default=None, description="Laboratory name if found on the report")
