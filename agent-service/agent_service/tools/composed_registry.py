"""Composed production tool registry for the chart copilot (M13 follow-up).

This module owns the *production* wiring of the agent loop's tool surface.
Step M13's :func:`agent_service.api.copilot.get_registry_builder` shipped
with a placeholder builder that returned :func:`default_registry` -- the
M5 inert stubs whose ``executor`` fields are all ``None``. Every LLM tool
call therefore tripped the executor's ``executor_missing`` reason and
the agent returned an empty refused response.

This module composes the three real registries minted in earlier steps:

* :func:`agent_service.tools.patient_evidence_tools.patient_evidence_tool_registry`
  (M10) -- five read-only chart tools backed by the M9 read repository.
* :func:`agent_service.tools.source_drilldown.source_drilldown_tool_registry`
  (M11) -- ``get_source_detail`` drilldown.
* :func:`agent_service.tools.document_tools.document_tool_registry`
  (M12) -- four document/lab/intake tools (extractor, RAG, lab proposal,
  citation region).

``retrieve_guidelines`` is wired with a ``pipeline_factory`` that loads
the bundled clinical-guideline corpus and builds sparse + dense indexes
once at first use; the indexes are immutable after construction so we
share them across pipeline instances. ``get_document_citation_region``
still has no ``citation_lookup`` -- M12 returns an empty result bag with
a typed warning when the lookup is absent, so the tool stays advertisable
until that collaborator lands.

Public surface:

* :func:`compose_production_registry` -- per-context factory that the
  API's :func:`get_registry_builder` closes over a shared repository
  instance.
"""

from __future__ import annotations

import functools

from agent_service.auth.copilot_run_context import CopilotRunContext
from agent_service.rag.bm25_index import BM25Index
from agent_service.rag.corpus_loader import load_corpus
from agent_service.rag.dense_index import DenseIndex, fake_embed
from agent_service.rag.pipeline import RAGPipeline
from agent_service.rag.reranker import FakeReranker
from agent_service.repository.openemr import OpenEmrReadRepository
from agent_service.tools.document_tools import document_tool_registry
from agent_service.tools.patient_evidence_tools import (
    patient_evidence_tool_registry,
)
from agent_service.tools.registry import ToolRegistry
from agent_service.tools.source_drilldown import source_drilldown_tool_registry


__all__ = ["compose_production_registry"]


@functools.cache
def _local_rag_indexes() -> tuple[BM25Index, DenseIndex]:
    """Load the bundled clinical-guideline corpus and build sparse + dense indexes.

    Cached at module level: the corpus is immutable once loaded and the
    indexes are non-trivial to build, so subsequent calls reuse the same
    instances. Cache key is the empty argument tuple, so a single set of
    indexes is shared across all pipeline factory invocations within a
    process.
    """
    chunks = load_corpus()
    return (
        BM25Index(chunks),
        DenseIndex.from_chunks_with_fake_embeddings(chunks, dim=64),
    )


def _build_local_rag_pipeline() -> RAGPipeline:
    """Factory returning a fresh ``RAGPipeline`` over the bundled local corpus.

    ``RAGPipeline`` is cheap to construct (it holds references to the
    cached indexes plus a stateless reranker), so a new instance per tool
    call satisfies the per-call factory contract M12 expects without any
    per-call indexing cost.

    The reranker and embedder are the deterministic local fakes
    (``FakeReranker``, ``fake_embed``) so this wiring works without any
    external service or downloaded model.
    """
    bm25, dense = _local_rag_indexes()
    return RAGPipeline(
        bm25_index=bm25,
        dense_index=dense,
        reranker=FakeReranker(),
        embed_fn=lambda q: fake_embed(q, dim=64),
    )


def compose_production_registry(
    context: CopilotRunContext,
    *,
    repository: OpenEmrReadRepository,
) -> ToolRegistry:
    """Return a per-context :class:`ToolRegistry` with real executors wired.

    The registry merges the M10 patient-evidence tools, the M11 source
    drilldown tool, and the M12 document tools into a single
    :class:`ToolRegistry`. The ``context`` argument is currently
    unused -- the tools' executors close over the shared repository at
    construction time and already enforce per-call authority via the
    M6 executor + the per-tool ``allowed_source_types`` defense-in-depth
    checks. The signature still accepts ``context`` so the function
    matches the :data:`agent_service.loop.RegistryBuilder` callable type
    and so future iterations can vary the registry by run scope without
    a churn-y signature change.

    The five M10 tools, the single M11 tool, and the four M12 tools all
    have distinct names, so there is no name collision to resolve when
    merging. ``ToolRegistry.register`` raises on duplicates -- if a
    future tool starts colliding, the agent loop will fail loudly at
    request time rather than silently masking a bug.

    ``retrieve_guidelines`` receives :func:`_build_local_rag_pipeline` so
    the RAG tool returns real chunks from the bundled corpus.
    ``get_document_citation_region`` still has no citation lookup; M12
    handles ``None`` for that resource by returning an empty result bag
    with a typed warning, so the tool stays advertisable until the
    collaborator lands (M21).
    """
    del context  # unused today; reserved for per-run registry tailoring.

    composed = ToolRegistry()
    for source in (
        patient_evidence_tool_registry(repository),
        source_drilldown_tool_registry(repository),
        document_tool_registry(pipeline_factory=_build_local_rag_pipeline),
    ):
        for name in source.list_names():
            composed.register(source.get(name))
    return composed
