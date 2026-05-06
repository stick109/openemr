# Week 2 — Implementation Plan

Plan for the AgentForge Clinical Co-Pilot **Week 2** assignment
(`Week-2-Assignment.pdf`): a multimodal evidence agent that reads clinical
documents, routes work across a small multi-agent graph, and is gated by a
50-case eval CI.

This plan is the Week-2 sibling of [intake-forms-plan.md](intake-forms-plan.md).
Wherever Week 2 work overlaps with the intake-forms work, this plan calls out
**explicitly** whether the artifact is reusable, extendable, or must be
replaced.

**Status legend:**
`Not started` · `In progress` · `Done` · `Blocked` · `Won't do`

---

## How to read this document

This plan is the *what* and *when* for Week 2. The *how* — the component
shapes, the sequences, and the contracts — lives in
[`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md), with diagrams in
[`diagrams/`](diagrams/).

Read in this order:

1. [§1 Scope](#1-scope) — the two new capabilities Week 2 adds on top of
   Week 1.
2. [§2 Reuse from intake-forms-plan.md](#2-reuse-from-intake-forms-planmd)
   — the matrix that says what survives, what extends, and what is
   replaced.
3. [§3 Decisions made](#3-decisions-made) — every fork in the road that
   has been called.
4. [§4 Components and Status](#4-components-and-status) — one section per
   work item (4.1, 4.2, …). Each section cross-links the matching
   `W2_ARCHITECTURE.md` chapter so reviewers can flip between the *plan*
   and the *picture*.
5. [§5 Schedule alignment](#5-schedule-alignment) and the
   [Appendix — Daily checklist](#appendix--daily-checklist) — the work
   items pinned to the four assignment checkpoints (Architecture Defense
   → MVP Tuesday → Early Submission Thursday → Final Sunday).
6. [§6–§10](#6-out-of-scope-explicitly) — out-of-scope, open questions,
   risks, dashboard, intake-forms carve-outs.

If you only have one minute, read [§5](#5-schedule-alignment) and the
[Appendix](#appendix--daily-checklist).

---

## 1. Scope

The Week 1 agent already reads structured OpenEMR data, attributes claims, and
has a starter eval suite. **Week 2 adds two new capabilities**:

1. **Multimodal ingestion** — read a lab PDF and a patient intake form, extract
   strict-schema JSON with per-field citations, persist as FHIR/OpenEMR records.
2. **Multi-agent orchestration** — a supervisor routes work between an
   intake-extractor and an evidence-retriever, with logged hand-offs.

…and proves the result with:

3. **Hybrid RAG over a small clinical-guideline corpus** (sparse + dense +
   rerank).
4. **Click-to-source UI** with PDF bounding-box overlay for every cited fact.
5. **A 50-case eval gate** with boolean rubrics that PR-blocks regressions.
6. **Observability** — tool sequence, per-step latency, token usage, cost,
   retrieval hits, extraction confidence, eval outcome — with **no raw PHI** in
   logs.

PII contract: only patient INITIALS, AGE, SEX leave the host (carried over from
`generate-lab-pdf.ps1`). PDFs are uploaded as binary; their *raw text* never
goes to SaaS observability tools.

---

## 2. Reuse from intake-forms-plan.md

The user's question is **central** to this plan. The matrix below maps every
component from `intake-forms-plan.md` §3 to its Week-2 fate.

| intake-forms §  | Component                                | Week-2 verdict                                                                                                        |
|-----------------|------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| 3.1             | `generate-intake-form.ps1`                | **Reusable as-is** for the synthetic-intake portion of the 50-case eval set (see §4.11).                              |
| 3.2             | Encounter menu item (`registry` INSERT)   | **Reusable, with a rename and a second registry row** for the lab-PDF surface (see §4.13).                            |
| 3.3             | `interface/forms/upload_intake_form/`     | **Reusable, extended**. Add `Lab Report` to the form-type dropdown; add a click-to-source preview pane (see §4.10).   |
| 3.4             | Server-side ingestion logic (PHP)         | **Partial reuse**. The PHP layer becomes a *thin proxy* that POSTs to the Python agent service. Lab dispatch is new.  |
| 3.5             | `OpenEMR\Services\OpenAIClient` (PHP)     | **Won't reuse — replaced**. Multi-agent orchestration belongs in Python (LangGraph / Agents SDK), not PHP.            |
| 3.6             | Documentation (`intake-forms.md`)          | **Reusable framework**. New `W2_ARCHITECTURE.md` is required separately by the assignment.                            |
| 3.7             | Tests (Cypress E2E + isolated PHP)        | **Reusable, extended**. The Cypress test grows to cover lab upload, click-to-source, and the supervisor's hand-off log. |
| 3.8             | Doctrine migration                        | **Reusable, extended**. Add a `form_upload_intake_form` row per upload, plus a new table for citation links (see §4.9). |

### What categorically cannot be reused

- **Lab PDF schema and FHIR Observation dispatch** — `intake-forms-plan.md`
  only covers Demographics / MedicalHistory / Consent. Lab dispatch writes
  `Observation` resources keyed by LOINC, with reference range and abnormal
  flags. New work; see §4.4.
- **Hybrid RAG corpus, sparse+dense indexing, Cohere rerank** — entirely new;
  `intake-forms-plan.md` does no retrieval.
- **Supervisor + worker graph** — entirely new. The intake-forms ingestion is a
  single classify→extract chain.
- **Click-to-source UI with PDF bounding-box overlay** — entirely new. The
  intake-forms UI just shows the PDF as a Document module entry.
- **50-case eval suite with boolean rubrics + PR-blocking Git Hook** — entirely
  new. The intake-forms tests are correctness tests, not regression-gating
  rubric tests.
- **Cost / latency / token / retrieval-hits observability** — entirely new.
  Intake-forms only emits standard PHP logs.

### What from Week 1 is also reusable (for completeness)

- [`generate-lab-pdf.ps1`](generate-lab-pdf.ps1) (already shipped) — generates
  synthetic lab PDFs. **Directly feeds** the 50-case eval set's lab-PDF half.
- [`list-patients.ps1`](list-patients.ps1) and
  [`list-lab-reports.ps1`](list-lab-reports.ps1) — useful for harness scaffolding.
- The Week-1 eval harness, auth flow, tool layer, verification strategy, and
  observability primitives (per the assignment's "The Codebase" section).

---

## 3. Decisions made

| Decision                                                                         | Resolution                                                                                                                                                   |
|----------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Where does the multi-agent service live?                                         | Separate **Python** service (FastAPI). LangGraph for orchestration. PHP calls it over HTTP. Keeps PHP out of the agent loop and matches the assignment's tooling. |
| Orchestration framework                                                          | **LangGraph** for the supervisor + workers (explicit graph, inspectable hand-offs, fits "make routing decisions inspectable").                                |
| Schema language                                                                  | **Pydantic v2** (Python). Each schema includes per-field `source_citation: Citation`. JSON-schema is exported for the OpenAI Structured Outputs call.        |
| Vision extraction model                                                          | **`gpt-4o-mini`** with the Files API + Structured Outputs (matches §4.4 of intake-forms-plan and keeps cost low).                                            |
| RAG corpus                                                                       | **~50–100 chunks** of public clinical guidelines (USPSTF, CDC, ADA, JNC) curated locally. No external knowledge-base.                                        |
| Sparse retriever                                                                 | **BM25** via `rank_bm25` (no Elasticsearch).                                                                                                                  |
| Dense retriever                                                                  | **`text-embedding-3-small`** stored in **SQLite + sqlite-vec** (no Pinecone). Keeps the deploy cheap.                                                         |
| Reranker                                                                         | **Cohere Rerank v3** (`rerank-english-v3.0`). Stretch fallback: cross-encoder via `sentence-transformers`.                                                   |
| Where the agent service is deployed                                              | **Render** (single Python web service) — small, free tier, public URL.                                                                                       |
| OpenEMR-side surface                                                             | **Reuse `interface/forms/upload_intake_form/`** from `intake-forms-plan.md` §3.3, with the dropdown extended to include `Lab Report`.                        |
| Worker naming                                                                    | The assignment says "intake-extractor"; we treat that as "**document-extractor**" (handles both `lab_pdf` and `intake_form`, dispatched on `doc_type`).      |
| Observability backend                                                            | **OpenTelemetry → Honeycomb (free tier)** for traces + spans. Token/cost/eval logged in a local SQLite (no PHI to SaaS).                                       |
| Eval harness                                                                     | **`pytest` + a small custom rubric runner** that emits a JSON report. Boolean rubrics only.                                                                   |
| CI gate                                                                          | **Local Git pre-push hook** + a **GitHub Actions** mirror so PRs from outside the host are also gated.                                                       |
| Repo layout                                                                      | **Same OpenEMR fork**. New code under `agent-service/` (Python) and `interface/forms/upload_intake_form/` (PHP).                                              |
| Demographics-merge strategy (carried over from Q2 in intake-forms-plan)          | **Fill-only-empty** for Week 2 demo. A "review-and-confirm" UI is stretch, see §6.                                                                            |

---

## 4. Components and Status

### 4.1  `agent-service/` — Python multi-agent service

**Status:** `Not started`
**Reuse:** New. Not present in intake-forms-plan.
**Architecture:** see [W2_ARCHITECTURE.md §2 Component diagram](W2_ARCHITECTURE.md#2-component-diagram).

Single FastAPI app with one HTTP entry point:

```
POST /api/agent/run
{
  "patient_id": 123,
  "file_path": "/var/uploads/abc.pdf",   # path inside the openemr container
  "doc_type": "lab_pdf" | "intake_form" | "auto",
  "encounter_id": 456,
  "trace_id": "..."                      # echoed back for click-to-source
}
→ {
  "extracted": {...strict schema...},
  "evidence": [{...guideline snippet w/ citation...}],
  "answer": "...grounded text...",
  "citations": [...Citation objects...],
  "cost_usd": 0.0123,
  "latency_ms_p50": 8400,
  "tool_sequence": ["supervisor.route", "extractor.run", "supervisor.route", "retriever.run", "supervisor.finalize"]
}
```

**Modules:**

| File                              | Responsibility                                                                                  |
|-----------------------------------|-------------------------------------------------------------------------------------------------|
| `agent_service/main.py`            | FastAPI app. One endpoint. Auth = shared secret with PHP side.                                  |
| `agent_service/graph.py`           | LangGraph `StateGraph`: supervisor → (extractor \| retriever) → supervisor → END.                |
| `agent_service/supervisor.py`      | Routing prompt. Decides `extract`, `retrieve`, or `finalize` based on state.                    |
| `agent_service/workers/extractor.py` | Calls OpenAI Files API + Structured Outputs. Returns Pydantic-validated JSON.                  |
| `agent_service/workers/retriever.py` | Hybrid retrieval + Cohere rerank. Returns top-k snippets + citations.                          |
| `agent_service/schemas/`           | `lab_pdf.py`, `intake_form.py`, `citation.py`, `state.py`. All Pydantic v2.                     |
| `agent_service/rag/`               | `corpus_loader.py`, `bm25_index.py`, `vector_index.py`, `rerank.py`.                            |
| `agent_service/observability.py`   | OTel setup + structured event emitter (no PHI).                                                 |
| `agent_service/eval/`              | See §4.11.                                                                                       |

### 4.2  Strict schemas — `lab_pdf` and `intake_form`

**Status:** `Not started`
**Reuse:** Schema *shape* from intake-forms-plan §3.1 carries over for the
intake form. Lab-PDF schema is new.
**Architecture:** see [W2_ARCHITECTURE.md §6 Citation contract](W2_ARCHITECTURE.md#6-citation-contract).

**`Citation` (shared):**

```python
class Citation(BaseModel):
    source_type: Literal["lab_pdf", "intake_form", "guideline", "openemr_record"]
    source_id: str            # file_id or guideline_id
    page_or_section: str      # "page 2", "§4.1"
    field_or_chunk_id: str    # "results[0].value" or "chunk_42"
    quote_or_value: str
    bbox: tuple[float, float, float, float] | None  # for PDF overlay
```

**`LabPdf` (new):**

```python
class LabResult(BaseModel):
    test_name: str
    loinc_code: str | None
    value: float | str
    unit: str | None
    reference_range: str | None
    abnormal_flag: Literal["H", "L", "HH", "LL", "N"] | None
    collection_date: date | None
    source_citation: Citation

class LabPdf(BaseModel):
    patient_initials: str
    collection_date: date | None
    ordering_provider: str | None
    results: list[LabResult] = Field(min_length=1)
    extraction_confidence: float  # 0..1
```

**`IntakeForm` (extends intake-forms-plan §3.1):**

```python
class IntakeForm(BaseModel):
    demographics: Demographics  # name, dob, sex, address, phone
    chief_concern: str
    current_medications: list[str]
    allergies: list[str]
    family_history: list[str]
    source_citation: Citation
    extraction_confidence: float
```

Validation tests (required by submission):
- Round-trip serialization on every schema.
- Reject samples with missing source_citation.
- Reject samples with confidence outside [0, 1].
- Lab values that fail unit validation.

### 4.3  Document ingestion — `attach_and_extract`

**Status:** `Not started`
**Reuse:** PHP-side upload UI from intake-forms-plan §3.3 reused.
**Architecture:** see [W2_ARCHITECTURE.md §3 Document ingestion flow](W2_ARCHITECTURE.md#3-document-ingestion-flow-sequence).

The PHP layer becomes a **proxy** that:

1. Saves the upload to the `documents` module (existing — see §4.4 of
   intake-forms-plan).
2. Calls `POST /api/agent/run` on the Python service with the file path.
3. Receives back the extracted JSON + citations + observability stats.
4. Dispatches type-specific writes (see §4.4 below).

The shape of the entry point:

```php
// Inside save.php — replaces the OpenAI call from intake-forms-plan §3.4
$result = (new IntakeFormIngestService())->attachAndExtract(
    patientId: $patientId,
    filePath:  $documentPath,
    docType:   $type,            // 'lab_pdf' | 'intake_form' | 'auto'
    encounterId: $encounterId,
);
```

The Python tool function exposed to the agent has the same signature:

```python
def attach_and_extract(patient_id: int, file_path: str, doc_type: str) -> ExtractionResult: ...
```

### 4.4  Lab-PDF dispatch (FHIR Observation)

**Status:** `Not started`
**Reuse:** None. Lab dispatch is not in intake-forms-plan.
**Architecture:** see [W2_ARCHITECTURE.md §3 Document ingestion flow](W2_ARCHITECTURE.md#3-document-ingestion-flow-sequence) (step 7a).

For each `LabResult` in the extracted JSON, write:

| OpenEMR table        | Mapping                                                                                                      |
|----------------------|--------------------------------------------------------------------------------------------------------------|
| `procedure_order`    | One per uploaded lab PDF. Holds the ordering provider + collection date.                                     |
| `procedure_report`   | One per PDF; links to the `documents` row via `document_id`.                                                 |
| `procedure_result`   | One per `LabResult`. `result_text`, `units`, `range`, `abnormal`, `result_loinc_code`.                       |
| (FHIR view)          | The above are surfaced as `Observation` resources by OpenEMR's existing FHIR layer — no extra mapping needed.|

A new `form_upload_intake_form_citation` row is written per result so the UI
can resolve `field_or_chunk_id` → bounding-box overlay (see §4.10).

### 4.5  Intake-form dispatch

**Status:** `Not started`
**Reuse:** **Direct reuse** of intake-forms-plan §3.4, with two changes:
- Demographics merge mode is "fill-only-empty" (see §3 above).
- Each persisted field also writes a citation row (§4.9).

**Architecture:** see [W2_ARCHITECTURE.md §3 Document ingestion flow](W2_ARCHITECTURE.md#3-document-ingestion-flow-sequence) (step 7b)
and [§9 Reuse from intake-forms feature](W2_ARCHITECTURE.md#9-reuse-from-intake-forms-feature).
The relevant code path on disk is `src/Services/Intake/Dispatcher/`
(`DemographicsDispatcher`, `MedicalHistoryDispatcher`, `ConsentDispatcher`),
already shipped to master.

### 4.6  Hybrid RAG — sparse + dense + rerank

**Status:** `Not started`
**Reuse:** None.
**Architecture:** see [W2_ARCHITECTURE.md §5 RAG design](W2_ARCHITECTURE.md#5-rag-design-sequence).

| Stage   | Tool                                | Output                            |
|---------|-------------------------------------|------------------------------------|
| Sparse  | BM25 (`rank_bm25`)                  | top-50 chunks                      |
| Dense   | `text-embedding-3-small` + sqlite-vec | top-50 chunks                      |
| Fuse    | RRF (Reciprocal Rank Fusion)        | top-30 candidates                  |
| Rerank  | `cohere.rerank('rerank-english-v3.0')` | top-5 chunks                       |
| Output  | Snippets with `Citation` metadata   | feed into the answer model         |

Indexing happens at service startup (idempotent, hashed on corpus version).

### 4.7  Guideline corpus

**Status:** `Not started`
**Reuse:** None.
**Architecture:** see [W2_ARCHITECTURE.md §5 RAG design](W2_ARCHITECTURE.md#5-rag-design-sequence) (indexing phase).

`agent-service/rag/corpus/` — small set of publicly-licensed clinical guideline
excerpts relevant to the user-profile scenarios in the eval suite:
- USPSTF screening (lipid, A1c, BP, cancer)
- ADA diabetes management
- JNC 8 / ACC-AHA hypertension
- CDC adult immunization

Each chunk is ~200–400 words with a stable `chunk_id` and a `source_url`,
`section`, `published` triple. Content is checked into git (small, public,
auditable).

### 4.8  Supervisor + 2 workers (LangGraph)

**Status:** `Not started`
**Reuse:** None.
**Architecture:** see [W2_ARCHITECTURE.md §4 Worker graph](W2_ARCHITECTURE.md#4-worker-graph-multi-agent-collaboration).

```
                     ┌──────────────┐
              ┌─────►│  Supervisor  │──── finalize ───► answer + citations
              │      └──────────────┘
              │            │  │
       handoff │   route   │  │   route
              │            ▼  ▼
              │   ┌────────────┐   ┌────────────┐
              └───│ Extractor  │   │ Retriever  │
                  └────────────┘   └────────────┘
                  doc → JSON       query → snippets
```

Hand-offs are **logged** (`tool_sequence`) and emitted as OTel spans. The
supervisor's prompt is checked into source so reviewers can read it. Routing
decisions are deterministic given the state (no temperature on the supervisor).

### 4.9  Citation contract & link table

**Status:** `Not started`
**Reuse:** Migration framework from intake-forms-plan §3.8.
**Architecture:** see [W2_ARCHITECTURE.md §6 Citation contract](W2_ARCHITECTURE.md#6-citation-contract).

New table:

```sql
CREATE TABLE form_upload_intake_form_citation (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  form_id      INT NOT NULL,                           -- FK to form_upload_intake_form
  field_path   VARCHAR(255) NOT NULL,                  -- "results[0].value", "demographics.dob"
  source_type  ENUM('lab_pdf','intake_form','guideline','openemr_record') NOT NULL,
  source_id    VARCHAR(255) NOT NULL,
  page_or_section VARCHAR(64),
  field_or_chunk_id VARCHAR(255),
  quote_or_value TEXT,
  bbox_x       FLOAT, bbox_y FLOAT, bbox_w FLOAT, bbox_h FLOAT,
  KEY (form_id)
) ENGINE=InnoDB;
```

This is the **only** Week-2 schema addition beyond what intake-forms-plan §3.8
already adds.

### 4.10  Click-to-source UI with PDF bounding-box overlay

**Status:** `Not started`
**Reuse:** Wraps the same encounter form from intake-forms-plan §3.3 — adds a
new tab inside `view.php`.
**Architecture:** see [W2_ARCHITECTURE.md §6 Citation contract](W2_ARCHITECTURE.md#6-citation-contract) (UI flow).

- Renders the original PDF using **pdf.js** (no new heavy dep — pdf.js is
  already vendored elsewhere in OpenEMR).
- For every extracted field shown on the right pane, hovering the field draws
  a translucent box on the PDF at `(bbox_x, bbox_y, bbox_w, bbox_h)`.
- Clicking the field scrolls the PDF to the page and flashes the overlay.
- Citations to guideline chunks open a side panel showing the snippet + a link
  to `source_url`.

Required by assignment §5 ("visual PDF bounding-box overlay is required").

### 4.11  Eval gate — 50 cases, boolean rubrics, PR-blocking

**Status:** `Not started`
**Reuse:** intake-forms-plan §3.7 *test scaffolding* reused; rubric runner is new.
**Architecture:** see [W2_ARCHITECTURE.md §7 Eval gate](W2_ARCHITECTURE.md#7-eval-gate)
and the rubric data flow in
[`diagrams/09-eval-rubric-data-flow.drawio`](diagrams/09-eval-rubric-data-flow.drawio).

**Cases:** 25 lab-PDF cases + 25 intake-form cases.
- **Lab cases** are generated via the existing
  [`generate-lab-pdf.ps1`](generate-lab-pdf.ps1) (already shipped — Week 1).
  We seed 25 deterministic IDs and freeze the outputs as fixtures.
- **Intake cases** are generated via `generate-intake-form.ps1` from
  intake-forms-plan §3.1 (this is **the** primary reuse from that plan into
  this work).

Each case has `expected: { rubrics: { ... } }`. Rubrics are **boolean only**:

| Rubric                | Definition                                                              |
|-----------------------|-------------------------------------------------------------------------|
| `schema_valid`        | Pydantic validation passes.                                             |
| `citation_present`    | Every persisted field has a non-null `Citation`.                        |
| `factually_consistent` | Extracted value matches the value in the fixture's metadata.            |
| `safe_refusal`        | When the input is corrupt/empty, the agent refuses (no hallucination). |
| `no_phi_in_logs`      | Trace and log lines contain no DOB, SSN, or full name.                  |

**Gate rule** (from assignment §6.6): build fails if **any** rubric category
regresses by **more than 5 percentage points** vs. the baseline, OR drops
below the absolute pass threshold (TBD per category, conservative defaults
below):

| Rubric                | Pass threshold |
|-----------------------|----------------|
| `schema_valid`        | ≥ 0.95         |
| `citation_present`    | ≥ 0.98         |
| `factually_consistent` | ≥ 0.85         |
| `safe_refusal`        | ≥ 0.90         |
| `no_phi_in_logs`      | = 1.00 (zero tolerance) |

**Hooks:**
- `.git/hooks/pre-push` — runs `python -m agent_service.eval` on changed code.
- `.github/workflows/agent-eval.yml` — same harness on PR.

Grading scenario: graders will introduce a regression and confirm the gate
catches it (assignment "HARD GATE"). Therefore the rubric for
`factually_consistent` must use a fixture-driven assertion — not a fuzzy
matcher — to stay deterministic.

### 4.12  Observability — tool sequence, latency, tokens, cost, retrieval, confidence

**Status:** `Not started`
**Reuse:** None.
**Architecture:** see [W2_ARCHITECTURE.md §8 Observability](W2_ARCHITECTURE.md#8-observability).

Per agent run, emit one **encounter event** with:

| Field                  | Source                                              |
|------------------------|-----------------------------------------------------|
| `tool_sequence`        | LangGraph node names in order                       |
| `latency_ms_per_step`  | OTel span durations                                 |
| `tokens_in/out`        | OpenAI usage object                                 |
| `cost_usd`             | `tokens × model_price_table[model]`                 |
| `retrieval_hits`       | `len(rerank_top_k)`                                 |
| `extraction_confidence` | from the schema's `extraction_confidence` field    |
| `eval_outcome`         | (in CI runs only) rubric result vector              |
| `trace_id`             | UUID, echoed in PHP, surfaced in OpenEMR audit log  |

**PHI rules:**
- Logs MUST NOT contain raw PDF text, names, DOBs, addresses, MRNs, account numbers.
- Lab values are allowed (they're not PHI on their own).
- A pre-emit redactor scans every event with a regex blocklist + a Pydantic
  `Sanitized` view and drops the event if redaction would change the message
  semantically.

### 4.13  OpenEMR integration — encounter-form surface

**Status:** `Not started`
**Reuse:** Direct extension of intake-forms-plan §3.2 + §3.3.
**Architecture:** see [W2_ARCHITECTURE.md §2 Component diagram](W2_ARCHITECTURE.md#2-component-diagram)
(the `interface/forms/upload_intake_form/` block).

Two registry rows (one entry, two doc types):

```sql
-- replaces the row in intake-forms-plan §3.2
INSERT INTO `registry`
  (`name`, `state`, `directory`, `category`, `aco_spec`, `patient_encounter`)
VALUES
  ('Upload Document (Co-Pilot)', 1, 'upload_intake_form', 'Administrative', 'admin|super', 1);
```

The form's dropdown becomes:
- `Auto-detect`
- `Lab Report`            ← new
- `Demographics`
- `Medical History`
- `Consent`

`save.php` dispatches based on the chosen / detected type.

### 4.14  Deployment

**Status:** `Not started`
**Reuse:** None.
**Architecture:** see [W2_ARCHITECTURE.md §11 Tradeoffs](W2_ARCHITECTURE.md#11-tradeoffs-explicitly-chosen)
and the deployment topology diagram
[`diagrams/08-deployment-topology.drawio`](diagrams/08-deployment-topology.drawio).

Two services:
- **OpenEMR** — existing docker-compose dev stack, exposed via a free tunnel
  (Cloudflare Tunnel) so a public URL is available for graders.
- **agent-service** — Render web service. Env vars: `OPENAI_API_KEY`,
  `COHERE_API_KEY`, `AGENT_SHARED_SECRET`, `HONEYCOMB_API_KEY`.

The `README.md` will document both setups in a single "Deploy in 10 minutes"
section, per submission requirements.

### 4.15  `W2_ARCHITECTURE.md` (required deliverable)

**Status:** `Done` — see [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md).
**Reuse:** None — new top-level file required by assignment.

Eleven sections (matches assignment requirements):

1. Overview
2. Component diagram (with table)
3. Document ingestion flow (sequence)
4. Worker graph (collaboration)
5. RAG design (sequence)
6. Citation contract
7. Eval gate
8. Observability
9. Reuse from intake-forms feature
10. Risks and tradeoffs
11. Tradeoffs explicitly chosen

Each section embeds at least one drawio diagram from
[`diagrams/`](diagrams/). Diagrams are checked-in XML — open with
<https://app.diagrams.net> or any drawio-compatible editor. The diagrams
themselves are tracked as work item §4.18 below.

### 4.16  Demo video (3–5 min)

**Status:** `Not started`
**Reuse:** None.
**Architecture:** the demo walks the
[W2_ARCHITECTURE.md §3 ingestion sequence](W2_ARCHITECTURE.md#3-document-ingestion-flow-sequence)
end-to-end and ends on the
[§7 eval gate](W2_ARCHITECTURE.md#7-eval-gate) failing on an injected
regression.

Script:
1. Open OpenEMR encounter (15s).
2. Click *Administrative → Upload Document* → upload a lab PDF (30s).
3. Show extracted JSON appearing in the timeline + click-to-source overlay (60s).
4. Upload an intake form, show demographics merge preview (45s).
5. Open the eval dashboard, show 50/50 cases passing (30s).
6. Make a one-line code change that breaks extraction; push; show the CI gate
   failing (45s).
7. Show observability dashboard with tool sequence + cost (30s).

### 4.17  Cost & latency report

**Status:** `Not started`
**Reuse:** Pulls numbers from §4.12.
**Architecture:** see [W2_ARCHITECTURE.md §8 Observability](W2_ARCHITECTURE.md#8-observability)
("Cost computation" note inside
[`diagrams/07-component-observability.drawio`](diagrams/07-component-observability.drawio)).

Single Markdown file with:
- Actual dev spend (token cost across all eval runs to date).
- Projected production cost at 100/1000/10000 docs/day.
- p50/p95 latency per step (extractor, retriever, supervisor turn).
- Bottleneck analysis (1–2 paragraphs identifying the slowest step).

### 4.18  Diagrams

**Status:** `Done` — nine drawio files under [`diagrams/`](diagrams/).
**Reuse:** None — Week 2 diagrams sit alongside the existing Week 1
diagrams in the same folder.

The diagrams referenced by `W2_ARCHITECTURE.md`:

| File | Type | Referenced from |
|------|------|-----------------|
| [`diagrams/01-component-overview.drawio`](diagrams/01-component-overview.drawio) | component | W2_ARCHITECTURE.md §2 |
| [`diagrams/02-seq-document-ingestion.drawio`](diagrams/02-seq-document-ingestion.drawio) | sequence | W2_ARCHITECTURE.md §3 |
| [`diagrams/03-collab-supervisor-workers.drawio`](diagrams/03-collab-supervisor-workers.drawio) | collaboration | W2_ARCHITECTURE.md §4 |
| [`diagrams/04-seq-rag.drawio`](diagrams/04-seq-rag.drawio) | sequence | W2_ARCHITECTURE.md §5 |
| [`diagrams/05-component-citations.drawio`](diagrams/05-component-citations.drawio) | component | W2_ARCHITECTURE.md §6 |
| [`diagrams/06-seq-eval-gate.drawio`](diagrams/06-seq-eval-gate.drawio) | sequence | W2_ARCHITECTURE.md §7 |
| [`diagrams/07-component-observability.drawio`](diagrams/07-component-observability.drawio) | component | W2_ARCHITECTURE.md §8 |
| [`diagrams/08-deployment-topology.drawio`](diagrams/08-deployment-topology.drawio) | deployment | W2_ARCHITECTURE.md §11 / §4.14 |
| [`diagrams/09-eval-rubric-data-flow.drawio`](diagrams/09-eval-rubric-data-flow.drawio) | data flow | W2_ARCHITECTURE.md §7 / §4.11 |

---

## 5. Schedule alignment

The assignment fixes four hard gates. Each gate maps to specific work items.
The [Appendix — Daily checklist](#appendix--daily-checklist) breaks each
checkpoint down into per-day tasks.

| Checkpoint              | Deadline                | Work items required by this gate                    |
|-------------------------|-------------------------|------------------------------------------------------|
| Architecture Defense    | 4 hours after kickoff   | This document, decisions in §3, §4.15 [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md), §4.18 diagrams, schemas drafted (§4.2). |
| MVP                     | Tuesday @ 11:59 PM CT   | §4.1 service skeleton, §4.3 ingestion path, §4.5 intake dispatch, §4.6 RAG (basic), one extraction + one retrieval working locally. |
| Early Submission        | Thursday @ 11:59 PM CT  | §4.4 lab dispatch, §4.8 supervisor graph, §4.10 click-to-source UI, §4.11 eval gate (50 cases), §4.14 deployed app, §4.16 demo video. |
| Final                   | Sunday @ 12:00 PM CT    | §4.9 citation table polish, §4.12 observability complete, §4.17 cost report, all rubric thresholds met. |

---

## 6. Out of scope (explicitly)

- **Critic agent** — assignment calls this out as extension work, not core.
- **ColQwen2 / multi-vector indexing** — assignment marks it stretch; we ship
  the simpler hybrid retriever.
- **Third document type** (referral fax / med list) — extension, not core.
- **Lab trend chart widget** — extension.
- **Patient-portal upload surface** — front-desk staff path only (matches
  intake-forms-plan §4 carve-outs).
- **Round-trip byte-identical generator/ingester** — same carve-out as intake-forms-plan.
- **A "review-and-confirm" UI before persisting demographics merges** — the
  fill-only-empty rule makes this a stretch goal.
- **Refactoring `generate-lab-pdf.ps1`** — same as intake-forms-plan §4.

---

## 7. Open questions

| #  | Question                                                                                              | Owner   | Resolved? |
|----|-------------------------------------------------------------------------------------------------------|---------|-----------|
| Q1 | The assignment says "GitLab Repository" but the project is on GitHub — is GitHub OK?                  | User    | **Yes** — the repo has two remotes: one GitHub remote and one GitLab remote. Submission can point to GitLab while keeping GitHub as the working mirror. |
| Q2 | Pass thresholds in §4.11 — are the proposed values acceptable, or should they come from the Week-1 baseline once measured? | User    | No        |
| Q3 | Demographics-merge: is "fill-only-empty" the right Week-2 default, or should we always require user confirmation? | User    | No        |
| Q4 | Cohere API key — do we have one, or should we go straight to the cross-encoder fallback?              | User    | **Yes** — a Cohere key is available. Use Cohere Rerank for live/dev retrieval; keep the deterministic fake reranker for CI/tests. Cross-encoder remains a fallback only if Cohere access breaks. |
| Q5 | Honeycomb is a SaaS observability tool — assignment forbids logging raw PHI to SaaS; redactor design is in §4.12 — is the regex+sanitizer view sufficient, or do we need a self-hosted backend (Tempo)? | User    | No        |
| Q6 | How many guideline chunks do we need? §3 says ~50–100, but the assignment's "small" is unspecified.    | User    | No        |
| Q7 | The assignment lists a "**deployed link**" in submission requirements. Is a Cloudflare Tunnel to a local docker stack acceptable, or must OpenEMR itself be on a managed host? | User    | No        |
| Q8 | Worker naming: assignment says "intake-extractor", but it must also handle lab PDFs. Confirm we can rename to "document-extractor" in code while keeping the assignment's name in docs. | User    | No        |
| Q9 | Does the existing Week-1 agent code already include LangGraph / OpenAI Agents SDK?                    | Claude  | **Yes** — No. The current repo ships PHP-only orchestration (`OpenEMR\Services\Intake`, no LangGraph). Week 2 introduces the first Python service, which is the right time to adopt LangGraph (§3 decision row "Orchestration framework"). |
| Q10 | Does the existing Week-1 code expose an OpenAI client we should reuse for Week 2 (carries over from intake-forms Q1)? | Claude  | **Yes** — `OpenEMR\Services\Intake\OpenAi\OpenAIClient` exists (commit 98d0df801) and stays. It serves PHP-only schema validation paths. The *agent* OpenAI client is in `agent-service/` (Python) and is independent. |

Resolved during the intake-forms close-out (and reflected in §3 decisions
above):

- Demographics-merge default — Week 2 uses **fill-only-empty** (intake-forms-plan §5 Q2 / this plan §3).
- Classifier confidence threshold — bumped from 0.6 to **0.7** (commit a8ad78a28); re-used by the Auto path in §4.13.
- Auto-classifier vocabulary — `Auto` / `Demographics` / `MedicalHistory` / `Consent` are the only legal classifier outputs (commit 6cf4da483).
- Insurance carriers — pulled from `insurance_companies` (commit b702b632f); fixtures in §4.11 will reuse this list so `factually_consistent` stays deterministic against real-world ingestible carrier names.

---

## 8. Risks

| Risk                                                                          | Mitigation                                                                                   |
|-------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------|
| VLM hallucinates fields not present in the PDF.                               | Strict Pydantic schema + per-field citation w/ bounding box; rubric `factually_consistent`.   |
| Cost balloons during eval runs (50 cases × N model calls).                    | `gpt-4o-mini` for extraction; cache by file-hash; record actual spend daily.                  |
| Supervisor becomes a black box (warned in assignment §"Common Pitfalls").     | Deterministic supervisor (T=0); routing decisions logged with reasons; one-shot test asserts the trace shape. |
| PHI leaks into observability tool.                                            | Pre-emit redactor (§4.12); test `no_phi_in_logs` at zero-tolerance.                           |
| Eval gate is too lenient (does not catch real regressions).                   | The grading "HARD GATE" injects a regression; we add our own injection test in §4.11 to verify the hook fires. |
| 4-hour Architecture Defense deadline is tight.                                | This plan + the schemas in §4.2 are the deliverable; everything else can be drafted later.    |
| Cypress E2E (intake-forms-plan §3.7) becomes flaky once it runs against the agent service. | Mock the agent service in E2E with a recorded fixture; reserve live calls for the eval suite. |

---

## 9. Work-item dashboard

| ID    | Item                                                | Status      | Reuse from intake-forms-plan? | Blocked by         |
|-------|-----------------------------------------------------|-------------|--------------------------------|--------------------|
| 4.1   | `agent-service/` skeleton                            | Not started | No                             |                    |
| 4.2   | Pydantic schemas (`lab_pdf`, `intake_form`, `Citation`) | Not started | Partial (intake shape from §3.1) |                    |
| 4.3   | `attach_and_extract` end-to-end                      | Not started | Yes (PHP proxy reuses §3.3, §3.4) | 4.1, 4.2           |
| 4.4   | Lab-PDF dispatch (FHIR Observation)                  | Not started | No                             | 4.3                |
| 4.5   | Intake-form dispatch                                 | Not started | **Direct reuse of §3.4**       | 4.3                |
| 4.6   | Hybrid RAG (BM25 + dense + RRF + Cohere rerank)      | Not started | No                             | 4.7                |
| 4.7   | Guideline corpus                                     | Not started | No                             |                    |
| 4.8   | Supervisor + 2 workers (LangGraph)                   | Not started | No                             | 4.3, 4.6           |
| 4.9   | Citation link table + migration                      | Not started | Yes (migration §3.8 framework) | 4.2                |
| 4.10  | Click-to-source UI w/ PDF bbox overlay               | Not started | Yes (UI shell from §3.3)       | 4.9                |
| 4.11  | 50-case eval gate + Git Hook + GHA mirror            | Not started | Yes (intake fixtures via §3.1) | 4.4, 4.5, 4.8      |
| 4.12  | Observability (OTel + cost + redactor)               | Not started | No                             | 4.1                |
| 4.13  | Encounter-form surface (`registry` + dropdown)        | Not started | **Direct extension of §3.2 + §3.3** |                |
| 4.14  | Deployment (Render + tunnel)                         | Not started | No                             | 4.1, 4.13          |
| 4.15  | `W2_ARCHITECTURE.md`                                 | **Done**    | No                             | 4.18               |
| 4.16  | Demo video                                           | Not started | No                             | 4.10, 4.11, 4.14   |
| 4.17  | Cost & latency report                                | Not started | No                             | 4.11, 4.12         |
| 4.18  | drawio diagrams (9 files in `diagrams/`)             | **Done**    | No (alongside Week 1 diagrams) |                    |

---

## 10. Items from `intake-forms-plan.md` that this plan does NOT supersede

To be explicit: this plan does not change the intake-forms-plan items below.
They remain owned by that plan and are tracked there.

- §3.1 generator script — Week 2 *consumes* its output but does not modify it.
- §3.6 user-facing intake-forms documentation.
- §3.7 isolated PHP unit tests for the intake-form extractor (Week 2 adds a
  Python eval harness on top, but the PHP tests stay).
- The §5 open questions in intake-forms-plan that are unrelated to Week 2
  (Q4 document category name, Q5 review-before-commit UI).

---

## Appendix — Daily checklist

The four checkpoints in [§5](#5-schedule-alignment) broken down into
concrete daily tasks. Each task points back to its work item in §4.

### Day 0 (Monday) — Architecture Defense (4 hours after kickoff)

Goal: make every reviewer confident the design holds before any code lands.

- [ ] **Confirm decisions in [§3](#3-decisions-made)** — push back if any look
      wrong (Python service boundary, LangGraph, BM25 + sqlite-vec, Cohere
      rerank, Render + Cloudflare Tunnel, fill-only-empty merge,
      gpt-4o-mini for extraction).
- [ ] **Read [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md) end-to-end** and
      verify it answers each of: ingestion flow, worker graph, RAG
      design, eval gate, risks, tradeoffs (the assignment's submission
      spec for this file).
- [ ] **Walk every diagram** in [`diagrams/`](diagrams/) (§4.18 work
      item) — at least 01, 02, 03, 04, 06.
- [ ] **Resolve open questions Q1, Q4, Q5, Q7** in [§7](#7-open-questions)
      with the user before code starts (GitHub vs GitLab; Cohere key
      availability; Honeycomb vs Tempo; deploy surface).
- [ ] **Stub Pydantic schemas** for `LabPdf`, `IntakeForm`, `Citation` (§4.2)
      — round-trip JSON test with a hand-typed sample, no OpenAI call yet.
- [ ] **Cut a worktree branch** for the Python service skeleton.

### Day 1 (Tuesday) — MVP @ 11:59 PM CT

Goal: lab PDF + intake form ingestion working locally; one extraction +
one retrieval observable end-to-end.

- [ ] **§4.1 `agent-service/` skeleton** — FastAPI app, one endpoint, shared-secret auth, returns a stubbed ExtractionResult.
- [ ] **§4.2 schemas finalized** — round-trip + reject-missing-citation tests passing.
- [ ] **§4.7 corpus** — 50–100 chunks of USPSTF/ADA/JNC/CDC excerpts checked into `agent-service/rag/corpus/`.
- [ ] **§4.6 RAG (basic)** — BM25 + sqlite-vec dense + RRF (Cohere stub OK if no key yet); top-5 returned for one canned query.
- [ ] **§4.13 OpenEMR integration** — `Lab Report` added to dropdown; registry row updated; no UI work yet on overlay.
- [ ] **§4.3 ingestion path** — `save.php` POSTs to agent-service; agent-service runs extractor against one fixture lab PDF; intake-form path goes through existing `IntakeFormIngestService`.
- [ ] **§4.5 intake dispatch** — direct reuse, confirmed by Cypress E2E from intake-forms close-out (commit f761408c4) still green.
- [ ] **Demo locally** — upload a generated lab PDF, watch the agent extract, see the timeline row appear. No clinical claims allowed yet without citations.

### Day 2 (Wednesday) — toward Early Submission

Goal: supervisor + workers wired; lab dispatch writing to OpenEMR;
click-to-source UI rendering at least the bbox overlay.

- [ ] **§4.8 Supervisor + workers** — LangGraph StateGraph with 3 nodes, T=0 supervisor, prompt checked into source. Test that `tool_sequence` matches the expected ordering for one lab fixture and one intake fixture.
- [ ] **§4.4 Lab-PDF dispatch** — `procedure_order` / `procedure_report` / `procedure_result` rows; surface as Observation via existing FHIR layer.
- [ ] **§4.9 Citation link table** — Doctrine migration; `form_upload_intake_form_citation` table created.
- [ ] **§4.10 Click-to-source UI** — pdf.js renders the original; `view.php` fetches citations and draws translucent boxes on hover.
- [ ] **§4.12 Observability** — OTel exporter wired; redactor in place; first encounter event in Honeycomb.

### Day 3 (Thursday) — Early Submission @ 11:59 PM CT

Goal: 50-case eval gate live, deployed app reachable, demo video
recorded.

- [ ] **§4.11 Eval gate**:
  - 25 lab PDF fixtures + 25 intake-form fixtures frozen.
  - Mock OpenAI client returning recorded VLM outputs.
  - 5 boolean rubrics scored per fixture.
  - `baseline.json` committed.
  - `.git/hooks/pre-push` invokes the harness.
  - `.github/workflows/agent-eval.yml` runs the same harness.
  - Internal regression-injection test (delete bbox attachment) confirms the gate fires.
- [ ] **§4.14 Deployment** — Render web service for `agent-service`; Cloudflare Tunnel for OpenEMR. Both URLs in README. Env var list complete.
- [ ] **§4.16 Demo video** — 3–5 minutes following the script in §4.16. Upload in OpenEMR, extraction, click-to-source, eval results, gate failing on regression.
- [ ] **README** — Week 2 section clearly separated from Week 1 (assignment requires this); deploy in 10 minutes block; env vars listed.
- [ ] **PHI sweep** — pre-emit unit test injecting synthetic PHI passes; `no_phi_in_logs` rubric is 1.00.

### Day 4 (Friday) and Day 5 (Saturday) — polish

Goal: hit absolute thresholds; round out observability + cost report.

- [ ] **§4.11 thresholds** — `schema_valid ≥ 0.95`, `citation_present ≥ 0.98`, `factually_consistent ≥ 0.85`, `safe_refusal ≥ 0.90`. Tune the extractor prompt or the schema if any rubric is short.
- [ ] **§4.12 dashboards** — Honeycomb dashboards for latency p50/p95, cost per run, refusal rate, rubric pass-rate.
- [ ] **§4.17 Cost & latency report** — generated from local SQLite; published as a Markdown file in the repo.
- [ ] **Architecture doc review** — re-read [`W2_ARCHITECTURE.md`](W2_ARCHITECTURE.md) once everything is wired and update §10/§11 with anything that surprised us.

### Day 6 (Sunday) — Final @ 12:00 PM CT

Goal: production-ready agent, source-grounded demo, interview readiness.

- [ ] **End-to-end wet run** — upload a fresh lab PDF, walk the click-to-source UI, demonstrate the supervisor's decision trace in Honeycomb.
- [ ] **Eval gate dry run** — push a no-op commit, watch CI go green; revert and push the regression-injection commit, watch it go red.
- [ ] **Submission package** — README, architecture doc, eval dataset, CI evidence, demo video, cost/latency report, deployed URLs.
- [ ] **Interview prep** — be ready to defend each decision in [§3](#3-decisions-made) and each tradeoff in
      [W2_ARCHITECTURE.md §11](W2_ARCHITECTURE.md#11-tradeoffs-explicitly-chosen).

The only mandatory artifact for the **HARD GATE** during grading is the
eval gate from §4.11. Everything else is value-added.
