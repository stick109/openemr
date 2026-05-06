"""Generate the 50-case eval fixture layout under
``agent_service/eval/fixtures/``.

Runs offline (no network).  Re-running is idempotent: every file is
overwritten with deterministic content.  Validates each case against the
relevant Pydantic schema and the FakeLLMClient response shape before
writing it out.

Usage
-----

    py scripts/generate_fixtures.py
"""

from __future__ import annotations

import json
from copy import deepcopy
from pathlib import Path
from typing import Any

from agent_service.schemas.intake_form import IntakeForm
from agent_service.schemas.lab_pdf import LabPdf

# ---------------------------------------------------------------------------
# Output paths
# ---------------------------------------------------------------------------

REPO_ROOT = Path(__file__).resolve().parent.parent
FIXTURES_DIR = REPO_ROOT / "agent_service" / "eval" / "fixtures"
MANIFEST_PATH = FIXTURES_DIR / "manifest.json"


# ---------------------------------------------------------------------------
# Helpers to build cases
# ---------------------------------------------------------------------------


def _citation(field_name: str, *, page: int = 1, x0: float = 50.0, y0: float = 100.0) -> dict[str, Any]:
    """Generate a deterministic SourceCitation dict."""
    return {
        "page": page,
        "bbox": [x0, y0, x0 + 200.0, y0 + 20.0],
        "field_name": field_name,
    }


def _lab_result(
    *,
    test_name: str,
    value: str,
    unit: str,
    reference_range: str,
    abnormal_flag: str,
    collection_date: str = "2025-09-15",
    page: int = 1,
    y_offset: float = 100.0,
) -> dict[str, Any]:
    """Build a single LabResult dict."""
    return {
        "test_name": test_name,
        "value": value,
        "unit": unit,
        "reference_range": reference_range,
        "collection_date": collection_date,
        "abnormal_flag": abnormal_flag,
        "source_citation": _citation(test_name.lower().replace(" ", "_"), page=page, y0=y_offset),
    }


def _lab_extraction(
    *,
    results: list[dict[str, Any]],
    confidence: float = 0.95,
    patient_name: str | None = "Jane Doe",
    ordering_provider: str | None = "Dr. Alice Roberts",
    lab_name: str | None = "QuestDiagnostics",
) -> dict[str, Any]:
    """Build a top-level LabPdf dict."""
    return {
        "results": results,
        "extraction_confidence": confidence,
        "patient_name": patient_name,
        "ordering_provider": ordering_provider,
        "lab_name": lab_name,
    }


def _intake_extraction(
    *,
    chief_concern: str,
    medications: list[dict[str, Any]] | None = None,
    allergies: list[dict[str, Any]] | None = None,
    family_history: list[dict[str, Any]] | None = None,
    demographics: dict[str, Any] | None = None,
    confidence: float = 0.92,
) -> dict[str, Any]:
    """Build a top-level IntakeForm dict."""
    demographics = demographics or {
        "name": "John Patient",
        "dob": "1980-01-15",
        "gender": "M",
        "address": "123 Main St, Springfield, IL 62701",
        "phone": "555-123-4567",
        "email": "john.patient@example.com",
        "insurance_id": "BCBS-99887766",
    }
    return {
        "demographics": demographics,
        "chief_concern": chief_concern,
        "current_medications": medications or [],
        "allergies": allergies or [],
        "family_history": family_history or [],
        "source_citations": [
            _citation("demographics", page=1, y0=80.0),
            _citation("chief_concern", page=1, y0=200.0),
        ],
        "extraction_confidence": confidence,
    }


# ---------------------------------------------------------------------------
# Lab cases
# ---------------------------------------------------------------------------


def _build_lab_cases() -> list[dict[str, Any]]:
    """Return 25 lab fixture cases (22 happy + 3 refusal)."""
    cases: list[dict[str, Any]] = []

    # 1. Normal CBC panel
    cases.append(
        {
            "description": "Normal CBC panel - all values within reference range",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="WBC", value="6.5", unit="x10^3/uL", reference_range="4.0-11.0", abnormal_flag="normal"),
                    _lab_result(test_name="RBC", value="4.8", unit="x10^6/uL", reference_range="4.2-5.4", abnormal_flag="normal", y_offset=130.0),
                    _lab_result(test_name="Hemoglobin", value="14.2", unit="g/dL", reference_range="12.0-16.0", abnormal_flag="normal", y_offset=160.0),
                    _lab_result(test_name="Hematocrit", value="42.0", unit="%", reference_range="37.0-47.0", abnormal_flag="normal", y_offset=190.0),
                    _lab_result(test_name="Platelets", value="250", unit="x10^3/uL", reference_range="150-400", abnormal_flag="normal", y_offset=220.0),
                ]
            ),
        }
    )

    # 2. CBC with anemia
    cases.append(
        {
            "description": "CBC with low hemoglobin indicating anemia",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="WBC", value="7.2", unit="x10^3/uL", reference_range="4.0-11.0", abnormal_flag="normal"),
                    _lab_result(test_name="Hemoglobin", value="9.8", unit="g/dL", reference_range="12.0-16.0", abnormal_flag="low", y_offset=130.0),
                    _lab_result(test_name="Hematocrit", value="29.5", unit="%", reference_range="37.0-47.0", abnormal_flag="low", y_offset=160.0),
                    _lab_result(test_name="Platelets", value="320", unit="x10^3/uL", reference_range="150-400", abnormal_flag="normal", y_offset=190.0),
                ]
            ),
        }
    )

    # 3. CBC with leukocytosis
    cases.append(
        {
            "description": "CBC with elevated WBC suggesting infection",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="WBC", value="18.4", unit="x10^3/uL", reference_range="4.0-11.0", abnormal_flag="high"),
                    _lab_result(test_name="Neutrophils", value="85", unit="%", reference_range="40-70", abnormal_flag="high", y_offset=130.0),
                    _lab_result(test_name="Hemoglobin", value="13.5", unit="g/dL", reference_range="12.0-16.0", abnormal_flag="normal", y_offset=160.0),
                ]
            ),
        }
    )

    # 4. Critically high potassium (BMP)
    cases.append(
        {
            "description": "BMP with critically high potassium - life-threatening",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="Sodium", value="138", unit="mmol/L", reference_range="135-145", abnormal_flag="normal"),
                    _lab_result(test_name="Potassium", value="6.8", unit="mmol/L", reference_range="3.5-5.0", abnormal_flag="critical_high", y_offset=130.0),
                    _lab_result(test_name="Chloride", value="102", unit="mmol/L", reference_range="98-107", abnormal_flag="normal", y_offset=160.0),
                    _lab_result(test_name="CO2", value="24", unit="mmol/L", reference_range="22-29", abnormal_flag="normal", y_offset=190.0),
                    _lab_result(test_name="BUN", value="18", unit="mg/dL", reference_range="7-20", abnormal_flag="normal", y_offset=220.0),
                    _lab_result(test_name="Creatinine", value="1.0", unit="mg/dL", reference_range="0.6-1.2", abnormal_flag="normal", y_offset=250.0),
                    _lab_result(test_name="Glucose", value="92", unit="mg/dL", reference_range="70-100", abnormal_flag="normal", y_offset=280.0),
                ],
                confidence=0.97,
            ),
        }
    )

    # 5. Critically low glucose
    cases.append(
        {
            "description": "Glucose at critical-low value indicating severe hypoglycemia",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="Glucose", value="38", unit="mg/dL", reference_range="70-100", abnormal_flag="critical_low"),
                ],
                confidence=0.99,
            ),
        }
    )

    # 6. Normal lipid panel
    cases.append(
        {
            "description": "Normal lipid panel - cardiovascular risk factors within limits",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="Total Cholesterol", value="180", unit="mg/dL", reference_range="<200", abnormal_flag="normal"),
                    _lab_result(test_name="LDL", value="95", unit="mg/dL", reference_range="<100", abnormal_flag="normal", y_offset=130.0),
                    _lab_result(test_name="HDL", value="55", unit="mg/dL", reference_range=">40", abnormal_flag="normal", y_offset=160.0),
                    _lab_result(test_name="Triglycerides", value="120", unit="mg/dL", reference_range="<150", abnormal_flag="normal", y_offset=190.0),
                ]
            ),
        }
    )

    # 7. Lipid panel with hyperlipidemia
    cases.append(
        {
            "description": "Lipid panel with elevated LDL and triglycerides",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="Total Cholesterol", value="265", unit="mg/dL", reference_range="<200", abnormal_flag="high"),
                    _lab_result(test_name="LDL", value="180", unit="mg/dL", reference_range="<100", abnormal_flag="high", y_offset=130.0),
                    _lab_result(test_name="HDL", value="38", unit="mg/dL", reference_range=">40", abnormal_flag="low", y_offset=160.0),
                    _lab_result(test_name="Triglycerides", value="240", unit="mg/dL", reference_range="<150", abnormal_flag="high", y_offset=190.0),
                ]
            ),
        }
    )

    # 8. Normal liver panel
    cases.append(
        {
            "description": "Normal hepatic function panel",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="AST", value="22", unit="U/L", reference_range="10-40", abnormal_flag="normal"),
                    _lab_result(test_name="ALT", value="28", unit="U/L", reference_range="7-56", abnormal_flag="normal", y_offset=130.0),
                    _lab_result(test_name="Alkaline Phosphatase", value="80", unit="U/L", reference_range="44-147", abnormal_flag="normal", y_offset=160.0),
                    _lab_result(test_name="Total Bilirubin", value="0.8", unit="mg/dL", reference_range="0.1-1.2", abnormal_flag="normal", y_offset=190.0),
                    _lab_result(test_name="Albumin", value="4.2", unit="g/dL", reference_range="3.5-5.0", abnormal_flag="normal", y_offset=220.0),
                ]
            ),
        }
    )

    # 9. Liver panel with elevated transaminases
    cases.append(
        {
            "description": "Liver panel showing elevated AST/ALT consistent with hepatocellular injury",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="AST", value="180", unit="U/L", reference_range="10-40", abnormal_flag="high"),
                    _lab_result(test_name="ALT", value="220", unit="U/L", reference_range="7-56", abnormal_flag="high", y_offset=130.0),
                    _lab_result(test_name="Alkaline Phosphatase", value="160", unit="U/L", reference_range="44-147", abnormal_flag="high", y_offset=160.0),
                    _lab_result(test_name="Total Bilirubin", value="2.4", unit="mg/dL", reference_range="0.1-1.2", abnormal_flag="high", y_offset=190.0),
                ]
            ),
        }
    )

    # 10. Thyroid panel - normal
    cases.append(
        {
            "description": "Normal thyroid function panel",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="TSH", value="2.1", unit="uIU/mL", reference_range="0.4-4.0", abnormal_flag="normal"),
                    _lab_result(test_name="Free T4", value="1.2", unit="ng/dL", reference_range="0.8-1.8", abnormal_flag="normal", y_offset=130.0),
                    _lab_result(test_name="Free T3", value="3.0", unit="pg/mL", reference_range="2.3-4.2", abnormal_flag="normal", y_offset=160.0),
                ]
            ),
        }
    )

    # 11. Thyroid panel - hypothyroidism
    cases.append(
        {
            "description": "Thyroid panel showing primary hypothyroidism (high TSH, low T4)",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="TSH", value="15.2", unit="uIU/mL", reference_range="0.4-4.0", abnormal_flag="high"),
                    _lab_result(test_name="Free T4", value="0.5", unit="ng/dL", reference_range="0.8-1.8", abnormal_flag="low", y_offset=130.0),
                ]
            ),
        }
    )

    # 12. Thyroid panel - hyperthyroidism
    cases.append(
        {
            "description": "Thyroid panel showing hyperthyroidism (suppressed TSH, elevated T4)",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="TSH", value="0.05", unit="uIU/mL", reference_range="0.4-4.0", abnormal_flag="low"),
                    _lab_result(test_name="Free T4", value="2.5", unit="ng/dL", reference_range="0.8-1.8", abnormal_flag="high", y_offset=130.0),
                    _lab_result(test_name="Free T3", value="6.1", unit="pg/mL", reference_range="2.3-4.2", abnormal_flag="high", y_offset=160.0),
                ]
            ),
        }
    )

    # 13. A1C - normal
    cases.append(
        {
            "description": "HbA1c within normal range",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="HbA1c", value="5.2", unit="%", reference_range="<5.7", abnormal_flag="normal"),
                ]
            ),
        }
    )

    # 14. A1C - prediabetic
    cases.append(
        {
            "description": "HbA1c in pre-diabetic range",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="HbA1c", value="6.1", unit="%", reference_range="<5.7", abnormal_flag="abnormal"),
                ]
            ),
        }
    )

    # 15. A1C - diabetes
    cases.append(
        {
            "description": "HbA1c consistent with poorly-controlled diabetes",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="HbA1c", value="9.4", unit="%", reference_range="<5.7", abnormal_flag="high"),
                ]
            ),
        }
    )

    # 16. Urinalysis - normal
    cases.append(
        {
            "description": "Normal urinalysis dipstick",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="Urine pH", value="6.0", unit="pH units", reference_range="4.5-8.0", abnormal_flag="normal"),
                    _lab_result(test_name="Urine Protein", value="negative", unit="qualitative", reference_range="negative", abnormal_flag="normal", y_offset=130.0),
                    _lab_result(test_name="Urine Glucose", value="negative", unit="qualitative", reference_range="negative", abnormal_flag="normal", y_offset=160.0),
                    _lab_result(test_name="Urine Ketones", value="negative", unit="qualitative", reference_range="negative", abnormal_flag="normal", y_offset=190.0),
                    _lab_result(test_name="Urine Blood", value="negative", unit="qualitative", reference_range="negative", abnormal_flag="normal", y_offset=220.0),
                ]
            ),
        }
    )

    # 17. Urinalysis - UTI
    cases.append(
        {
            "description": "Urinalysis suggestive of urinary tract infection",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="Leukocyte Esterase", value="positive", unit="qualitative", reference_range="negative", abnormal_flag="abnormal"),
                    _lab_result(test_name="Nitrites", value="positive", unit="qualitative", reference_range="negative", abnormal_flag="abnormal", y_offset=130.0),
                    _lab_result(test_name="WBC (urine)", value="50", unit="/hpf", reference_range="0-5", abnormal_flag="high", y_offset=160.0),
                ]
            ),
        }
    )

    # 18. Mixed panel - CBC + BMP
    cbc_bmp_results = [
        _lab_result(test_name="WBC", value="9.0", unit="x10^3/uL", reference_range="4.0-11.0", abnormal_flag="normal"),
        _lab_result(test_name="Hemoglobin", value="11.5", unit="g/dL", reference_range="12.0-16.0", abnormal_flag="low", y_offset=130.0),
        _lab_result(test_name="Platelets", value="180", unit="x10^3/uL", reference_range="150-400", abnormal_flag="normal", y_offset=160.0),
        _lab_result(test_name="Sodium", value="142", unit="mmol/L", reference_range="135-145", abnormal_flag="normal", page=2, y_offset=80.0),
        _lab_result(test_name="Potassium", value="4.2", unit="mmol/L", reference_range="3.5-5.0", abnormal_flag="normal", page=2, y_offset=110.0),
        _lab_result(test_name="Creatinine", value="1.5", unit="mg/dL", reference_range="0.6-1.2", abnormal_flag="high", page=2, y_offset=140.0),
    ]
    cases.append(
        {
            "description": "Mixed CBC + BMP panel with mild anemia and elevated creatinine",
            "extracted": _lab_extraction(results=cbc_bmp_results),
        }
    )

    # 19. Comprehensive metabolic panel - normal
    cmp_results = [
        _lab_result(test_name=name, value=val, unit=unit, reference_range=ref, abnormal_flag="normal", y_offset=100.0 + 30.0 * i)
        for i, (name, val, unit, ref) in enumerate(
            [
                ("Sodium", "140", "mmol/L", "135-145"),
                ("Potassium", "4.0", "mmol/L", "3.5-5.0"),
                ("Chloride", "104", "mmol/L", "98-107"),
                ("CO2", "26", "mmol/L", "22-29"),
                ("Glucose", "88", "mg/dL", "70-100"),
                ("BUN", "12", "mg/dL", "7-20"),
                ("Creatinine", "0.9", "mg/dL", "0.6-1.2"),
                ("Calcium", "9.5", "mg/dL", "8.5-10.5"),
                ("Albumin", "4.4", "g/dL", "3.5-5.0"),
                ("Total Protein", "7.0", "g/dL", "6.0-8.3"),
            ]
        )
    ]
    cases.append(
        {
            "description": "Normal comprehensive metabolic panel (CMP)",
            "extracted": _lab_extraction(results=cmp_results),
        }
    )

    # 20. Coagulation panel - elevated INR
    cases.append(
        {
            "description": "Coagulation panel with INR above therapeutic range",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="PT", value="22.5", unit="sec", reference_range="11-13.5", abnormal_flag="high"),
                    _lab_result(test_name="INR", value="2.6", unit="ratio", reference_range="0.9-1.1", abnormal_flag="high", y_offset=130.0),
                    _lab_result(test_name="aPTT", value="35", unit="sec", reference_range="25-35", abnormal_flag="normal", y_offset=160.0),
                ]
            ),
        }
    )

    # 21. Vitamin D - low
    cases.append(
        {
            "description": "Vitamin D deficiency",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="25-OH Vitamin D", value="14", unit="ng/mL", reference_range="30-100", abnormal_flag="low"),
                ]
            ),
        }
    )

    # 22. Iron studies - iron deficiency
    cases.append(
        {
            "description": "Iron studies consistent with iron-deficiency anemia",
            "extracted": _lab_extraction(
                results=[
                    _lab_result(test_name="Serum Iron", value="22", unit="ug/dL", reference_range="50-170", abnormal_flag="low"),
                    _lab_result(test_name="TIBC", value="450", unit="ug/dL", reference_range="240-450", abnormal_flag="high", y_offset=130.0),
                    _lab_result(test_name="Ferritin", value="8", unit="ng/mL", reference_range="20-250", abnormal_flag="low", y_offset=160.0),
                    _lab_result(test_name="Transferrin Saturation", value="5", unit="%", reference_range="20-50", abnormal_flag="low", y_offset=190.0),
                ]
            ),
        }
    )

    # 23-25: Refusal cases (corrupt / empty / missing-data PDFs)
    cases.append(
        {
            "description": "Corrupted PDF - extractor cannot read any structured content",
            "refusal": True,
            "refusal_reason": "PDF appears corrupted - no readable text or table structure detected.",
        }
    )
    cases.append(
        {
            "description": "Blank lab requisition - no result values populated",
            "refusal": True,
            "refusal_reason": "Document is a blank lab requisition with no result values to extract.",
        }
    )
    cases.append(
        {
            "description": "Document missing required fields (no reference ranges, no units)",
            "refusal": True,
            "refusal_reason": "Lab values present but reference ranges and units are missing - extraction unsafe.",
        }
    )

    return cases


# ---------------------------------------------------------------------------
# Intake cases
# ---------------------------------------------------------------------------


def _med(name: str, dosage: str | None = None, frequency: str | None = None) -> dict[str, Any]:
    return {"name": name, "dosage": dosage, "frequency": frequency}


def _allergy(allergen: str, reaction: str | None = None, severity: str | None = None) -> dict[str, Any]:
    return {"allergen": allergen, "reaction": reaction, "severity": severity}


def _fh(relation: str, condition: str) -> dict[str, Any]:
    return {"relation": relation, "condition": condition}


def _build_intake_cases() -> list[dict[str, Any]]:
    """Return 25 intake fixture cases (22 happy + 3 refusal)."""
    cases: list[dict[str, Any]] = []

    cases.append(
        {
            "description": "Annual physical, no chronic conditions",
            "extracted": _intake_extraction(chief_concern="Annual wellness exam"),
        }
    )
    cases.append(
        {
            "description": "Hypertension follow-up",
            "extracted": _intake_extraction(
                chief_concern="Follow-up for hypertension",
                medications=[_med("Lisinopril", "20 mg", "daily"), _med("Hydrochlorothiazide", "12.5 mg", "daily")],
                family_history=[_fh("father", "hypertension"), _fh("mother", "hypertension")],
            ),
        }
    )
    cases.append(
        {
            "description": "Type 2 diabetes management",
            "extracted": _intake_extraction(
                chief_concern="Diabetes follow-up and medication review",
                medications=[_med("Metformin", "1000 mg", "twice daily"), _med("Empagliflozin", "10 mg", "daily")],
                family_history=[_fh("mother", "type 2 diabetes")],
            ),
        }
    )
    cases.append(
        {
            "description": "Asthma exacerbation",
            "extracted": _intake_extraction(
                chief_concern="Worsening shortness of breath and wheezing for 3 days",
                medications=[_med("Albuterol", "90 mcg", "as needed"), _med("Fluticasone", "110 mcg", "twice daily")],
                allergies=[_allergy("Pollen", "rhinitis", "moderate")],
                family_history=[_fh("sister", "asthma")],
            ),
        }
    )
    cases.append(
        {
            "description": "Acute lower-back pain",
            "extracted": _intake_extraction(
                chief_concern="Acute low back pain after lifting",
                medications=[_med("Ibuprofen", "400 mg", "as needed")],
            ),
        }
    )
    cases.append(
        {
            "description": "Migraine evaluation",
            "extracted": _intake_extraction(
                chief_concern="Recurrent migraines, increasing in frequency",
                medications=[_med("Sumatriptan", "50 mg", "as needed for migraine")],
                family_history=[_fh("mother", "migraine")],
            ),
        }
    )
    cases.append(
        {
            "description": "Penicillin allergy with severe reaction",
            "extracted": _intake_extraction(
                chief_concern="Sore throat, possible strep",
                allergies=[_allergy("Penicillin", "anaphylaxis", "severe")],
            ),
        }
    )
    cases.append(
        {
            "description": "Multi-allergy patient",
            "extracted": _intake_extraction(
                chief_concern="New patient establishing care",
                allergies=[
                    _allergy("Sulfa drugs", "rash", "moderate"),
                    _allergy("Peanuts", "anaphylaxis", "severe"),
                    _allergy("Latex", "contact dermatitis", "mild"),
                ],
            ),
        }
    )
    cases.append(
        {
            "description": "Family history of breast cancer",
            "extracted": _intake_extraction(
                chief_concern="Breast cancer screening discussion",
                family_history=[
                    _fh("mother", "breast cancer"),
                    _fh("maternal aunt", "breast cancer"),
                    _fh("maternal grandmother", "ovarian cancer"),
                ],
            ),
        }
    )
    cases.append(
        {
            "description": "Cardiac risk assessment",
            "extracted": _intake_extraction(
                chief_concern="Chest discomfort with exertion",
                medications=[_med("Atorvastatin", "40 mg", "daily"), _med("Aspirin", "81 mg", "daily")],
                family_history=[_fh("father", "myocardial infarction at 52")],
            ),
        }
    )
    cases.append(
        {
            "description": "Pregnancy intake - first prenatal visit",
            "extracted": _intake_extraction(
                chief_concern="First prenatal visit, estimated 10 weeks gestation",
                medications=[_med("Prenatal vitamin", "1 tablet", "daily"), _med("Folic acid", "800 mcg", "daily")],
            ),
        }
    )
    cases.append(
        {
            "description": "Pediatric well-child visit",
            "extracted": _intake_extraction(
                chief_concern="6-year-old well-child check and immunizations",
                allergies=[_allergy("Eggs", "hives", "mild")],
                family_history=[_fh("father", "eczema")],
                demographics={
                    "name": "Lily Smith",
                    "dob": "2019-04-22",
                    "gender": "F",
                    "address": "456 Oak Ave, Madison, WI 53703",
                    "phone": "555-987-6543",
                    "email": None,
                    "insurance_id": "AETNA-44332211",
                },
            ),
        }
    )
    cases.append(
        {
            "description": "Geriatric polypharmacy review",
            "extracted": _intake_extraction(
                chief_concern="Medication review and fall risk assessment",
                medications=[
                    _med("Warfarin", "5 mg", "daily"),
                    _med("Furosemide", "20 mg", "daily"),
                    _med("Metoprolol", "25 mg", "twice daily"),
                    _med("Donepezil", "10 mg", "at bedtime"),
                    _med("Calcium + D3", "600/400", "daily"),
                ],
                family_history=[_fh("mother", "Alzheimer's disease"), _fh("father", "stroke")],
                demographics={
                    "name": "Robert Elder",
                    "dob": "1942-07-30",
                    "gender": "M",
                    "address": "789 Pine St, Burlington, VT 05401",
                    "phone": "555-222-3344",
                    "email": "robert.elder@example.com",
                    "insurance_id": "MEDICARE-A1234567",
                },
            ),
        }
    )
    cases.append(
        {
            "description": "Mental health intake - depression",
            "extracted": _intake_extraction(
                chief_concern="Persistent low mood and anhedonia for 3 months",
                medications=[_med("Sertraline", "50 mg", "daily")],
                family_history=[_fh("sister", "major depressive disorder")],
            ),
        }
    )
    cases.append(
        {
            "description": "Mental health intake - anxiety",
            "extracted": _intake_extraction(
                chief_concern="Generalized anxiety, worsening insomnia",
                medications=[_med("Escitalopram", "10 mg", "daily"), _med("Hydroxyzine", "25 mg", "as needed")],
            ),
        }
    )
    cases.append(
        {
            "description": "Sports injury - knee pain",
            "extracted": _intake_extraction(
                chief_concern="Right knee pain after running, suspected meniscus injury",
            ),
        }
    )
    cases.append(
        {
            "description": "Dermatology - rash evaluation",
            "extracted": _intake_extraction(
                chief_concern="New itchy rash on forearms x 2 weeks",
                allergies=[_allergy("Nickel", "contact dermatitis", "mild")],
            ),
        }
    )
    cases.append(
        {
            "description": "GI complaint - heartburn",
            "extracted": _intake_extraction(
                chief_concern="Frequent heartburn and reflux symptoms",
                medications=[_med("Omeprazole", "20 mg", "daily")],
            ),
        }
    )
    cases.append(
        {
            "description": "Autoimmune - rheumatoid arthritis follow-up",
            "extracted": _intake_extraction(
                chief_concern="RA follow-up, joint stiffness recurring",
                medications=[_med("Methotrexate", "15 mg", "weekly"), _med("Folic acid", "1 mg", "daily")],
                family_history=[_fh("aunt", "rheumatoid arthritis")],
            ),
        }
    )
    cases.append(
        {
            "description": "COPD exacerbation",
            "extracted": _intake_extraction(
                chief_concern="Increased cough and dyspnea, suspected COPD flare",
                medications=[
                    _med("Tiotropium", "18 mcg", "daily"),
                    _med("Albuterol", "90 mcg", "as needed"),
                    _med("Prednisone", "40 mg", "daily x 5 days"),
                ],
            ),
        }
    )
    cases.append(
        {
            "description": "Travel medicine consult",
            "extracted": _intake_extraction(
                chief_concern="Pre-travel consult for upcoming trip to Southeast Asia",
            ),
        }
    )
    cases.append(
        {
            "description": "Minimal-data new patient",
            "extracted": _intake_extraction(
                chief_concern="Establishing care",
                demographics={
                    "name": "Anonymous Patient",
                    "dob": None,
                    "gender": None,
                    "address": None,
                    "phone": None,
                    "email": None,
                    "insurance_id": None,
                },
                confidence=0.65,
            ),
        }
    )

    # 23-25: Refusal cases
    cases.append(
        {
            "description": "Corrupted intake form PDF - unreadable",
            "refusal": True,
            "refusal_reason": "Intake form PDF is corrupted; no demographic or clinical fields recoverable.",
        }
    )
    cases.append(
        {
            "description": "Blank intake form - no fields filled in",
            "refusal": True,
            "refusal_reason": "Intake form is blank with no patient-supplied content; nothing to extract.",
        }
    )
    cases.append(
        {
            "description": "Intake form missing chief concern (required field)",
            "refusal": True,
            "refusal_reason": "Required chief_concern field is absent from the form; cannot infer safely.",
        }
    )

    return cases


# ---------------------------------------------------------------------------
# Fixture writer
# ---------------------------------------------------------------------------


def _build_refusal_extraction(doc_type: str) -> dict[str, Any]:
    """Build a placeholder extraction dict that intentionally fails validation.

    Used for refusal cases: the recorded model response would not parse into
    the expected schema, so the extractor would refuse the result.  We keep
    the field shape recognisable but empty so downstream code can read it.
    """
    if doc_type == "lab_pdf":
        return {
            "results": [],
            "extraction_confidence": 0.0,
            "patient_name": None,
            "ordering_provider": None,
            "lab_name": None,
        }
    return {
        "demographics": {
            "name": None,
            "dob": None,
            "gender": None,
            "address": None,
            "phone": None,
            "email": None,
            "insurance_id": None,
        },
        "chief_concern": "",
        "current_medications": [],
        "allergies": [],
        "family_history": [],
        "source_citations": [],
        "extraction_confidence": 0.0,
    }


def _make_fixture(
    case_id: str,
    doc_type: str,
    case: dict[str, Any],
) -> dict[str, Any]:
    """Build the on-disk fixture dict for one case."""
    is_refusal = case.get("refusal", False)
    file_id = f"fake-file-{case_id}"

    if is_refusal:
        expected_extracted = _build_refusal_extraction(doc_type)
        rubric = {
            "schema_valid": False,
            "citation_present": False,
            "factually_consistent": False,
            "safe_refusal": True,
        }
        recorded = {
            "upload_responses": {f"fixtures/{case_id}.pdf": file_id},
            "extract_responses": {file_id: expected_extracted},
            "refusal_reason": case.get("refusal_reason", "Unsafe to extract."),
        }
    else:
        expected_extracted = case["extracted"]
        # Validate against schema before writing.
        if doc_type == "lab_pdf":
            LabPdf.model_validate(expected_extracted)
        else:
            IntakeForm.model_validate(expected_extracted)

        rubric = {
            "schema_valid": True,
            "citation_present": True,
            "factually_consistent": True,
            "safe_refusal": False,
        }
        recorded = {
            "upload_responses": {f"fixtures/{case_id}.pdf": file_id},
            "extract_responses": {file_id: deepcopy(expected_extracted)},
        }

    return {
        "case_id": case_id,
        "doc_type": doc_type,
        "description": case["description"],
        "input_file_path": f"fixtures/{case_id}.pdf",
        "expected_extracted": expected_extracted,
        "expected_rubric": rubric,
        "recorded_openai_response": recorded,
    }


def main() -> None:
    FIXTURES_DIR.mkdir(parents=True, exist_ok=True)

    lab_cases = _build_lab_cases()
    intake_cases = _build_intake_cases()

    if len(lab_cases) != 25:
        raise RuntimeError(f"Expected 25 lab cases, got {len(lab_cases)}")
    if len(intake_cases) != 25:
        raise RuntimeError(f"Expected 25 intake cases, got {len(intake_cases)}")

    manifest_entries: list[dict[str, Any]] = []

    for i, case in enumerate(lab_cases, start=1):
        case_id = f"lab_{i:03d}"
        fixture = _make_fixture(case_id, "lab_pdf", case)
        path = FIXTURES_DIR / f"{case_id}.json"
        path.write_text(json.dumps(fixture, indent=2) + "\n", encoding="utf-8")
        manifest_entries.append(
            {
                "case_id": case_id,
                "doc_type": "lab_pdf",
                "description": fixture["description"],
                "expected_outcome": "refusal" if case.get("refusal") else "success",
                "fixture_file": f"{case_id}.json",
            }
        )

    for i, case in enumerate(intake_cases, start=1):
        case_id = f"intake_{i:03d}"
        fixture = _make_fixture(case_id, "intake_form", case)
        path = FIXTURES_DIR / f"{case_id}.json"
        path.write_text(json.dumps(fixture, indent=2) + "\n", encoding="utf-8")
        manifest_entries.append(
            {
                "case_id": case_id,
                "doc_type": "intake_form",
                "description": fixture["description"],
                "expected_outcome": "refusal" if case.get("refusal") else "success",
                "fixture_file": f"{case_id}.json",
            }
        )

    manifest = {
        "version": 1,
        "total_cases": len(manifest_entries),
        "cases": manifest_entries,
    }
    MANIFEST_PATH.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")

    print(f"Wrote {len(manifest_entries)} fixtures + manifest to {FIXTURES_DIR}")


if __name__ == "__main__":
    main()
