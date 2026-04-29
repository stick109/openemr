# Users

## Scope

This document defines the first user for the Clinical Co-Pilot: a doctor preparing to see a scheduled outpatient.

The scope is intentionally narrow. The first version of the agent should support an outpatient primary-care style visit where a patient is already scheduled or already open in the chart. The agent is not a generic medical chatbot, chart search engine, diagnosis tool, order entry assistant, or documentation writer.

The user definition below is based on `Week-1-Assignment.pdf`, `CURRENT-ARCHITECTURE.md`, and `AUDIT.md`. The audit constraints are treated as product requirements: the agent must be read-only for MVP, must operate only on a server-validated current patient context, and must cite source records. If patient identity is ambiguous, the agent refuses to act. If evidence is ambiguous, the agent clearly marks it as ambiguous in the UI. The agent does not ask for clarification because there is no time; the doctor has only 90 seconds to get familiar with the patient.

## MVP Assumptions

- The agent is embedded in an authenticated OpenEMR workflow, either the patient chart or the main scheduled-visit workflow.
- The patient is selected by OpenEMR server-side state, not by free-text model choice.
- The agent can read only bounded evidence for the current patient: appointment context, demographics flags, recent encounters, active problems, medications, allergies, recent vitals, recent labs/procedures, and selected parsed documents.
- Every factual claim must cite a source record or be labeled as missing, unknown, conflicting, or not checked.
- The MVP does not write to the chart automatically. A user may copy or act on the output, but the agent itself does not sign notes, change medications, create orders, or modify diagnoses.
- The agent should return useful output in seconds. Long document parsing, OCR, embeddings, and broad chart summarization are outside the synchronous visit workflow.

## User: Doctor

### Profile

The doctor is seeing a full schedule of patients and has about 90 seconds between rooms. They need to remember who the patient is, why they are here, what changed since the last visit, and what matters today.

This user needs fast synthesis, not generic medical advice. They are responsible for clinical decisions and need the agent to be conservative, source-grounded, and explicit about uncertainty.

### Workflow

1. The doctor opens the next scheduled patient.
2. The doctor asks for a brief visit summary before entering the room.
3. The agent returns a short, source-cited briefing: visit reason, last relevant encounter, major changes, active problems, medications, allergies, recent labs/procedures, and recent documents.
4. The doctor asks follow-up questions about the current patient's chart, such as "what changed since the last visit?" or "why is this item flagged?"
5. The doctor makes clinical decisions in OpenEMR using normal charting, ordering, and documentation workflows.

### Needs

- A 90-second pre-visit briefing.
- Direct answers with source attribution.
- Clear flags for changed, missing, conflicting, or stale information.
- Fast drilldown into the evidence behind a claim.
- Refusals when the agent cannot support an answer from the chart.

### Use Cases

#### D1. Generate a 90-second pre-visit briefing

The doctor asks: "Brief me before I go in."

The agent should summarize the current visit context: who the patient is, why they are here, what happened at the last relevant encounter, active medications and allergies, major active problems, and recent labs/procedures/documents.

Why an agent is the right solution: the doctor needs synthesis across schedule, chart, medication, allergy, encounter, document, and result data under severe time pressure. A static dashboard can show panels, but it cannot answer follow-up questions or explain why a specific item was included. A conversational agent can provide the initial summary and then narrow immediately.

#### D2. Explain what changed since the last visit

The doctor asks: "What changed since I last saw this patient?"

The agent should compare the most recent relevant completed encounter against newer evidence: updated meds, new or resolved problems, new allergies, new labs/procedures, and recent documents. The answer should label each item as recent, active, historical, undated, or conflicting where appropriate.

Why an agent is the right solution: "changed since last visit" spans tables and data sources with different date semantics. A simple sorted list can miss the clinical question or overstate certainty. The agent can apply a source-specific change rule and explain uncertainty in natural language.

#### D4. Drill down into the evidence behind a claim

The doctor asks: "Why are you saying this is active?" or "Show me the source."

The agent should return the exact source record type, date, status, and short escaped excerpt or display field supporting the claim. If no source supports the claim, the agent should retract or restate the answer as uncertain.

Why an agent is the right solution: trust is the core product requirement. The doctor needs rapid source inspection without opening every chart section. A conversational evidence drilldown keeps the interaction fast while enforcing the verification requirement.

### Doctor Boundaries

- The agent must not make unsupported clinical claims.
- The agent must not provide generic medical advice outside the current patient's evidence.
- The agent must not hide tool failures or missing records.
- The agent must not persist generated summaries as medical-record artifacts unless a later architecture explicitly supports provenance, retention, and review.

## Use Case Traceability

| ID | User | Workflow Moment | Primary Evidence Needed | Required Agent Behavior |
| --- | --- | --- | --- | --- |
| D1 | Doctor | 90 seconds before entering room | Current appointment, patient demographics, last encounter, active clinical lists, recent results/documents | Produce a concise visit briefing with citations |
| D2 | Doctor | Pre-visit or in-room follow-up | Last completed encounter plus newer meds, problems, allergies, labs, and documents | Explain changes by source and date, including uncertainty |
| D4 | Doctor | Trust and verification drilldown | Source records behind a specific claim | Show record-level provenance or retract unsupported claims |

## Out Of Scope For MVP

- Emergency triage.
- Inpatient rounding.
- Specialty-specific clinical protocols.
- Autonomous chart writes.
- Order entry, prescribing, diagnosis coding, billing coding, or treatment recommendations.
- Cross-patient search.
- Full-chart export or unrestricted document summarization.
- Patient-facing portal use.
