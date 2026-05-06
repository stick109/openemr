"""Loader for clinical guideline corpus chunks.

Reads JSONL files from the corpus directory, validates metadata fields,
and returns a list of typed GuidelineChunk objects.
"""

from __future__ import annotations

import json
from pathlib import Path

from pydantic import BaseModel, Field

# Default corpus directory adjacent to this module.
_CORPUS_DIR = Path(__file__).resolve().parent / "corpus"


class GuidelineChunk(BaseModel):
    """A single chunk of a public clinical guideline."""

    chunk_id: str = Field(..., min_length=1, description="Stable unique identifier for the chunk.")
    source_url: str = Field(..., min_length=1, description="URL to the public guideline source.")
    section: str = Field(..., min_length=1, description="Section name within the guideline.")
    published: str = Field(
        ...,
        min_length=1,
        pattern=r"^\d{4}-\d{2}-\d{2}$",
        description="Publication date in ISO 8601 format (YYYY-MM-DD).",
    )
    text: str = Field(..., min_length=1, description="Guideline text (100-300 words recommended).")


def load_corpus(corpus_dir: Path | None = None) -> list[GuidelineChunk]:
    """Load and validate all guideline chunks from JSONL files in *corpus_dir*.

    Parameters
    ----------
    corpus_dir:
        Directory containing ``.jsonl`` files.  Defaults to the built-in
        ``corpus/`` directory shipped with this package.

    Returns
    -------
    list[GuidelineChunk]
        Validated guideline chunks, one per JSON line across all files.

    Raises
    ------
    FileNotFoundError
        If *corpus_dir* does not exist.
    ValueError
        If a chunk fails validation or duplicate ``chunk_id`` values are found.
    """
    if corpus_dir is None:
        corpus_dir = _CORPUS_DIR

    if not corpus_dir.is_dir():
        raise FileNotFoundError(f"Corpus directory does not exist: {corpus_dir}")

    jsonl_files = sorted(corpus_dir.glob("*.jsonl"))
    if not jsonl_files:
        raise FileNotFoundError(f"No .jsonl files found in corpus directory: {corpus_dir}")

    chunks: list[GuidelineChunk] = []
    seen_ids: set[str] = set()

    for filepath in jsonl_files:
        with filepath.open(encoding="utf-8") as fh:
            for line_no, raw_line in enumerate(fh, start=1):
                line = raw_line.strip()
                if not line:
                    continue
                try:
                    data = json.loads(line)
                except json.JSONDecodeError as exc:
                    raise ValueError(
                        f"{filepath.name}:{line_no}: invalid JSON: {exc}"
                    ) from exc

                try:
                    chunk = GuidelineChunk.model_validate(data)
                except Exception as exc:
                    raise ValueError(
                        f"{filepath.name}:{line_no}: validation error: {exc}"
                    ) from exc

                if chunk.chunk_id in seen_ids:
                    raise ValueError(
                        f"{filepath.name}:{line_no}: duplicate chunk_id '{chunk.chunk_id}'"
                    )
                seen_ids.add(chunk.chunk_id)
                chunks.append(chunk)

    return chunks
