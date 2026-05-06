"""Reciprocal Rank Fusion (RRF) for combining sparse and dense retrieval results.

Implements the standard RRF formula:
    fused_score(doc) = sum(1 / (k + rank_i)) for each retrieval system i

The constant *k* dampens the influence of high ranks; the default of 60 is
widely used in information retrieval literature.
"""

from __future__ import annotations

from agent_service.rag.bm25_index import ScoredChunk
from agent_service.rag.corpus_loader import GuidelineChunk


def reciprocal_rank_fusion(
    *result_lists: list[ScoredChunk],
    k: int = 60,
    top_k: int = 20,
) -> list[ScoredChunk]:
    """Merge multiple ranked result lists using Reciprocal Rank Fusion.

    Parameters
    ----------
    *result_lists:
        One or more ranked lists of :class:`ScoredChunk` (best first).
    k:
        RRF damping constant.  Higher values reduce the gap between
        high-ranked and low-ranked documents.  Default ``60`` follows
        the Cormack, Clarke & Butt (2009) recommendation.
    top_k:
        Maximum number of fused results to return.

    Returns
    -------
    list[ScoredChunk]
        Merged results sorted by descending fused score.  Each chunk's
        ``score`` field contains the RRF score.
    """
    # Accumulate RRF scores keyed by chunk_id.
    rrf_scores: dict[str, float] = {}
    chunk_map: dict[str, GuidelineChunk] = {}

    for result_list in result_lists:
        for rank, scored_chunk in enumerate(result_list, start=1):
            cid = scored_chunk.chunk.chunk_id
            rrf_scores[cid] = rrf_scores.get(cid, 0.0) + 1.0 / (k + rank)
            # Keep the chunk reference (last write wins, all refer to the same chunk).
            chunk_map[cid] = scored_chunk.chunk

    # Build fused results and sort descending.
    fused = [
        ScoredChunk(chunk=chunk_map[cid], score=score)
        for cid, score in rrf_scores.items()
    ]
    fused.sort(key=lambda sc: sc.score, reverse=True)
    return fused[:top_k]
