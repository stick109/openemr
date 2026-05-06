"""Extractor worker for the LangGraph agent pipeline.

Accepts a graph state dict containing ``file_path``, ``doc_type``, and
``trace_id``, uploads the PDF via the LLM client, extracts structured data
against the appropriate Pydantic schema, and returns the updated state.

Retry policy
------------
If the LLM response fails Pydantic validation, the worker retries **once**
with the validation errors appended to the prompt so the model can self-
correct.  If the second attempt also fails, the worker returns a refusal
event -- it never surfaces partial / invalid extraction as success.
"""

from __future__ import annotations

import logging
from typing import Any

from pydantic import ValidationError

from agent_service.clients.openai_client import LLMClient
from agent_service.schemas.api import DocType
from agent_service.schemas.intake_form import IntakeForm
from agent_service.schemas.lab_pdf import LabPdf

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Prompt templates
# ---------------------------------------------------------------------------

_LAB_PDF_PROMPT = (
    "Extract all lab results from this PDF into a structured JSON object "
    "conforming to the LabPdf schema.  Include every test row with its "
    "name, value, unit, reference range, collection date, abnormal flag, "
    "and the source citation (page number and bounding box).  Also include "
    "patient_name, ordering_provider, and lab_name if present.  Provide an "
    "extraction_confidence between 0 and 1."
)

_INTAKE_FORM_PROMPT = (
    "Extract all patient intake information from this PDF into a structured "
    "JSON object conforming to the IntakeForm schema.  Include demographics, "
    "chief concern, current medications, allergies, family history, and "
    "source citations (page number and bounding box).  Provide an "
    "extraction_confidence between 0 and 1."
)

_AUTO_DETECT_PROMPT = (
    "Determine whether this PDF is a lab report or an intake form and "
    "extract all relevant data accordingly.  If it is a lab report, conform "
    "to the LabPdf schema.  If it is an intake form, conform to the "
    "IntakeForm schema.  Provide an extraction_confidence between 0 and 1."
)

_SCHEMA_FOR_DOC_TYPE: dict[DocType, type[LabPdf] | type[IntakeForm]] = {
    DocType.LAB_PDF: LabPdf,
    DocType.INTAKE_FORM: IntakeForm,
}

_PROMPT_FOR_DOC_TYPE: dict[DocType, str] = {
    DocType.LAB_PDF: _LAB_PDF_PROMPT,
    DocType.INTAKE_FORM: _INTAKE_FORM_PROMPT,
    DocType.AUTO: _AUTO_DETECT_PROMPT,
}


# ---------------------------------------------------------------------------
# Internal helpers
# ---------------------------------------------------------------------------


def _resolve_auto_doc_type(raw: dict[str, Any]) -> DocType:
    """Infer ``DocType`` from an extraction dict returned in *auto* mode.

    Heuristic: if the dict contains a ``results`` key (LabPdf's top-level
    list of lab rows), treat it as a lab PDF; if it contains a
    ``chief_concern`` key (IntakeForm's required field), treat it as an
    intake form.  Falls back to ``lab_pdf`` when ambiguous.
    """
    if "chief_concern" in raw:
        return DocType.INTAKE_FORM
    return DocType.LAB_PDF


def _validate_extraction(
    raw: dict[str, Any],
    schema: type[LabPdf] | type[IntakeForm],
) -> LabPdf | IntakeForm:
    """Validate *raw* against *schema* and return a model instance.

    Raises ``ValidationError`` on failure.
    """
    return schema.model_validate(raw)


def _build_retry_prompt(base_prompt: str, errors: str) -> str:
    """Append validation feedback to the base prompt for a retry attempt."""
    return (
        f"{base_prompt}\n\n"
        "--- VALIDATION ERRORS FROM PREVIOUS ATTEMPT ---\n"
        f"{errors}\n"
        "Please fix the above errors and return a corrected JSON object."
    )


# ---------------------------------------------------------------------------
# Public API
# ---------------------------------------------------------------------------


class ExtractionRefused(Exception):
    """Raised when extraction fails after retry and must not proceed."""

    def __init__(self, trace_id: str, errors: str) -> None:
        self.trace_id = trace_id
        self.errors = errors
        super().__init__(
            f"Extraction refused for trace_id={trace_id}: validation failed "
            f"after retry.  Errors: {errors}"
        )


class ExtractorWorker:
    """LangGraph node that extracts structured clinical data from a PDF.

    Parameters
    ----------
    llm_client:
        Any object satisfying the ``LLMClient`` protocol (real or fake).
    """

    def __init__(self, llm_client: LLMClient) -> None:
        self._llm = llm_client

    # -- public entry point ------------------------------------------------

    def run(self, state: dict[str, Any]) -> dict[str, Any]:
        """Execute the extraction step.

        Parameters
        ----------
        state:
            LangGraph state dict.  Required keys:

            * ``file_path`` -- absolute path to the uploaded PDF
            * ``doc_type``  -- one of ``"lab_pdf"``, ``"intake_form"``, ``"auto"``
            * ``trace_id``  -- UUID v4 correlation identifier

        Returns
        -------
        dict
            Updated state with ``extracted`` (model dict), ``doc_type``
            (resolved), and ``extraction_confidence``.  On refusal the dict
            contains an ``error`` key instead.
        """
        file_path: str = state["file_path"]
        doc_type = DocType(state["doc_type"])
        trace_id: str = state["trace_id"]

        logger.info(
            "Extractor starting",
            extra={"trace_id": trace_id, "doc_type": doc_type, "file_path": file_path},
        )

        # 1. Upload
        file_id = self._llm.upload_pdf(file_path)

        # 2. Determine schema & prompt
        prompt = _PROMPT_FOR_DOC_TYPE[doc_type]

        if doc_type == DocType.AUTO:
            schema: type[LabPdf] | type[IntakeForm] = LabPdf  # initial guess for auto
        else:
            schema = _SCHEMA_FOR_DOC_TYPE[doc_type]

        # 3. First extraction attempt
        raw = self._llm.extract_structured(file_id, schema, prompt)

        # For auto mode, resolve the actual doc type
        if doc_type == DocType.AUTO:
            resolved_type = _resolve_auto_doc_type(raw)
            schema = _SCHEMA_FOR_DOC_TYPE[resolved_type]
        else:
            resolved_type = doc_type

        # 4. Validate
        try:
            model = _validate_extraction(raw, schema)
        except ValidationError as first_err:
            logger.warning(
                "First extraction attempt failed validation, retrying",
                extra={
                    "trace_id": trace_id,
                    "errors": str(first_err),
                },
            )

            # 5. Retry with feedback
            retry_prompt = _build_retry_prompt(prompt, str(first_err))
            raw_retry = self._llm.extract_structured(file_id, schema, retry_prompt)

            # For auto mode on retry, re-resolve
            if doc_type == DocType.AUTO:
                resolved_type = _resolve_auto_doc_type(raw_retry)
                schema = _SCHEMA_FOR_DOC_TYPE[resolved_type]

            try:
                model = _validate_extraction(raw_retry, schema)
            except ValidationError as second_err:
                logger.error(
                    "Extraction refused after retry",
                    extra={
                        "trace_id": trace_id,
                        "errors": str(second_err),
                    },
                )
                return {
                    **state,
                    "error": (
                        f"Extraction validation failed after retry: {second_err}"
                    ),
                    "trace_id": trace_id,
                    "doc_type": resolved_type.value,
                }

        # 6. Success
        extracted = model.model_dump()
        confidence = extracted.get("extraction_confidence", 0.0)

        logger.info(
            "Extraction succeeded",
            extra={
                "trace_id": trace_id,
                "doc_type": resolved_type.value,
                "confidence": confidence,
            },
        )

        return {
            **state,
            "extracted": extracted,
            "doc_type": resolved_type.value,
            "extraction_confidence": confidence,
            "trace_id": trace_id,
        }
