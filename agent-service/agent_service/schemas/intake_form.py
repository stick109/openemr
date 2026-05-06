"""Pydantic v2 models for structured intake-form extraction results.

An ``IntakeForm`` captures the key clinical and demographic data extracted from
a patient intake PDF.  Every extraction carries mandatory source citations
linking values back to the original document.
"""

from __future__ import annotations

from typing import Annotated

from pydantic import BaseModel, Field

from agent_service.schemas.citation import SourceCitation


class Medication(BaseModel):
    """A single medication entry from the intake form."""

    name: Annotated[str, Field(min_length=1, description="Medication name")]
    dosage: str | None = Field(default=None, description="Dosage if specified")
    frequency: str | None = Field(default=None, description="Frequency if specified")


class Allergy(BaseModel):
    """A single allergy entry from the intake form."""

    allergen: Annotated[str, Field(min_length=1, description="Name of the allergen")]
    reaction: str | None = Field(default=None, description="Type of reaction if specified")
    severity: str | None = Field(default=None, description="Severity if specified")


class Demographics(BaseModel):
    """Demographic fields from the intake form."""

    name: str | None = Field(default=None, description="Patient full name")
    dob: str | None = Field(default=None, description="Date of birth (ISO 8601 preferred)")
    gender: str | None = Field(default=None, description="Gender / sex")
    address: str | None = Field(default=None, description="Mailing address")
    phone: str | None = Field(default=None, description="Phone number")
    email: str | None = Field(default=None, description="Email address")
    insurance_id: str | None = Field(default=None, description="Insurance member ID")


class FamilyHistoryEntry(BaseModel):
    """A single family-history entry from the intake form."""

    relation: Annotated[str, Field(min_length=1, description="Family relation (e.g. mother, father)")]
    condition: Annotated[str, Field(min_length=1, description="Medical condition")]


class IntakeForm(BaseModel):
    """Top-level extraction result for a patient intake form PDF."""

    demographics: Demographics = Field(description="Patient demographic information")
    chief_concern: Annotated[str, Field(min_length=1, description="Primary reason for visit")]
    current_medications: list[Medication] = Field(
        default_factory=list,
        description="Current medications reported by the patient",
    )
    allergies: list[Allergy] = Field(
        default_factory=list,
        description="Known allergies reported by the patient",
    )
    family_history: list[FamilyHistoryEntry] = Field(
        default_factory=list,
        description="Family medical history entries",
    )
    source_citations: Annotated[
        list[SourceCitation],
        Field(min_length=1, description="PDF locations the form data was extracted from (at least one required)"),
    ]
    extraction_confidence: Annotated[
        float,
        Field(ge=0.0, le=1.0, description="Model confidence in the overall extraction"),
    ]
