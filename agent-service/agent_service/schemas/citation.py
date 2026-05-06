"""Internal clinical citation model for linking extracted fields to PDF locations.

This is separate from the API-level Citation in api.py.  The API citations
describe *where* evidence came from (PDF bbox vs. guideline chunk).  This
clinical citation binds a specific *extracted field name* to its source
location on a PDF page, so downstream reviewers can verify each value.
"""

from __future__ import annotations

from typing import Annotated

from pydantic import BaseModel, Field, model_validator


class SourceCitation(BaseModel):
    """A pointer from an extracted field back to a bounding box on a PDF page."""

    page: Annotated[int, Field(ge=1, description="1-based page number in the source PDF")]
    bbox: Annotated[
        list[float],
        Field(
            min_length=4,
            max_length=4,
            description="Bounding box [x0, y0, x1, y1] in PDF points",
        ),
    ]
    field_name: Annotated[str, Field(min_length=1, description="Name of the extracted field this citation covers")]

    @model_validator(mode="after")
    def _validate_bbox(self) -> SourceCitation:
        """Enforce non-negative coordinates and positive area."""
        x0, y0, x1, y1 = self.bbox

        for i, coord in enumerate((x0, y0, x1, y1)):
            if coord < 0:
                raise ValueError(f"bbox[{i}] must be non-negative, got {coord}")

        if x1 <= x0:
            raise ValueError(f"bbox x1 ({x1}) must be greater than x0 ({x0}) for positive width")

        if y1 <= y0:
            raise ValueError(f"bbox y1 ({y1}) must be greater than y0 ({y0}) for positive height")

        return self


def validate_bbox(bbox: list[float]) -> None:
    """Standalone helper to validate a PDF bounding box.

    Raises ``ValueError`` when any constraint is violated:
    - Exactly 4 coordinates
    - All coordinates non-negative
    - Positive width  (x1 > x0)
    - Positive height (y1 > y0)
    """
    if len(bbox) != 4:
        raise ValueError(f"bbox must contain exactly 4 floats, got {len(bbox)}")

    x0, y0, x1, y1 = bbox

    for i, coord in enumerate((x0, y0, x1, y1)):
        if coord < 0:
            raise ValueError(f"bbox[{i}] must be non-negative, got {coord}")

    if x1 <= x0:
        raise ValueError(f"bbox x1 ({x1}) must be greater than x0 ({x0}) for positive width")

    if y1 <= y0:
        raise ValueError(f"bbox y1 ({y1}) must be greater than y0 ({y0}) for positive height")
