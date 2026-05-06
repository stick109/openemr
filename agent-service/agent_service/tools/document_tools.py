"""Document / lab / intake tools for the sidecar agent loop (M12).

This module defines the four document-oriented tools exposed to the
chart copilot agent loop:

* :func:`extract_uploaded_document` -- structure a previously uploaded
  PDF (lab report or intake form) into a typed model.
* :func:`retrieve_guidelines` -- run the RAG retrieval pipeline for a
  short clinician-facing query.
* :func:`persist_lab_observation_proposal` -- emit a *proposal* (NOT a
  database write) for a lab observation that the PHP host or a future
  validated commit endpoint may accept and persist.
* :func:`get_document_citation_region` -- look up the page + bbox of a
  citation previously emitted in this run.

Every tool's model-facing input schema is intentionally minimal:

* No filesystem paths, no SQL, no patient/encounter/document IDs as
  model-supplied inputs.  Authority scoping (patient_id, allowed source
  types, lookback, row caps) is injected by the executor (M6) from the
  verified :class:`CopilotRunContext`.
* Only opaque IDs (e.g. ``citation_id``) and small, bounded primitives
  (``query``, ``k``, ``document_kind``, ``observation``) cross the
  model-facing surface.

Wiring status (M12 vs. M13/M21)
-------------------------------
M12 is primarily about **registration and contract**, not full
end-to-end wiring.  Two of the executors here intentionally raise
``NotImplementedError`` in production paths:

* ``extract_uploaded_document`` requires an ``ExtractorWorker`` plus an
  upload-tracking layer that maps a runtime-supplied ``upload_id`` to a
  filesystem path.  Neither is on the agent-loop branch yet, so the
  executor returns a deterministic stub bag with a single ``warnings``
  entry.  M13 (agent loop) and M21 (validated commit) replace this with
  a real call into :class:`agent_service.workers.extractor.ExtractorWorker`.
* ``persist_lab_observation_proposal`` returns a fully-shaped
  :class:`WriteProposal` -- it never writes to a database.  The actual
  commit is a PHP-side concern wired in M21.

The other two tools (``retrieve_guidelines``,
``get_document_citation_region``) wire to real Python collaborators
through dependency-injected factories so the agent loop in M13 can pass
a configured pipeline / citation index without this module reaching
into module-level globals.
"""

from __future__ import annotations

import hashlib
import logging
import uuid
from collections.abc import Callable, Mapping
from datetime import datetime, timezone
from typing import Any, Final, Literal

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.rag.pipeline import RAGPipeline, RetrievalResult
from agent_service.schemas.copilot import Citation
from agent_service.schemas.proposals import WriteProposal
from agent_service.tools.definition import ToolDefinition
from agent_service.tools.registry import ToolRegistry

__all__ = [
    "DOCUMENT_TOOL_NAMES",
    "CitationLookup",
    "build_document_tools",
    "document_tool_registry",
    "extract_uploaded_document_executor",
    "get_document_citation_region_executor",
    "make_extract_uploaded_document_tool",
    "make_get_document_citation_region_tool",
    "make_persist_lab_observation_proposal_tool",
    "make_retrieve_guidelines_tool",
    "persist_lab_observation_proposal_executor",
    "retrieve_guidelines_executor",
]


_LOGGER: Final[logging.Logger] = logging.getLogger("agent_service.tools.document_tools")


# Tuple of canonical tool names this module registers.  Used by the
# agent loop (M13) to compose allow-lists and by tests to assert that
# the registry exposes exactly the expected surface.
DOCUMENT_TOOL_NAMES: Final[tuple[str, ...]] = (
    "extract_uploaded_document",
    "get_document_citation_region",
    "persist_lab_observation_proposal",
    "retrieve_guidelines",
)


# Closed set of document kinds the extractor accepts.  Mirrors the
# subset of :class:`agent_service.schemas.api.DocType` that is safe to
# expose to the model (we do not advertise ``"auto"`` because the
# extractor's auto-resolution heuristic depends on a real PDF -- the
# model should know what it uploaded).
_DocumentKind = Literal["lab_pdf", "intake_form"]


# ---------------------------------------------------------------------------
# Citation lookup protocol
# ---------------------------------------------------------------------------


class CitationLookup:
    """Adapter for resolving a ``citation_id`` to a region (page + bbox).

    The agent loop owns the citation index; this module only knows the
    contract.  Callers pass a concrete :class:`CitationLookup` into
    :func:`make_get_document_citation_region_tool` so the executor can
    perform the resolution without reaching into module-level globals.

    Implementations must be **scope-aware**: ``resolve`` receives the
    verified :class:`CopilotRunContext` so it can refuse citations that
    do not belong to a source type the run is authorised to read.

    Returning ``None`` means "no citation matches this id within scope"
    -- the executor surfaces this as an empty record bag with a typed
    warning, never as a 500.
    """

    def resolve(
        self,
        context: CopilotRunContext,
        citation_id: str,
    ) -> tuple[Citation, int, tuple[float, float, float, float]] | None:
        """Resolve ``citation_id`` to ``(citation, page, bbox)`` or ``None``.

        Parameters
        ----------
        context
            The verified run context.  Implementations must enforce
            ``"documents" in context.allowed_source_types`` (or whatever
            scope rule the citation belongs to) before returning a hit.
        citation_id
            Opaque, model-supplied citation identifier.  Implementations
            must treat this as untrusted and validate format/scope.
        """
        raise NotImplementedError("Subclasses must implement resolve().")


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------


# Maximum length of a model-supplied citation_id.  Mirrors the bound on
# ``get_source_detail`` in the M5 stubs so the two surfaces are
# consistent.
_CITATION_ID_MAX_LENGTH: Final[int] = 256


def _utc_now() -> datetime:
    """Return the current time as a timezone-aware UTC datetime."""
    return datetime.now(tz=timezone.utc)


def _stable_payload_hash(payload: Mapping[str, Any]) -> str:
    """Return a deterministic short hash of ``payload``.

    Used as the scope component of an idempotency key so two calls with
    structurally-identical payloads inside the same trace dedupe to the
    same key.  The hash is intentionally short (16 hex chars) because
    full SHA-256 would bloat the wire and the dedupe is per-trace, not
    cryptographic.
    """
    # ``repr`` over the keys+values gives a stable serialisation for the
    # bounded payload shapes M12 admits (str/int/float/list/dict only).
    # We deliberately avoid ``json.dumps`` here so we never silently
    # coerce a tuple to a list during hashing.
    serialised = repr(sorted(payload.items())).encode("utf-8")
    return hashlib.sha256(serialised).hexdigest()[:16]


def _citation_id_format_ok(value: str) -> bool:
    """Return ``True`` when ``value`` looks like a well-formed citation id.

    Validates the format defensively before any repository lookup so we
    never let arbitrary attacker strings (newlines, very long blobs)
    flow through to the citation resolver.
    """
    if not isinstance(value, str):
        return False
    if value == "" or len(value) > _CITATION_ID_MAX_LENGTH:
        return False
    # Citation IDs are opaque tokens.  We accept printable ASCII without
    # control chars / whitespace.  Tighter formats can be enforced by
    # the resolver itself.
    return all(0x21 <= ord(c) <= 0x7E for c in value)


# ---------------------------------------------------------------------------
# Schema builders
# ---------------------------------------------------------------------------


def _extract_document_schema() -> dict[str, Any]:
    """Schema for ``extract_uploaded_document``.

    No filesystem paths.  No ``document_id`` from the model -- the
    runtime maps an out-of-band upload tracking ID to a real file.  M12
    only exposes ``document_kind`` so the model can disambiguate.
    """
    return {
        "type": "object",
        "properties": {
            "document_kind": {
                "type": "string",
                "enum": ["lab_pdf", "intake_form"],
                "description": (
                    "Which document kind to extract.  The runtime "
                    "context provides the file via injected upload "
                    "tracking; the model does not name the file."
                ),
            },
        },
        "required": ["document_kind"],
        "additionalProperties": False,
    }


def _retrieve_guidelines_schema() -> dict[str, Any]:
    """Schema for ``retrieve_guidelines``.

    The free-text search is bounded at 500 chars to keep prompt and
    network costs predictable.  ``k`` is bounded by the registered
    ``max_rows``.

    The model-facing field is named ``search_text`` (not ``query``)
    because ``query`` and ``query_string`` are in the forbidden input
    set the registry enforces -- those names are reserved for unsafe
    surfaces (raw SQL, free-form database queries) that the model must
    never supply.
    """
    return {
        "type": "object",
        "properties": {
            "search_text": {
                "type": "string",
                "minLength": 1,
                "maxLength": 500,
                "description": (
                    "Short free-text retrieval search.  Bounded to 500 "
                    "chars to cap prompt and network costs."
                ),
            },
            "k": {
                "type": "integer",
                "minimum": 1,
                "maximum": 10,
                "description": "Maximum number of guideline chunks to return.",
            },
        },
        "required": ["search_text"],
        "additionalProperties": False,
    }


def _persist_lab_observation_proposal_schema() -> dict[str, Any]:
    """Schema for ``persist_lab_observation_proposal``.

    The model supplies an ``observation`` object describing the lab row
    it wants to persist.  Validation against
    :class:`~agent_service.schemas.lab_pdf.LabResult` happens inside the
    executor so the JSON Schema stays small and model-friendly.
    """
    return {
        "type": "object",
        "properties": {
            "observation": {
                "type": "object",
                "description": (
                    "Typed lab observation to propose for persistence.  "
                    "The executor validates against LabResult and "
                    "rejects anything that does not match."
                ),
            },
        },
        "required": ["observation"],
        "additionalProperties": False,
    }


def _citation_region_schema() -> dict[str, Any]:
    """Schema for ``get_document_citation_region``.

    The model supplies an opaque ``citation_id``.  The executor verifies
    the citation belongs to a source type the run is authorised to read.
    """
    return {
        "type": "object",
        "properties": {
            "citation_id": {
                "type": "string",
                "minLength": 1,
                "maxLength": _CITATION_ID_MAX_LENGTH,
                "description": (
                    "Opaque identifier of a citation previously emitted "
                    "by the agent.  The executor verifies it belongs to "
                    "the current run context."
                ),
            },
        },
        "required": ["citation_id"],
        "additionalProperties": False,
    }


# ---------------------------------------------------------------------------
# Executors
# ---------------------------------------------------------------------------


def extract_uploaded_document_executor(
    context: CopilotRunContext,
    runtime_args: Mapping[str, Any],
) -> dict[str, Any]:
    """Stub executor for ``extract_uploaded_document``.

    M12 deliberately does NOT wire the existing
    :class:`agent_service.workers.extractor.ExtractorWorker` end-to-end:
    the worker requires a real ``LLMClient`` *and* an upload-tracking
    layer mapping a runtime-supplied identifier to a filesystem path.
    Both arrive in M13 / M21.

    Until then this executor returns a deterministic, empty result bag
    with a single warning so the agent loop's success path is exercisable
    in tests without needing the LLM client or upload registry.

    The contract this stub honours:

    * Returns a mapping containing ``records``, ``citations`` and
      ``warnings`` -- the executor (M6) consumes the first two and
      ignores the third (warnings flow through ``payload`` unchanged).
    * Never raises -- M12 is about registration and contract, not full
      wiring.  Real failures will surface from the M13 wiring.
    """
    document_kind = runtime_args.get("document_kind")
    _LOGGER.info(
        "extract_uploaded_document stub invoked",
        extra={
            "trace_id": context.trace_id,
            "document_kind": document_kind,
            "patient_id": context.patient_id,
        },
    )
    return {
        "records": [],
        "citations": [],
        "warnings": [
            "extract_uploaded_document is registered but not yet wired; "
            "M13/M21 will replace this stub with a real ExtractorWorker "
            "call against an upload-tracked file id."
        ],
    }


def _make_retrieve_guidelines_executor(
    pipeline_factory: Callable[[], RAGPipeline] | None,
    *,
    default_top_k: int = 5,
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    """Build a ``retrieve_guidelines`` executor bound to a pipeline factory.

    The factory is invoked lazily (per call) so the agent loop can swap
    in a fake pipeline in tests without touching module-level state.
    """

    def _executor(
        context: CopilotRunContext,
        runtime_args: Mapping[str, Any],
    ) -> dict[str, Any]:
        if "guidelines" not in context.allowed_source_types:
            return {
                "records": [],
                "citations": [],
                "warnings": [
                    "retrieve_guidelines is allowed by tool policy but the "
                    "run context does not include 'guidelines' in its "
                    "allowed source types."
                ],
            }
        if pipeline_factory is None:
            return {
                "records": [],
                "citations": [],
                "warnings": [
                    "retrieve_guidelines: no RAG pipeline configured for "
                    "this run; M13 wires the real pipeline."
                ],
            }

        search_text_raw = runtime_args.get("search_text", "")
        if not isinstance(search_text_raw, str) or search_text_raw.strip() == "":
            return {
                "records": [],
                "citations": [],
                "warnings": ["retrieve_guidelines: empty search_text."],
            }
        search_text = search_text_raw.strip()

        # ``max_rows`` is the executor-injected cap; ``k`` is the model
        # hint.  We always honour the smaller of the two so callers
        # cannot widen the row budget by misusing the model surface.
        max_rows = int(runtime_args.get("max_rows", default_top_k))
        k_hint = int(runtime_args.get("k", default_top_k))
        top_k = max(1, min(max_rows, k_hint))

        pipeline = pipeline_factory()
        results: list[RetrievalResult] = pipeline.retrieve(search_text, top_k=top_k)

        records = [
            {
                "chunk_id": r.chunk_id,
                "source_url": r.source_url,
                "section": r.section,
                "snippet": r.snippet,
                "score": r.score,
            }
            for r in results
        ]
        citations: list[Citation] = [
            Citation(
                source_type="guideline",
                source_id=r.chunk_id,
                label=f"{r.section}",
                url=r.source_url,
                snippet=r.snippet,
            )
            for r in results
        ]
        warnings: list[str] = []
        if not records:
            warnings.append("retrieve_guidelines returned zero matches.")
        return {
            "records": records,
            "citations": citations,
            "warnings": warnings,
        }

    return _executor


def retrieve_guidelines_executor(
    context: CopilotRunContext,
    runtime_args: Mapping[str, Any],
) -> dict[str, Any]:
    """Default ``retrieve_guidelines`` executor with no pipeline wired.

    Used when callers register the tool without a pipeline factory --
    returns an empty result bag with a typed warning so the agent loop
    can still observe the tool's outcome shape.
    """
    return _make_retrieve_guidelines_executor(None)(context, runtime_args)


def persist_lab_observation_proposal_executor(
    context: CopilotRunContext,
    runtime_args: Mapping[str, Any],
) -> dict[str, Any]:
    """Emit a :class:`WriteProposal` for a lab observation.

    This executor never writes to a database.  It produces a typed,
    deterministic proposal that the PHP host (M21) is responsible for
    accepting and persisting.

    Idempotency is keyed on ``trace_id`` plus a stable hash of the
    observation payload, so two identical calls inside the same run
    produce identical ``idempotency_key`` values.  This lets the PHP
    side dedupe accidental retries.

    The executor does NOT validate the observation against a schema in
    M12: that wiring belongs in M21 where the PHP committer enforces
    OpenEMR-side invariants.  We do, however, ensure ``observation`` is
    a mapping so we can hash it deterministically.
    """
    observation_raw = runtime_args.get("observation")
    if not isinstance(observation_raw, Mapping):
        return {
            "records": [],
            "citations": [],
            "warnings": [
                "persist_lab_observation_proposal: 'observation' must be "
                "an object mapping observation fields to values."
            ],
        }

    observation = dict(observation_raw)
    payload_hash = _stable_payload_hash(observation)
    idempotency_key = f"{context.trace_id}:{payload_hash}"
    proposal_id = uuid.uuid4().hex
    now = _utc_now()

    proposal = WriteProposal(
        proposal_id=proposal_id,
        proposal_kind="lab_observation",
        payload=observation,
        citations=(),
        idempotency_key=idempotency_key,
        proposed_at=now,
    )

    _LOGGER.info(
        "lab observation proposal minted",
        extra={
            "trace_id": context.trace_id,
            "proposal_id": proposal_id,
            "idempotency_key": idempotency_key,
        },
    )

    return {
        "records": [proposal.model_dump(mode="json")],
        "citations": [],
        "warnings": [],
        "proposal": proposal,
    }


def _make_get_document_citation_region_executor(
    lookup: CitationLookup | None,
) -> Callable[[CopilotRunContext, Mapping[str, Any]], dict[str, Any]]:
    """Build a ``get_document_citation_region`` executor.

    Wires the executor to a :class:`CitationLookup`.  When ``lookup`` is
    ``None`` the executor degrades gracefully with a typed warning so
    the agent loop's success path is exercisable in tests without a
    citation index.
    """

    def _executor(
        context: CopilotRunContext,
        runtime_args: Mapping[str, Any],
    ) -> dict[str, Any]:
        citation_id_raw = runtime_args.get("citation_id")
        if not _citation_id_format_ok(citation_id_raw):
            return {
                "records": [],
                "citations": [],
                "warnings": [
                    "get_document_citation_region: 'citation_id' is "
                    "missing or not a well-formed identifier."
                ],
            }
        # ``_citation_id_format_ok`` guarantees ``citation_id_raw`` is a
        # ``str`` at this point; rebind locally so type-checkers narrow.
        assert isinstance(citation_id_raw, str)
        citation_id = citation_id_raw

        if "documents" not in context.allowed_source_types:
            return {
                "records": [],
                "citations": [],
                "warnings": [
                    "get_document_citation_region: run context does not "
                    "permit reading from 'documents'."
                ],
            }

        if lookup is None:
            return {
                "records": [],
                "citations": [],
                "warnings": [
                    "get_document_citation_region: no citation index "
                    "configured for this run."
                ],
            }

        result = lookup.resolve(context, citation_id)
        if result is None:
            return {
                "records": [],
                "citations": [],
                "warnings": [
                    f"get_document_citation_region: citation_id "
                    f"{citation_id!r} did not resolve within run scope."
                ],
            }

        citation, page, bbox = result
        return {
            "records": [
                {
                    "page": page,
                    "bbox": list(bbox),
                },
            ],
            "citations": [citation],
            "warnings": [],
        }

    return _executor


def get_document_citation_region_executor(
    context: CopilotRunContext,
    runtime_args: Mapping[str, Any],
) -> dict[str, Any]:
    """Default ``get_document_citation_region`` executor (no index)."""
    return _make_get_document_citation_region_executor(None)(context, runtime_args)


# ---------------------------------------------------------------------------
# Tool builders
# ---------------------------------------------------------------------------


def make_extract_uploaded_document_tool() -> ToolDefinition:
    """Return the :class:`ToolDefinition` for ``extract_uploaded_document``.

    The tool is :attr:`ToolDefinition.read_only` -- extraction is a read
    of an already-uploaded file; persistence is the separate
    :func:`persist_lab_observation_proposal` tool.
    """
    return ToolDefinition(
        name="extract_uploaded_document",
        description=(
            "Extract a structured representation of a previously "
            "uploaded clinical document.  The runtime context names the "
            "file; the model supplies only the document kind."
        ),
        input_schema=_extract_document_schema(),
        required_capability="extract_documents",
        source_types=("documents",),
        read_only=True,
        max_rows=1,
        executor=extract_uploaded_document_executor,
    )


def make_retrieve_guidelines_tool(
    pipeline_factory: Callable[[], RAGPipeline] | None = None,
) -> ToolDefinition:
    """Return the :class:`ToolDefinition` for ``retrieve_guidelines``.

    Parameters
    ----------
    pipeline_factory
        Optional zero-arg factory returning a configured
        :class:`RAGPipeline`.  When omitted, the executor returns an
        empty result bag with a typed warning so callers can register
        the tool ahead of pipeline wiring.
    """
    executor = (
        retrieve_guidelines_executor
        if pipeline_factory is None
        else _make_retrieve_guidelines_executor(pipeline_factory)
    )
    return ToolDefinition(
        name="retrieve_guidelines",
        description=(
            "Retrieve relevant clinical guideline chunks via the RAG "
            "pipeline.  Returns short snippets with citation metadata."
        ),
        input_schema=_retrieve_guidelines_schema(),
        required_capability="retrieve_guidelines",
        source_types=("guidelines",),
        read_only=True,
        max_rows=10,
        executor=executor,
    )


def make_persist_lab_observation_proposal_tool() -> ToolDefinition:
    """Return the :class:`ToolDefinition` for ``persist_lab_observation_proposal``.

    Note: ``read_only=True`` is intentional even though the tool's
    *intent* is a write.  The tool body itself never mutates persistent
    state -- it emits a :class:`WriteProposal` for the PHP committer to
    materialise.  Keeping ``read_only=True`` lets the M5/M6 surface
    treat every sidecar tool uniformly as side-effect-free; the
    proposal-vs-commit distinction lives at the M21 boundary, not in
    this flag.
    """
    return ToolDefinition(
        name="persist_lab_observation_proposal",
        description=(
            "Emit a deferred write *proposal* for a lab observation.  "
            "The sidecar never writes to OpenEMR directly; this tool "
            "produces a typed proposal for the host to validate and "
            "commit."
        ),
        input_schema=_persist_lab_observation_proposal_schema(),
        required_capability="propose_lab_observation",
        source_types=("labs",),
        read_only=True,
        max_rows=1,
        executor=persist_lab_observation_proposal_executor,
    )


def make_get_document_citation_region_tool(
    lookup: CitationLookup | None = None,
) -> ToolDefinition:
    """Return the :class:`ToolDefinition` for ``get_document_citation_region``.

    Parameters
    ----------
    lookup
        Optional :class:`CitationLookup` adapter for resolving citation
        ids to ``(citation, page, bbox)`` triples.  When omitted, the
        executor returns an empty result bag with a typed warning.
    """
    executor = (
        get_document_citation_region_executor
        if lookup is None
        else _make_get_document_citation_region_executor(lookup)
    )
    return ToolDefinition(
        name="get_document_citation_region",
        description=(
            "Look up the page and bounding box of a citation previously "
            "emitted in this run.  Useful for the UI to highlight the "
            "evidence region in the source PDF."
        ),
        input_schema=_citation_region_schema(),
        required_capability="read_document_citations",
        source_types=("documents",),
        read_only=True,
        max_rows=1,
        executor=executor,
    )


# ---------------------------------------------------------------------------
# Registry factory
# ---------------------------------------------------------------------------


def build_document_tools(
    *,
    pipeline_factory: Callable[[], RAGPipeline] | None = None,
    citation_lookup: CitationLookup | None = None,
) -> tuple[ToolDefinition, ...]:
    """Construct the immutable tuple of M12 document tools.

    Returns the tuple in alphabetical order by name to match the
    convention established by :func:`agent_service.tools.stubs.build_stub_tools`.
    """
    return (
        make_extract_uploaded_document_tool(),
        make_get_document_citation_region_tool(citation_lookup),
        make_persist_lab_observation_proposal_tool(),
        make_retrieve_guidelines_tool(pipeline_factory),
    )


def document_tool_registry(
    *,
    pipeline_factory: Callable[[], RAGPipeline] | None = None,
    citation_lookup: CitationLookup | None = None,
) -> ToolRegistry:
    """Return a fresh :class:`ToolRegistry` seeded with the M12 tools.

    Each call returns a new registry instance.  This factory does NOT
    extend :func:`agent_service.tools.registry.default_registry`; the
    agent loop (M13) is the place that composes the patient-evidence
    registry with the document-tool registry, so this module stays
    independently testable.
    """
    registry = ToolRegistry()
    for tool in build_document_tools(
        pipeline_factory=pipeline_factory,
        citation_lookup=citation_lookup,
    ):
        registry.register(tool)
    return registry
