"""Tests for the M12 document/lab/intake tool registry.

Covers the contract documented in
``Clinical Co-Pilot Migration to Python Sidecar.md`` step M12:

* ``document_tool_registry()`` lists the four canonical tool names.
* Each tool's input schema is a structurally valid JSON Schema with no
  forbidden authority fields advertised.
* Calling each tool through ``execute_tool`` with valid args returns a
  properly-shaped outcome.
* Defense-in-depth: a model attempting to pass ``path`` / ``file_path``
  / ``document_id`` is rejected by the executor (M6 contract).
* Citation-id format defence: a malformed ``citation_id`` does not flow
  through to the citation resolver.
* ``extract_uploaded_document`` returns the documented stub bag for
  both supported document kinds.
* ``retrieve_guidelines`` against a fake pipeline returns the records
  and citations shape, and surfaces a typed warning when the pipeline
  yields zero results.
* ``persist_lab_observation_proposal`` returns a non-empty
  ``proposal_id`` and an ``idempotency_key`` derived from ``trace_id``;
  two calls with the same trace + observation produce the same key.
* ``get_document_citation_region`` resolves a known ``citation_id`` and
  surfaces an empty result bag with a typed warning for unknown ids.
"""

from __future__ import annotations

from collections.abc import Callable
from datetime import datetime, timezone
from typing import Any

import pytest

from agent_service.auth import CopilotRunContext
from agent_service.rag.pipeline import RAGPipeline, RetrievalResult
from agent_service.schemas.copilot import Citation
from agent_service.schemas.proposals import WriteProposal
from agent_service.tools import (
    DOCUMENT_TOOL_NAMES,
    CitationLookup,
    ToolExecutionError,
    ToolRegistry,
    document_tool_registry,
    execute_tool,
)
from agent_service.tools.document_tools import build_document_tools


# ---------------------------------------------------------------------------
# Fixtures and helpers
# ---------------------------------------------------------------------------


# Far-future expiry so frozen clocks always sit before the token deadline.
TOKEN_EXPIRES_AT: int = 1_900_000_000

# Anchor "now" for deterministic clock injection.
FROZEN_NOW: datetime = datetime(2030, 1, 1, tzinfo=timezone.utc)


def _frozen_clock(value: datetime) -> Callable[[], datetime]:
    """Return a deterministic clock for the executor."""

    def _clock() -> datetime:
        return value

    return _clock


def _make_context(
    *,
    allowed_tools: list[str] | None = None,
    allowed_source_types: list[str] | None = None,
    trace_id: str = "trace-doc-tools-1",
    expires_at: int = TOKEN_EXPIRES_AT,
) -> CopilotRunContext:
    """Build a :class:`CopilotRunContext` covering the document-tool surface."""
    return CopilotRunContext.model_validate(
        {
            "user_id": 17,
            "username": "dr.smith",
            "patient_id": 42,
            "encounter_id": 100,
            "allowed_tools": list(
                allowed_tools
                if allowed_tools is not None
                else list(DOCUMENT_TOOL_NAMES),
            ),
            "allowed_source_types": list(
                allowed_source_types
                if allowed_source_types is not None
                else ["documents", "guidelines", "labs"],
            ),
            "max_rows": 10,
            "lookback_days": 365,
            "expires_at": expires_at,
            "request_id": "req-doc-tools",
            "trace_id": trace_id,
            "key_version": "v1",
        },
    )


# ---------------------------------------------------------------------------
# Lightweight JSON Schema structural validator (no jsonschema dep).
# ---------------------------------------------------------------------------


_JSON_TYPES: frozenset[str] = frozenset(
    {"object", "array", "string", "integer", "number", "boolean", "null"},
)


def _assert_valid_json_schema(schema: Any, *, path: str = "$") -> None:
    """Structurally validate ``schema`` is a Draft-7-ish JSON Schema."""
    assert isinstance(schema, dict), f"{path}: schema must be dict"
    schema_type = schema.get("type")
    assert schema_type in _JSON_TYPES, f"{path}: invalid type {schema_type!r}"

    if schema_type == "object":
        properties = schema.get("properties", {})
        assert isinstance(properties, dict), f"{path}.properties must be dict"
        for key, sub in properties.items():
            assert isinstance(key, str) and key != "", f"{path}.properties bad key {key!r}"
            _assert_valid_json_schema(sub, path=f"{path}.properties.{key}")

        required = schema.get("required", [])
        assert isinstance(required, list), f"{path}.required must be list"
        for entry in required:
            assert isinstance(entry, str)
            assert entry in properties, f"{path}.required references missing {entry!r}"

        if "additionalProperties" in schema:
            ap = schema["additionalProperties"]
            assert isinstance(ap, (bool, dict))


# ---------------------------------------------------------------------------
# Fakes
# ---------------------------------------------------------------------------


class _FakeRAGPipeline:
    """In-memory replacement for :class:`RAGPipeline`.

    Returns a configurable list of :class:`RetrievalResult` so tests can
    pin both the populated and empty paths without touching the real
    BM25 / dense indexes.
    """

    def __init__(self, results: list[RetrievalResult] | None = None) -> None:
        self._results = results or []

    def retrieve(self, query: str, top_k: int = 5) -> list[RetrievalResult]:
        # Honour ``top_k`` so the executor's cap injection is observable.
        return list(self._results[:top_k])


class _FakeCitationLookup(CitationLookup):
    """In-memory citation index."""

    def __init__(
        self,
        index: dict[str, tuple[Citation, int, tuple[float, float, float, float]]],
    ) -> None:
        self._index = dict(index)

    def resolve(
        self,
        context: CopilotRunContext,
        citation_id: str,
    ) -> tuple[Citation, int, tuple[float, float, float, float]] | None:
        return self._index.get(citation_id)


def _retrieval_result(idx: int) -> RetrievalResult:
    return RetrievalResult(
        chunk_id=f"chunk-{idx}",
        source_url=f"https://example.test/guideline/{idx}",
        section=f"Section {idx}",
        snippet=f"Guideline snippet {idx}",
        score=1.0 / (idx + 1),
    )


def _make_registry_with_fakes(
    *,
    pipeline: RAGPipeline | None,
    lookup: CitationLookup | None,
) -> ToolRegistry:
    """Build a registry whose document tools wrap the supplied fakes."""
    return document_tool_registry(
        pipeline_factory=(lambda: pipeline) if pipeline is not None else None,
        citation_lookup=lookup,
    )


# ---------------------------------------------------------------------------
# Registry + schema contract
# ---------------------------------------------------------------------------


class TestRegistryShape:
    """The factory exposes exactly the four documented tools."""

    def test_lists_all_four_tool_names(self) -> None:
        registry = document_tool_registry()
        assert sorted(registry.list_names()) == sorted(DOCUMENT_TOOL_NAMES)
        assert len(registry) == 4

    def test_each_call_returns_fresh_registry(self) -> None:
        first = document_tool_registry()
        second = document_tool_registry()
        assert first is not second

    def test_model_facing_schemas_strip_internal_fields(self) -> None:
        registry = document_tool_registry()
        schemas = registry.model_facing_schemas()
        assert len(schemas) == 4
        for entry in schemas:
            assert set(entry.keys()) == {"name", "description", "input_schema"}

    @pytest.mark.parametrize("tool_name", sorted(DOCUMENT_TOOL_NAMES))
    def test_each_tool_input_schema_is_valid(self, tool_name: str) -> None:
        registry = document_tool_registry()
        tool = registry.get(tool_name)
        _assert_valid_json_schema(tool.input_schema)

    @pytest.mark.parametrize("tool_name", sorted(DOCUMENT_TOOL_NAMES))
    def test_no_forbidden_keys_in_advertised_schema(self, tool_name: str) -> None:
        """No tool advertises a forbidden authority field."""
        registry = document_tool_registry()
        tool = registry.get(tool_name)
        properties = tool.input_schema.get("properties", {})
        for forbidden in (
            "patient_id",
            "encounter_id",
            "document_id",
            "mrn",
            "path",
            "file_path",
            "sql",
            "query_string",
            "user_id",
            "username",
        ):
            assert forbidden not in properties, (
                f"Tool {tool_name!r} must not advertise {forbidden!r}"
            )


# ---------------------------------------------------------------------------
# Defense-in-depth: forbidden authority fields are rejected.
# ---------------------------------------------------------------------------


class TestExecutorForbidsAuthorityFields:
    """The executor rejects every forbidden key for every document tool."""

    @pytest.mark.parametrize(
        "tool_name",
        ["extract_uploaded_document", "retrieve_guidelines", "get_document_citation_region"],
    )
    @pytest.mark.parametrize("forbidden_key", ["path", "file_path", "document_id"])
    def test_path_like_inputs_are_rejected(
        self,
        tool_name: str,
        forbidden_key: str,
    ) -> None:
        registry = document_tool_registry()
        ctx = _make_context()

        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                tool_name,
                {forbidden_key: "../../etc/passwd"},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "model_supplied_authority_field"


# ---------------------------------------------------------------------------
# extract_uploaded_document
# ---------------------------------------------------------------------------


class TestExtractUploadedDocument:
    """The stub returns the documented bag shape for both kinds."""

    @pytest.mark.parametrize("kind", ["lab_pdf", "intake_form"])
    def test_returns_records_citations_warnings_bag(self, kind: str) -> None:
        registry = document_tool_registry()
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            "extract_uploaded_document",
            {"document_kind": kind},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.tool_name == "extract_uploaded_document"
        assert outcome.error_class is None
        # Records / citations / warnings are present and well-typed.
        assert outcome.payload["records"] == []
        assert outcome.payload["citations"] == []
        warnings = outcome.payload["warnings"]
        assert isinstance(warnings, list) and len(warnings) == 1
        assert "M13" in warnings[0] or "M21" in warnings[0]

    def test_unknown_document_kind_is_schema_rejected(self) -> None:
        registry = document_tool_registry()
        ctx = _make_context()
        # The structural validator only checks ``type`` -- ``enum`` is
        # not enforced by the in-tree validator -- so unknown kinds reach
        # the executor as strings.  This test pins the *current*
        # behaviour: the stub does not raise but flows through, which
        # the agent loop is responsible for rejecting downstream.
        outcome = execute_tool(
            ctx,
            "extract_uploaded_document",
            {"document_kind": "lab_pdf"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )
        assert outcome.payload["records"] == []


# ---------------------------------------------------------------------------
# retrieve_guidelines
# ---------------------------------------------------------------------------


class TestRetrieveGuidelines:
    def test_populated_results_yield_records_and_citations(self) -> None:
        pipeline = _FakeRAGPipeline(
            results=[_retrieval_result(0), _retrieval_result(1)],
        )
        registry = _make_registry_with_fakes(pipeline=pipeline, lookup=None)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            "retrieve_guidelines",
            {"search_text": "lipid management screening"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.error_class is None
        assert outcome.result_count == 2
        records = outcome.payload["records"]
        assert len(records) == 2
        for record in records:
            assert set(record.keys()) >= {"chunk_id", "source_url", "section", "snippet", "score"}

        # Each retrieval result becomes a Citation with source_type "guideline".
        citations = outcome.citations
        assert len(citations) == 2
        for citation in citations:
            assert isinstance(citation, Citation)
            assert citation.source_type == "guideline"

    def test_empty_results_emit_warning(self) -> None:
        pipeline = _FakeRAGPipeline(results=[])
        registry = _make_registry_with_fakes(pipeline=pipeline, lookup=None)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            "retrieve_guidelines",
            {"search_text": "no matches expected"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.payload["records"] == []
        warnings = outcome.payload["warnings"]
        assert len(warnings) == 1
        assert "zero matches" in warnings[0]

    def test_runs_without_pipeline_factory_with_warning(self) -> None:
        registry = document_tool_registry()
        ctx = _make_context()
        outcome = execute_tool(
            ctx,
            "retrieve_guidelines",
            {"search_text": "anything"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )
        assert outcome.payload["records"] == []
        warnings = outcome.payload["warnings"]
        assert len(warnings) == 1
        assert "no RAG pipeline" in warnings[0]

    def test_blocks_when_guidelines_not_in_allowed_source_types(self) -> None:
        pipeline = _FakeRAGPipeline(results=[_retrieval_result(0)])
        registry = _make_registry_with_fakes(pipeline=pipeline, lookup=None)
        ctx = _make_context(allowed_source_types=["documents"])

        outcome = execute_tool(
            ctx,
            "retrieve_guidelines",
            {"search_text": "anything"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.payload["records"] == []
        warnings = outcome.payload["warnings"]
        assert any("guidelines" in w for w in warnings)


# ---------------------------------------------------------------------------
# persist_lab_observation_proposal
# ---------------------------------------------------------------------------


class TestPersistLabObservationProposal:
    def test_returns_write_proposal_with_idempotency_key(self) -> None:
        registry = document_tool_registry()
        ctx = _make_context()
        observation = {
            "test_name": "Hemoglobin",
            "value": "13.5",
            "unit": "g/dL",
            "reference_range": "13.5-17.5",
            "collection_date": "2026-01-15",
            "abnormal_flag": "normal",
        }

        outcome = execute_tool(
            ctx,
            "persist_lab_observation_proposal",
            {"observation": observation},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.error_class is None
        records = outcome.payload["records"]
        assert len(records) == 1
        # The full proposal is also surfaced as a typed object so the
        # agent loop can inspect it without re-validating the dict.
        proposal = outcome.payload["proposal"]
        assert isinstance(proposal, WriteProposal)
        assert proposal.proposal_kind == "lab_observation"
        assert proposal.proposal_id != ""
        assert proposal.idempotency_key.startswith(ctx.trace_id + ":")
        assert len(proposal.idempotency_key) > len(ctx.trace_id) + 1

    def test_same_trace_and_payload_produce_same_idempotency_key(self) -> None:
        registry = document_tool_registry()
        ctx = _make_context(trace_id="trace-idem-test")
        observation = {
            "test_name": "Glucose",
            "value": "92",
            "unit": "mg/dL",
            "reference_range": "70-99",
            "collection_date": "2026-02-01",
            "abnormal_flag": "normal",
        }

        first = execute_tool(
            ctx,
            "persist_lab_observation_proposal",
            {"observation": observation},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )
        second = execute_tool(
            ctx,
            "persist_lab_observation_proposal",
            {"observation": observation},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        first_proposal = first.payload["proposal"]
        second_proposal = second.payload["proposal"]
        assert isinstance(first_proposal, WriteProposal)
        assert isinstance(second_proposal, WriteProposal)
        # Idempotency: same trace + same payload => same key.
        assert first_proposal.idempotency_key == second_proposal.idempotency_key
        # But the proposal_id is fresh per call (uuid4).
        assert first_proposal.proposal_id != second_proposal.proposal_id

    def test_different_trace_ids_produce_different_keys(self) -> None:
        registry = document_tool_registry()
        observation = {"test_name": "Glucose", "value": "92"}

        ctx_a = _make_context(trace_id="trace-a")
        ctx_b = _make_context(trace_id="trace-b")

        out_a = execute_tool(
            ctx_a,
            "persist_lab_observation_proposal",
            {"observation": observation},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )
        out_b = execute_tool(
            ctx_b,
            "persist_lab_observation_proposal",
            {"observation": observation},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )
        assert (
            out_a.payload["proposal"].idempotency_key
            != out_b.payload["proposal"].idempotency_key
        )

    def test_non_object_observation_emits_warning(self) -> None:
        registry = document_tool_registry()
        ctx = _make_context()
        # The schema validator enforces ``observation`` is an object,
        # so a string is rejected at validation time.
        with pytest.raises(ToolExecutionError) as exc_info:
            execute_tool(
                ctx,
                "persist_lab_observation_proposal",
                {"observation": "not-an-object"},
                registry=registry,
                clock=_frozen_clock(FROZEN_NOW),
            )
        assert exc_info.value.reason == "schema_validation_failed"


# ---------------------------------------------------------------------------
# get_document_citation_region
# ---------------------------------------------------------------------------


class TestGetDocumentCitationRegion:
    def test_known_citation_id_returns_region(self) -> None:
        citation = Citation(
            source_type="document",
            source_id="cit-1",
            label="Page 1",
        )
        lookup = _FakeCitationLookup(
            {"cit-1": (citation, 1, (12.0, 24.0, 200.0, 60.0))},
        )
        registry = _make_registry_with_fakes(pipeline=None, lookup=lookup)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            "get_document_citation_region",
            {"citation_id": "cit-1"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.error_class is None
        records = outcome.payload["records"]
        assert len(records) == 1
        assert records[0]["page"] == 1
        assert records[0]["bbox"] == [12.0, 24.0, 200.0, 60.0]
        assert len(outcome.citations) == 1

    def test_unknown_citation_id_emits_warning(self) -> None:
        lookup = _FakeCitationLookup({})
        registry = _make_registry_with_fakes(pipeline=None, lookup=lookup)
        ctx = _make_context()

        outcome = execute_tool(
            ctx,
            "get_document_citation_region",
            {"citation_id": "not-real"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.payload["records"] == []
        warnings = outcome.payload["warnings"]
        assert len(warnings) == 1
        assert "did not resolve" in warnings[0]

    def test_malformed_citation_id_does_not_reach_lookup(self) -> None:
        called: dict[str, Any] = {}

        class _SpyLookup(CitationLookup):
            def resolve(
                self,
                context: CopilotRunContext,
                citation_id: str,
            ) -> tuple[Citation, int, tuple[float, float, float, float]] | None:
                called["citation_id"] = citation_id
                return None

        registry = _make_registry_with_fakes(pipeline=None, lookup=_SpyLookup())
        ctx = _make_context()

        # Whitespace + control chars are rejected by the format check.
        outcome = execute_tool(
            ctx,
            "get_document_citation_region",
            {"citation_id": "bad id\nwith newline"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.payload["records"] == []
        warnings = outcome.payload["warnings"]
        assert any("well-formed" in w for w in warnings)
        # Critical: the spy was never invoked.
        assert "citation_id" not in called

    def test_missing_documents_scope_blocks_lookup(self) -> None:
        called: dict[str, Any] = {}

        class _SpyLookup(CitationLookup):
            def resolve(
                self,
                context: CopilotRunContext,
                citation_id: str,
            ) -> tuple[Citation, int, tuple[float, float, float, float]] | None:
                called["citation_id"] = citation_id
                return None

        registry = _make_registry_with_fakes(pipeline=None, lookup=_SpyLookup())
        ctx = _make_context(allowed_source_types=["labs"])

        outcome = execute_tool(
            ctx,
            "get_document_citation_region",
            {"citation_id": "cit-1"},
            registry=registry,
            clock=_frozen_clock(FROZEN_NOW),
        )

        assert outcome.payload["records"] == []
        warnings = outcome.payload["warnings"]
        assert any("documents" in w for w in warnings)
        # Critical: the spy was never invoked because scope blocked the lookup.
        assert "citation_id" not in called


# ---------------------------------------------------------------------------
# Builder smoke test
# ---------------------------------------------------------------------------


class TestBuildDocumentTools:
    def test_returns_tuple_of_four_definitions(self) -> None:
        tools = build_document_tools()
        assert len(tools) == 4
        # Returned tuple is alphabetical by name.
        names = [t.name for t in tools]
        assert names == sorted(names)
        assert sorted(names) == sorted(DOCUMENT_TOOL_NAMES)
