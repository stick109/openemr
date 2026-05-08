"""Typed request/response models for the agent sidecar HTTP contract.

These Pydantic v2 models enforce the constraints defined in CONTRACT.md (v1.0.0).
Both the FastAPI route layer and tests validate against these schemas.
"""

from __future__ import annotations

import uuid
from enum import StrEnum
from typing import Annotated, Literal, Union

from pydantic import BaseModel, Field, field_validator


# ---------------------------------------------------------------------------
# Enums
# ---------------------------------------------------------------------------


class DocType(StrEnum):
    """Document classification hint sent by the PHP host."""

    LAB_PDF = "lab_pdf"
    INTAKE_FORM = "intake_form"
    AUTO = "auto"


# ---------------------------------------------------------------------------
# Request
# ---------------------------------------------------------------------------


class AgentRunRequest(BaseModel):
    """POST /api/agent/run request body.

    See CONTRACT.md -- Request Body.
    """

    patient_id: Annotated[int, Field(gt=0, description="OpenEMR internal patient ID (pid)")]
    file_path: Annotated[str, Field(min_length=1, description="Absolute path on the shared volume")]
    doc_type: DocType = Field(description="Document classification hint")
    encounter_id: Annotated[int, Field(gt=0, description="OpenEMR encounter ID")]
    trace_id: Annotated[str, Field(min_length=1, description="UUID v4 correlation ID")]

    @field_validator("trace_id")
    @classmethod
    def _validate_trace_id_is_uuid(cls, value: str) -> str:
        """Ensure trace_id is a valid UUID v4 string."""
        try:
            parsed = uuid.UUID(value, version=4)
        except ValueError as exc:
            raise ValueError(f"trace_id must be a valid UUID v4, got: {value!r}") from exc
        # Normalise to lowercase with hyphens.
        return str(parsed)


# ---------------------------------------------------------------------------
# Citation (discriminated union)
# ---------------------------------------------------------------------------


class PdfBboxCitation(BaseModel):
    """Citation pointing to a bounding box in the uploaded PDF."""

    source_type: Literal["pdf_bbox"]
    page: Annotated[int, Field(ge=1, description="1-based page number")]
    bbox: Annotated[
        list[float],
        Field(min_length=4, max_length=4, description="Bounding box [x0, y0, x1, y1] in PDF points"),
    ]
    field_name: Annotated[
        str,
        Field(
            min_length=1,
            description=(
                "Name of the extracted field this bbox covers. The PHP host "
                "joins this against persisted result rows (case-insensitive) "
                "to wire UI hover/click overlays — see "
                "interface/forms/upload_intake_form/view.php."
            ),
        ),
    ]


class GuidelineCitation(BaseModel):
    """Citation pointing to a chunk from the guideline knowledge base."""

    source_type: Literal["guideline"]
    chunk_id: Annotated[str, Field(min_length=1, description="Unique guideline chunk identifier")]
    source_url: Annotated[str, Field(min_length=1, description="URL of the source guideline document")]
    snippet: Annotated[str, Field(min_length=1, description="Verbatim text excerpt from the guideline")]


Citation = Annotated[
    Union[PdfBboxCitation, GuidelineCitation],
    Field(discriminator="source_type"),
]


# ---------------------------------------------------------------------------
# Response
# ---------------------------------------------------------------------------


class AgentRunResponse(BaseModel):
    """POST /api/agent/run success response (HTTP 200).

    See CONTRACT.md -- Success Response.
    """

    extracted: dict[str, object] = Field(description="Structured extraction result; schema varies by doc_type")
    evidence: list[dict[str, object]] = Field(description="Retrieved guideline snippets with citation metadata")
    answer: str = Field(description="Natural-language clinical summary")
    citations: list[Citation] = Field(description="Source citations for the answer")
    cost_usd: float = Field(ge=0, description="Estimated cost of this run in USD")
    latency_ms_per_step: dict[str, int] = Field(description="Timing breakdown keyed by step name (ms)")
    tool_sequence: list[str] = Field(description="Ordered list of tool/worker names invoked")
    extraction_confidence: Annotated[
        float,
        Field(ge=0.0, le=1.0, description="Model confidence in the extraction"),
    ]


# ---------------------------------------------------------------------------
# Error response
# ---------------------------------------------------------------------------


class AgentErrorResponse(BaseModel):
    """Error envelope returned for all 4xx/5xx responses.

    See CONTRACT.md -- Error Response.
    """

    error: Annotated[str, Field(min_length=1, description="Machine-readable error code")]
    detail: Annotated[str, Field(min_length=1, description="Human-readable explanation")]
    trace_id: Annotated[str, Field(min_length=1, description="Echo of the request trace_id")]
