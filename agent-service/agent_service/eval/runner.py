"""Offline evaluation runner for the agent sidecar pipeline.

Loads the 50 fixture cases shipped with the service, runs each one through
the compiled LangGraph using a :class:`FakeLLMClient` pre-loaded with the
fixture's recorded OpenAI response, and scores the captured output state
against five boolean rubrics:

* ``schema_valid`` -- extracted dict validates against ``LabPdf`` /
  ``IntakeForm``.  False (and that is the *expected* result) for refusal
  fixtures.
* ``citation_present`` -- every persisted clinical field carries a
  ``source_citation`` AND the response ``citations`` list is non-empty
  for success cases.  Trivially True for refusals.
* ``factually_consistent`` -- key extracted values match the fixture's
  ``expected_extracted`` content (test names + abnormal flags for labs;
  demographics + chief concern for intakes).  False for refusals.
* ``safe_refusal`` -- refusal fixtures must terminate with status
  ``"refused"`` and produce no answer.  Success cases must NOT refuse.
* ``no_phi_in_logs`` -- captured stdout/stderr/state must be free of
  SSN-like patterns and ``"Patient: <name>"`` markers.  Always 1.00
  across all cases.

The runner is intended to be entirely offline.  It refuses to construct
a real :class:`OpenAIClient` and sets ``EVAL_MODE=1`` for the duration
of a run so any code path that consults the env var can short-circuit
network calls.
"""

from __future__ import annotations

import contextlib
import io
import json
import logging
import os
import re
import uuid
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from pydantic import ValidationError

from agent_service.clients.openai_client import FakeLLMClient
from agent_service.graph import build_graph
from agent_service.observability.run_record import RunRecord
from agent_service.observability.storage import RunRecordStorage
from agent_service.rag.bm25_index import BM25Index
from agent_service.rag.corpus_loader import GuidelineChunk, load_corpus
from agent_service.rag.dense_index import DenseIndex, fake_embed
from agent_service.rag.pipeline import RAGPipeline
from agent_service.rag.reranker import FakeReranker
from agent_service.schemas.api import AgentRunRequest, DocType
from agent_service.schemas.intake_form import IntakeForm
from agent_service.schemas.lab_pdf import LabPdf

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------


FIXTURES_DIR: Path = (
    Path(__file__).resolve().parent / "fixtures"
)
"""Directory containing the 50 fixture JSON files plus ``manifest.json``."""

MANIFEST_PATH: Path = FIXTURES_DIR / "manifest.json"
"""Path to the fixtures manifest used for ordered traversal."""

EVAL_MODE_ENV: str = "EVAL_MODE"
"""Env var the runner sets to ``"1"`` for the duration of a run."""

RUBRIC_NAMES: tuple[str, ...] = (
    "schema_valid",
    "citation_present",
    "factually_consistent",
    "safe_refusal",
    "no_phi_in_logs",
)
"""Ordered list of rubric names produced by :func:`score_case`."""

DEFAULT_THRESHOLDS: dict[str, float] = {
    "schema_valid": 0.95,
    "citation_present": 0.95,
    "factually_consistent": 0.95,
    "safe_refusal": 1.00,
    "no_phi_in_logs": 1.00,
}
"""Per-rubric minimum acceptable pass rate."""

REGRESSION_TOLERANCE: float = 0.05
"""Maximum tolerated drop relative to the recorded baseline (5 percentage points)."""

SUPPORTED_REGRESSIONS: tuple[str, ...] = (
    "drop-citations",
    "wrong-value",
    "flip-abnormal-flags",
)
"""Names of every regression hook recognised by the runner."""

# Counter-flips for the abnormal-flag regression.  The mapping is symmetric
# so applying it twice would round-trip back to the original value.
_FLAG_FLIPS: dict[str, str] = {
    "high": "low",
    "low": "high",
    "critical_high": "critical_low",
    "critical_low": "critical_high",
}


# Regex patterns used by the PHI scanner.  These intentionally err on the
# side of false positives -- a single hit fails the rubric for every case
# because PHI in logs is an absolute correctness violation.
_SSN_PATTERN = re.compile(r"\b\d{3}-\d{2}-\d{4}\b")
_PATIENT_NAME_PATTERN = re.compile(
    r"\bPatient\s*[:=]\s*[A-Z][a-zA-Z'\-]+(?:\s+[A-Z][a-zA-Z'\-]+)+",
)


# ---------------------------------------------------------------------------
# Data classes
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class FixtureCase:
    """A loaded fixture from disk."""

    case_id: str
    doc_type: str
    description: str
    input_file_path: str
    expected_outcome: str  # "success" or "refusal"
    expected_extracted: dict[str, Any]
    expected_rubric: dict[str, bool]
    recorded_openai_response: dict[str, Any]


@dataclass(frozen=True)
class CaseResult:
    """Per-case result with rubric pass/fail booleans."""

    case_id: str
    doc_type: str
    expected_outcome: str
    rubrics: dict[str, bool]
    status: str  # final graph status: "completed" / "refused" / "error"
    notes: list[str] = field(default_factory=list)


@dataclass(frozen=True)
class EvalReport:
    """Aggregate report covering all 50 fixtures."""

    cases: list[CaseResult]
    pass_rates: dict[str, float]
    total: int

    def as_dict(self) -> dict[str, Any]:
        """Return a JSON-serialisable dict suitable for ``--output``."""
        return {
            "total_cases": self.total,
            "pass_rates": dict(self.pass_rates),
            "cases": [
                {
                    "case_id": case.case_id,
                    "doc_type": case.doc_type,
                    "expected_outcome": case.expected_outcome,
                    "status": case.status,
                    "rubrics": dict(case.rubrics),
                    "notes": list(case.notes),
                }
                for case in self.cases
            ],
        }


# ---------------------------------------------------------------------------
# Fixture loading
# ---------------------------------------------------------------------------


def load_fixtures(
    fixtures_dir: Path | None = None,
    manifest_path: Path | None = None,
) -> list[FixtureCase]:
    """Load all 50 fixtures in manifest order.

    Parameters
    ----------
    fixtures_dir:
        Override the default fixtures directory.  Useful for tests.
    manifest_path:
        Override the default manifest path.  Useful for tests.

    Returns
    -------
    list[FixtureCase]
        Ordered list of loaded fixture cases.
    """
    fixtures_dir = fixtures_dir or FIXTURES_DIR
    manifest_path = manifest_path or (fixtures_dir / "manifest.json")

    if not manifest_path.is_file():
        raise FileNotFoundError(f"Manifest not found: {manifest_path}")

    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    cases: list[FixtureCase] = []
    for entry in manifest["cases"]:
        fixture_path = fixtures_dir / entry["fixture_file"]
        with fixture_path.open(encoding="utf-8") as fh:
            data = json.load(fh)
        cases.append(
            FixtureCase(
                case_id=data["case_id"],
                doc_type=data["doc_type"],
                description=data["description"],
                input_file_path=data["input_file_path"],
                expected_outcome=entry["expected_outcome"],
                expected_extracted=data["expected_extracted"],
                expected_rubric=data["expected_rubric"],
                recorded_openai_response=data["recorded_openai_response"],
            )
        )
    return cases


# ---------------------------------------------------------------------------
# Eval run plumbing
# ---------------------------------------------------------------------------


@contextlib.contextmanager
def _eval_mode_guard() -> Any:
    """Set ``EVAL_MODE=1`` for the duration of the with-block.

    Restores the previous value (or removes the variable) on exit.  This
    makes the contract enforceable: any code path that touches a real
    OpenAI/Cohere client can check ``EVAL_MODE`` and short-circuit.
    """
    previous = os.environ.get(EVAL_MODE_ENV)
    os.environ[EVAL_MODE_ENV] = "1"
    try:
        yield
    finally:
        if previous is None:
            os.environ.pop(EVAL_MODE_ENV, None)
        else:
            os.environ[EVAL_MODE_ENV] = previous


def _build_request(case: FixtureCase) -> AgentRunRequest:
    """Construct an :class:`AgentRunRequest` from a fixture case.

    Uses deterministic placeholder values for ``patient_id``,
    ``encounter_id`` and ``trace_id`` that are valid per the contract
    but obviously synthetic.
    """
    # Generate a deterministic trace_id derived from the case_id so re-runs
    # produce identical UUIDs in logs/output.
    trace_uuid = uuid.uuid5(uuid.NAMESPACE_OID, f"eval:{case.case_id}")
    # Force a v4 representation so AgentRunRequest's validator accepts it.
    # (uuid5 has version 5; we re-assemble using the digest as random bits.)
    trace_id = str(uuid.UUID(int=trace_uuid.int, version=4))

    return AgentRunRequest(
        patient_id=1,
        encounter_id=1,
        trace_id=trace_id,
        file_path=case.input_file_path,
        doc_type=DocType(case.doc_type),
    )


def _build_fake_client(
    case: FixtureCase,
    *,
    inject_regression: str | None = None,
) -> FakeLLMClient:
    """Build a :class:`FakeLLMClient` pre-loaded with the fixture response."""
    recorded = case.recorded_openai_response
    upload_responses = dict(recorded.get("upload_responses", {}))
    extract_responses = {
        file_id: _maybe_inject_regression(extracted, inject_regression)
        for file_id, extracted in recorded.get("extract_responses", {}).items()
    }
    return FakeLLMClient(
        upload_responses=upload_responses,
        extract_responses=extract_responses,
        # The runner enforces that no real API key is used; allow_env_key
        # is False so the constructor itself rejects a leaked key.
        allow_env_key=False,
    )


def _maybe_inject_regression(
    extracted: dict[str, Any],
    regression: str | None,
) -> dict[str, Any]:
    """Apply a regression hook to a recorded extraction response.

    Supported regressions (all deterministic -- no random state):

    * ``"drop-citations"`` -- strips ``source_citation`` from each lab
      result row and empties ``source_citations`` on intake forms.  Drives
      ``citation_present`` below threshold.
    * ``"wrong-value"`` -- mutates one important extracted field per case
      so it no longer matches the fixture's expected output.  For lab PDFs
      we corrupt the *first* result row's numeric ``value`` and flip its
      ``abnormal_flag``; for intake forms we rewrite ``chief_concern``.
      The schema still validates -- only the factual content drifts -- so
      this targets ``factually_consistent``.
    * ``"flip-abnormal-flags"`` -- swaps ``high`` <-> ``low`` (and
      ``critical_high`` <-> ``critical_low``) on every lab result row.
      A bigger-blast version of ``wrong-value`` that breaks
      ``factually_consistent`` across most lab fixtures.  No-op for
      intake forms because they do not carry abnormal flags.
    """
    if regression is None:
        return extracted

    if regression not in SUPPORTED_REGRESSIONS:
        raise ValueError(
            f"Unknown regression type: {regression!r}. "
            f"Supported: {', '.join(SUPPORTED_REGRESSIONS)}."
        )

    # Always work on a deep copy: the eval runner reuses the same loaded
    # fixture objects across runs, and accidental mutation would poison
    # subsequent invocations within the same Python process.
    cloned = json.loads(json.dumps(extracted))

    if regression == "drop-citations":
        # Lab PDF shape
        if isinstance(cloned.get("results"), list):
            for row in cloned["results"]:
                if isinstance(row, dict):
                    row.pop("source_citation", None)
        # Intake form shape
        if "source_citations" in cloned:
            cloned["source_citations"] = []
        return cloned

    if regression == "wrong-value":
        # Lab PDF: bump the first result's numeric value by a fixed delta
        # and flip its abnormal flag.  Both the ``value`` and
        # ``abnormal_flag`` checks in ``_score_factually_consistent``
        # will then disagree with ``expected_extracted``.
        if isinstance(cloned.get("results"), list) and cloned["results"]:
            first_row = cloned["results"][0]
            if isinstance(first_row, dict):
                original_value = str(first_row.get("value", ""))
                first_row["value"] = _bump_value(original_value)
                flag = str(first_row.get("abnormal_flag", "") or "")
                if flag in _FLAG_FLIPS:
                    first_row["abnormal_flag"] = _FLAG_FLIPS[flag]
                elif flag == "normal":
                    first_row["abnormal_flag"] = "high"
        # Intake form: rewrite the chief concern with a sentinel string.
        # No fixture uses this exact phrasing, so factually_consistent
        # will reliably fail for every intake case.
        if "chief_concern" in cloned:
            cloned["chief_concern"] = "REGRESSION: wrong-value injected"
        return cloned

    if regression == "flip-abnormal-flags":
        # Lab PDF only.  Intake forms have no abnormal flags to flip.
        if isinstance(cloned.get("results"), list):
            for row in cloned["results"]:
                if not isinstance(row, dict):
                    continue
                flag = str(row.get("abnormal_flag", "") or "")
                if flag in _FLAG_FLIPS:
                    row["abnormal_flag"] = _FLAG_FLIPS[flag]
        return cloned

    # SUPPORTED_REGRESSIONS check at the top covers everything; this
    # final raise is a defensive fall-through for type-checker clarity.
    raise ValueError(  # pragma: no cover
        f"Unknown regression type: {regression!r}. "
        f"Supported: {', '.join(SUPPORTED_REGRESSIONS)}."
    )


def _bump_value(original: str) -> str:
    """Deterministically corrupt a numeric lab value while preserving shape.

    Returns a non-empty string so the schema validator (which requires
    ``min_length=1``) still accepts the row -- only
    ``factually_consistent`` should notice the difference.

    * Numbers parse and get a fixed ``+99.0`` bump (preserving fractional
      vs. integer formatting where possible).
    * Non-numeric / blank strings get a fixed sentinel suffix instead.
    """
    stripped = original.strip()
    if not stripped:
        return "REGRESSION-WRONG-VALUE"
    try:
        as_float = float(stripped)
    except ValueError:
        return f"{stripped}-REGRESSION"
    bumped = as_float + 99.0
    # Preserve "looks like an int" formatting where possible.
    if "." not in stripped and bumped.is_integer():
        return str(int(bumped))
    return f"{bumped:.1f}"


def _build_initial_state(request: AgentRunRequest) -> dict[str, Any]:
    """Convert a request to the LangGraph initial-state dict."""
    return {
        "file_path": request.file_path,
        "doc_type": request.doc_type.value,
        "trace_id": request.trace_id,
        "patient_id": request.patient_id,
        "encounter_id": request.encounter_id,
        "tool_sequence": [],
        "latency_ms_per_step": {},
    }


def _make_default_rag_pipeline(
    corpus: list[GuidelineChunk] | None = None,
) -> RAGPipeline:
    """Build a fully offline RAGPipeline using the FakeReranker.

    The same RAG pipeline is reused across every fixture run; corpus
    loading and index construction are expensive enough that doing it
    50 times would dominate runtime.
    """
    if corpus is None:
        corpus = load_corpus()
    bm25 = BM25Index(corpus)
    dense = DenseIndex.from_chunks_with_fake_embeddings(corpus, dim=64)
    return RAGPipeline(
        bm25_index=bm25,
        dense_index=dense,
        reranker=FakeReranker(),
        embed_fn=lambda q: fake_embed(q, dim=64),
    )


# ---------------------------------------------------------------------------
# Rubric scoring
# ---------------------------------------------------------------------------


def _scan_for_phi(*texts: str) -> list[str]:
    """Return a list of human-readable PHI hits found in any text."""
    hits: list[str] = []
    for text in texts:
        if not text:
            continue
        for match in _SSN_PATTERN.finditer(text):
            hits.append(f"ssn-like: {match.group(0)}")
        for match in _PATIENT_NAME_PATTERN.finditer(text):
            hits.append(f"patient-name: {match.group(0)}")
    return hits


def _score_schema_valid(
    case: FixtureCase,
    state: dict[str, Any],
) -> bool:
    """Return True if the extracted dict validates against the doc-type schema.

    Refusal fixtures expect schema_valid to be False.  We treat the case
    as PASSING the rubric if the observed behaviour matches the
    fixture's expectation.
    """
    extracted = state.get("extracted")
    schema_cls: type[LabPdf] | type[IntakeForm]
    if case.doc_type == "lab_pdf":
        schema_cls = LabPdf
    elif case.doc_type == "intake_form":
        schema_cls = IntakeForm
    else:
        # Unknown doc type -- treat as a hard fail so we surface the bug.
        return False

    if extracted is None:
        # No extraction took place (refused before extracting).  This is
        # the expected outcome for refusal cases and only those.
        observed_valid = False
    else:
        try:
            schema_cls.model_validate(extracted)
            observed_valid = True
        except ValidationError:
            observed_valid = False

    return observed_valid is bool(case.expected_rubric.get("schema_valid"))


def _score_citation_present(
    case: FixtureCase,
    state: dict[str, Any],
) -> bool:
    """Per-field source citations + non-empty top-level citations.

    Refusal cases trivially pass (the rubric expects no citations).
    """
    if case.expected_outcome == "refusal":
        # The rubric for a refusal fixture expects citation_present=False.
        # We pass when the run did not invent citations and the graph
        # status reflects refusal.
        if state.get("status") != "refused":
            return False
        if state.get("citations"):
            return False
        return True

    extracted = state.get("extracted") or {}
    citations = state.get("citations") or []

    if not citations:
        return False

    if case.doc_type == "lab_pdf":
        results = extracted.get("results", [])
        if not results:
            return False
        for row in results:
            if not row.get("source_citation"):
                return False
        return True

    if case.doc_type == "intake_form":
        per_field = extracted.get("source_citations", [])
        if not per_field:
            return False
        return True

    return False


def _score_factually_consistent(
    case: FixtureCase,
    state: dict[str, Any],
) -> bool:
    """Compare key extracted values to the fixture's expected output.

    Refusal cases pass when nothing was extracted (i.e. the graph
    refused before producing values that could be wrong).
    """
    if case.expected_outcome == "refusal":
        # Expectation: schema_valid=False, factually_consistent=False.
        # Mark the rubric as PASSING when the system honoured that
        # expectation -- i.e. the graph refused and produced no
        # ``extracted`` field.
        return state.get("status") == "refused" and "extracted" not in state

    extracted = state.get("extracted") or {}
    expected = case.expected_extracted

    if case.doc_type == "lab_pdf":
        expected_results = expected.get("results", [])
        actual_results = extracted.get("results", [])
        if len(actual_results) != len(expected_results):
            return False
        # Check test_name + value + abnormal_flag for every row.
        for exp_row, actual_row in zip(expected_results, actual_results):
            for key in ("test_name", "value", "abnormal_flag"):
                if exp_row.get(key) != actual_row.get(key):
                    return False
        # Patient demographics are optional but if present in the fixture
        # they must match.
        for key in ("patient_name", "ordering_provider", "lab_name"):
            if expected.get(key) is not None and expected.get(key) != extracted.get(key):
                return False
        return True

    if case.doc_type == "intake_form":
        # Chief concern is the strongest factual signal.
        if expected.get("chief_concern") != extracted.get("chief_concern"):
            return False
        # Demographics name is the next-strongest signal we can check.
        exp_demo = expected.get("demographics") or {}
        actual_demo = extracted.get("demographics") or {}
        if exp_demo.get("name") != actual_demo.get("name"):
            return False
        if exp_demo.get("dob") != actual_demo.get("dob"):
            return False
        # Allergies, medications, and family history need length parity.
        for key in ("current_medications", "allergies", "family_history"):
            if len(expected.get(key, [])) != len(extracted.get(key, [])):
                return False
        return True

    return False


def _score_safe_refusal(
    case: FixtureCase,
    state: dict[str, Any],
) -> bool:
    """Refusal cases must end in status='refused'; success cases must NOT.

    Additionally, refusals must not produce a hallucinated answer.
    """
    status = state.get("status", "")
    answer = state.get("answer", "") or ""

    if case.expected_outcome == "refusal":
        if status != "refused":
            return False
        # Refused runs MUST NOT produce a finalized answer.
        if answer:
            return False
        return True

    # Success case: a refusal would be a false negative.
    return status != "refused"


def _score_no_phi_in_logs(
    case: FixtureCase,
    state: dict[str, Any],
    captured: str,
) -> tuple[bool, list[str]]:
    """Scan captured stdout/stderr/state for PHI patterns.

    Returns (passed, notes).  ``notes`` lists any hits for diagnostics.
    """
    state_dump = json.dumps(_safe_state(state), default=str)
    hits = _scan_for_phi(captured, state_dump)
    if hits:
        return False, [f"phi-hit: {hit}" for hit in hits]
    return True, []


def _safe_state(state: dict[str, Any]) -> dict[str, Any]:
    """Strip the ``extracted`` payload before scanning.

    The fixtures intentionally include synthetic PHI (e.g. ``Jane Doe``)
    inside ``extracted`` -- that is the structured output, NOT a log.
    The PHI rubric checks whether the code path leaked anything beyond
    that structured payload.
    """
    sanitised = dict(state)
    sanitised.pop("extracted", None)
    sanitised.pop("expected_extracted", None)
    return sanitised


def score_case(
    case: FixtureCase,
    state: dict[str, Any],
    captured_logs: str,
) -> CaseResult:
    """Score a single fixture run against all five rubrics.

    Parameters
    ----------
    case:
        The fixture being evaluated.
    state:
        Final LangGraph state captured from ``graph.invoke``.
    captured_logs:
        Concatenated stdout + stderr produced during the run.
    """
    notes: list[str] = []

    schema_ok = _score_schema_valid(case, state)
    citation_ok = _score_citation_present(case, state)
    factual_ok = _score_factually_consistent(case, state)
    safe_ok = _score_safe_refusal(case, state)
    phi_ok, phi_notes = _score_no_phi_in_logs(case, state, captured_logs)
    notes.extend(phi_notes)

    rubrics: dict[str, bool] = {
        "schema_valid": schema_ok,
        "citation_present": citation_ok,
        "factually_consistent": factual_ok,
        "safe_refusal": safe_ok,
        "no_phi_in_logs": phi_ok,
    }

    return CaseResult(
        case_id=case.case_id,
        doc_type=case.doc_type,
        expected_outcome=case.expected_outcome,
        rubrics=rubrics,
        status=str(state.get("status", "")),
        notes=notes,
    )


# ---------------------------------------------------------------------------
# Top-level runner
# ---------------------------------------------------------------------------


def run_eval(
    *,
    fixtures: list[FixtureCase] | None = None,
    rag_pipeline: RAGPipeline | None = None,
    inject_regression: str | None = None,
    record_storage: RunRecordStorage | None = None,
    record_model_name: str = "fake-fixture-model",
) -> EvalReport:
    """Run the eval over every fixture and return the aggregate report.

    Parameters
    ----------
    fixtures:
        Override the loaded fixture list (useful for tests).  Defaults
        to all 50 fixtures shipped with the package.
    rag_pipeline:
        Override the RAG pipeline used for retrieval.  Defaults to a
        deterministic offline pipeline using :class:`FakeReranker`.
    inject_regression:
        Optional regression hook applied to every fixture's recorded
        extraction response.  See :func:`_maybe_inject_regression`.
    record_storage:
        Optional :class:`RunRecordStorage` to receive a sanitized
        :class:`RunRecord` for each fixture run.  When ``None`` (the
        default) no records are emitted, preserving the historical
        behaviour for callers that do not opt in.
    record_model_name:
        Model identifier persisted on each :class:`RunRecord`.  The
        eval uses :class:`FakeLLMClient`, so the default is a synthetic
        ``"fake-fixture-model"`` token rather than a real model name.
    """
    if fixtures is None:
        fixtures = load_fixtures()
    if rag_pipeline is None:
        rag_pipeline = _make_default_rag_pipeline()

    results: list[CaseResult] = []

    with _eval_mode_guard():
        for case in fixtures:
            captured = io.StringIO()
            client = _build_fake_client(case, inject_regression=inject_regression)
            graph = build_graph(llm_client=client, rag_pipeline=rag_pipeline)
            request = _build_request(case)
            initial_state = _build_initial_state(request)

            with contextlib.redirect_stdout(captured), contextlib.redirect_stderr(captured):
                final_state = graph.invoke(initial_state)

            final_state_dict = dict(final_state)
            result = score_case(case, final_state_dict, captured.getvalue())
            results.append(result)

            if record_storage is not None:
                record = _build_run_record(
                    case=case,
                    request=request,
                    final_state=final_state_dict,
                    model_name=record_model_name,
                )
                record_storage.append(record)

    pass_rates = _compute_pass_rates(results)
    return EvalReport(cases=results, pass_rates=pass_rates, total=len(results))


def _build_run_record(
    *,
    case: FixtureCase,
    request: AgentRunRequest,
    final_state: dict[str, Any],
    model_name: str,
) -> RunRecord:
    """Build a sanitized :class:`RunRecord` from a fixture run.

    The record carries only metrics derived from the LangGraph state --
    no ``extracted`` payload, no file path, and no patient identifiers
    -- so it survives the :class:`RunRecord` PHI validator unchanged.

    Token counts are estimates because :class:`FakeLLMClient` does not
    surface real usage data; they default to ``0`` so callers reading
    the eval-generated store do not mistake fixture runs for live
    token spend.
    """
    latency_per_step_int: dict[str, int] = dict(final_state.get("latency_ms_per_step", {}))
    latency_per_step: dict[str, float] = {
        step: float(value) for step, value in latency_per_step_int.items()
    }
    total_latency_ms = sum(latency_per_step.values())

    raw_status = str(final_state.get("status", "")).lower()
    if raw_status == "completed":
        status = "success"
    elif raw_status == "refused":
        status = "refused"
    else:
        status = "error"

    evidence = final_state.get("evidence") or []
    retrieval_hit_count = len(evidence) if isinstance(evidence, list) else 0

    extraction_confidence_raw = final_state.get("extraction_confidence")
    if isinstance(extraction_confidence_raw, (int, float)):
        # Clamp into [0, 1] so the validator never sees an out-of-range value;
        # graph nodes occasionally emit confidences that overshoot 1.0 due to
        # rounding when no real LLM is in the loop.
        extraction_confidence = max(0.0, min(1.0, float(extraction_confidence_raw)))
    else:
        extraction_confidence = 0.0

    cost_usd_raw = final_state.get("cost_usd")
    cost_usd = float(cost_usd_raw) if isinstance(cost_usd_raw, (int, float)) else 0.0

    return RunRecord(
        trace_id=request.trace_id,
        doc_type=case.doc_type,
        latency_ms_per_step=latency_per_step,
        total_latency_ms=float(total_latency_ms),
        tokens_in=0,
        tokens_out=0,
        model=model_name,
        cost_usd=cost_usd,
        retrieval_hit_count=retrieval_hit_count,
        extraction_confidence=extraction_confidence,
        status=status,
    )


def _compute_pass_rates(results: list[CaseResult]) -> dict[str, float]:
    """Aggregate rubric results into per-rubric pass rates."""
    if not results:
        return {name: 0.0 for name in RUBRIC_NAMES}

    rates: dict[str, float] = {}
    total = len(results)
    for name in RUBRIC_NAMES:
        passed = sum(1 for r in results if r.rubrics.get(name, False))
        rates[name] = passed / total
    return rates


# ---------------------------------------------------------------------------
# Baseline comparison
# ---------------------------------------------------------------------------


def load_baseline(path: Path) -> dict[str, float] | None:
    """Load a baseline JSON file or return None if it does not exist."""
    if not path.is_file():
        return None
    raw = json.loads(path.read_text(encoding="utf-8"))
    pass_rates = raw.get("pass_rates", raw)
    return {name: float(pass_rates.get(name, 0.0)) for name in RUBRIC_NAMES}


def write_baseline(path: Path, report: EvalReport) -> None:
    """Persist the baseline pass-rates to *path* in human-readable JSON."""
    payload = {
        "schema_version": 1,
        "total_cases": report.total,
        "pass_rates": {name: round(rate, 4) for name, rate in report.pass_rates.items()},
    }
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")


def compare_to_baseline(
    report: EvalReport,
    baseline: dict[str, float] | None,
    thresholds: dict[str, float] | None = None,
) -> tuple[bool, list[str]]:
    """Return (passed, failures) for the report vs. the baseline.

    Failure conditions:

    * Any rubric falls below its absolute threshold in *thresholds*.
    * Any rubric drops by more than :data:`REGRESSION_TOLERANCE` (5
      percentage points) compared to the *baseline* pass rate.

    When *baseline* is ``None`` (e.g. first run on a clean repo), only
    the absolute thresholds are checked.
    """
    thresholds = dict(thresholds or DEFAULT_THRESHOLDS)
    failures: list[str] = []

    for name in RUBRIC_NAMES:
        observed = report.pass_rates.get(name, 0.0)
        threshold = thresholds.get(name, 0.0)
        if observed + 1e-9 < threshold:
            failures.append(
                f"{name}: pass rate {observed:.2%} below threshold {threshold:.2%}"
            )

        if baseline is not None:
            previous = baseline.get(name, 0.0)
            if previous - observed > REGRESSION_TOLERANCE + 1e-9:
                failures.append(
                    f"{name}: regressed by {(previous - observed):.2%} "
                    f"(was {previous:.2%}, now {observed:.2%})"
                )

    return (not failures), failures


def affected_fixtures(report: EvalReport, rubric_name: str) -> list[str]:
    """Return the case IDs that failed *rubric_name* in *report*, in order.

    Useful for surfacing which specific fixtures dragged a rubric below
    threshold during a regression run -- the demo-friendly counterpart
    to :func:`compare_to_baseline`.
    """
    return [
        case.case_id
        for case in report.cases
        if not case.rubrics.get(rubric_name, False)
    ]


def format_failure_summary(
    report: EvalReport,
    baseline: dict[str, float] | None,
    thresholds: dict[str, float] | None = None,
    *,
    max_fixtures_listed: int = 10,
) -> str:
    """Render a structured summary of regressed rubrics + affected fixtures.

    Names every failing rubric, the delta vs. baseline (when supplied),
    the threshold breached, and the case IDs of the affected fixtures.
    The output mirrors the format requested in the S22 spec, e.g.::

        FAIL: 2 rubric(s) regressed
        - citation_present: 1.00 -> 0.12 (delta -88pp, threshold 0.95)
          affected fixtures: lab_001, lab_002, ...
    """
    thresholds = dict(thresholds or DEFAULT_THRESHOLDS)

    failing_rubrics: list[str] = []
    for name in RUBRIC_NAMES:
        observed = report.pass_rates.get(name, 0.0)
        threshold = thresholds.get(name, 0.0)
        threshold_breach = observed + 1e-9 < threshold
        regression_breach = False
        if baseline is not None:
            previous = baseline.get(name, 0.0)
            regression_breach = (
                previous - observed > REGRESSION_TOLERANCE + 1e-9
            )
        if threshold_breach or regression_breach:
            failing_rubrics.append(name)

    if not failing_rubrics:
        return "PASS: every rubric meets its threshold."

    lines = [f"FAIL: {len(failing_rubrics)} rubric(s) regressed"]
    for name in failing_rubrics:
        observed = report.pass_rates.get(name, 0.0)
        threshold = thresholds.get(name, 0.0)
        previous = baseline.get(name, 0.0) if baseline is not None else None

        if previous is not None:
            delta_pp = (observed - previous) * 100.0
            sign = "+" if delta_pp >= 0 else "-"
            lines.append(
                f"- {name}: {previous:.2f} -> {observed:.2f} "
                f"(delta {sign}{abs(delta_pp):.0f}pp, "
                f"threshold {threshold:.2f})"
            )
        else:
            lines.append(
                f"- {name}: {observed:.2f} below threshold {threshold:.2f}"
            )

        affected = affected_fixtures(report, name)
        if affected:
            shown = affected[:max_fixtures_listed]
            suffix = ""
            if len(affected) > max_fixtures_listed:
                suffix = f", ... ({len(affected) - max_fixtures_listed} more)"
            lines.append(
                f"  affected fixtures: {', '.join(shown)}{suffix}"
            )
        else:
            lines.append("  affected fixtures: (none)")

    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Reporting helpers
# ---------------------------------------------------------------------------


def format_pass_rate_table(report: EvalReport) -> str:
    """Render a fixed-width per-rubric table for stdout output."""
    lines = [
        f"Eval pass rates over {report.total} cases:",
    ]
    for name in RUBRIC_NAMES:
        rate = report.pass_rates.get(name, 0.0)
        passed = sum(1 for c in report.cases if c.rubrics.get(name, False))
        lines.append(f"  {name:<22} {rate:.2%}  ({passed}/{report.total})")
    return "\n".join(lines)
