# Users

## Scope

This document defines the first two users for the Clinical Co-Pilot:

1. A nurse performing the initial take-in for a scheduled patient.
2. A doctor preparing to see that same patient.

The scope is intentionally narrow. The first version of the agent should support an outpatient primary-care style visit where a patient is already scheduled or already open in the chart. The agent is not a generic medical chatbot, chart search engine, diagnosis tool, order entry assistant, or documentation writer.

The user definitions below are based on `Week-1-Assignment.pdf`, `CURRENT-ARCHITECTURE.md`, and `AUDIT.md`. The audit constraints are treated as product requirements: the agent must be read-only for MVP, must operate only on a server-validated current patient context, must cite source records, and must refuse or ask for clarification when patient identity or evidence is ambiguous.

## Shared MVP Assumptions

- The agent is embedded in an authenticated OpenEMR workflow, either the patient chart or the main scheduled-visit workflow.
- The patient is selected by OpenEMR server-side state, not by free-text model choice.
- The agent can read only bounded evidence for the current patient: appointment context, demographics flags, recent encounters, active problems, medications, allergies, recent vitals, recent labs/procedures, selected parsed documents, and intake notes once available.
- Every factual claim must cite a source record or be labeled as missing, unknown, conflicting, or not checked.
- The MVP does not write to the chart automatically. A user may copy or act on the output, but the agent itself does not sign notes, change medications, create orders, or modify diagnoses.
- The agent should return useful output in seconds. Long document parsing, OCR, embeddings, and broad chart summarization are outside the synchronous visit workflow.

## User 1: Intake Nurse

### Profile

The intake nurse rooms scheduled patients before the doctor enters. Their job is to confirm who the patient is, why they are here, whether key chart data is still accurate, and whether anything urgent or clinically relevant should be surfaced before the doctor starts the visit.

They work under time pressure, but their goal is not to make diagnostic decisions. Their goal is to collect and confirm the right information quickly, avoid missing safety-critical discrepancies, and hand the doctor a cleaner starting point.

### Workflow

1. The nurse opens the scheduled patient in OpenEMR.
2. The nurse starts rooming: identity confirmation, chief complaint or visit reason, vitals, allergies, medication reconciliation, and relevant history updates.
3. The nurse asks the agent for a short intake checklist for this patient and visit.
4. The agent returns specific questions or confirmations, each tied to source evidence or explicitly marked as missing.
5. The nurse asks follow-up questions only about the current patient, such as "which meds should I confirm?" or "what changed since the last visit that I should ask about?"
6. The nurse records confirmed intake information through the normal OpenEMR workflow, not through automatic agent writes.

### Needs

- A short, patient-specific intake checklist.
- Clear separation between confirmed chart facts, stale data, conflicting data, and missing data.
- Fast answers that do not require scanning many chart pages.
- Plain language questions they can ask the patient.
- Source references so they can trust why an item appeared.

### Use Cases

#### N1. Build a rooming checklist for the current visit

The nurse asks: "What should I confirm during intake for this patient?"

The agent should return a compact checklist based on the current appointment, last encounter, active problems, allergies, medication list, recent vitals, and recent relevant documents or results. The checklist should focus on questions the nurse can actually resolve during rooming.

Why an agent is the right solution: this is not just a static dashboard problem. The right checklist depends on the visit reason, recent chart changes, stale or missing fields, and follow-up questions the nurse may ask while talking to the patient. A conversational agent can adapt the checklist without making the nurse manually assemble context from several OpenEMR screens.

#### N2. Identify medication and allergy items to confirm

The nurse asks: "Which medications or allergies need confirmation today?"

The agent should identify active medications, listed allergies, stale or undated records, possible duplicates, and conflicting entries. It should not infer that a medication is active unless the source supports that status.

Why an agent is the right solution: medication and allergy data can appear in multiple representations and may be stale, duplicated, or imported. The nurse needs a targeted conversation script, not a raw table dump. The agent can turn record-level evidence into a short list of confirmations while preserving uncertainty.

#### N3. Surface missing or stale intake data

The nurse asks: "What important intake information looks missing or stale?"

The agent should flag items such as old vitals, missing visit reason, unverified allergies, missing preferred pharmacy, incomplete contact data, or documents/results that appear relevant but have not been reviewed for the visit.

Why an agent is the right solution: missingness is contextual. Some gaps matter for today's visit and some do not. A conversational agent can explain why an item matters now and let the nurse narrow the answer, for example by asking for only items that can be resolved before the doctor enters.

### Nurse Boundaries

- The agent must not diagnose, recommend treatment, or instruct the nurse to change medications.
- The agent must not expose another patient's information through free-text search.
- The agent must not treat missing data as negative evidence.
- The agent must not automatically update the chart from the conversation.

## User 2: Doctor

### Profile

The doctor is seeing a full schedule of patients and has about 90 seconds between rooms. They need to remember who the patient is, why they are here, what changed since the last visit, and what matters today.

This user needs fast synthesis, not generic medical advice. They are responsible for clinical decisions and need the agent to be conservative, source-grounded, and explicit about uncertainty.

### Workflow

1. The doctor opens the next scheduled patient or the patient already roomed by the nurse.
2. The doctor asks for a brief visit summary before entering the room.
3. The agent returns a short, source-cited briefing: visit reason, last relevant encounter, major changes, active problems, medications, allergies, recent labs/procedures, recent documents, and nurse intake flags.
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

The agent should summarize the current visit context: who the patient is, why they are here, what happened at the last relevant encounter, active medications and allergies, major active problems, recent labs/procedures/documents, and any nurse intake flags.

Why an agent is the right solution: the doctor needs synthesis across schedule, chart, medication, allergy, encounter, document, and result data under severe time pressure. A static dashboard can show panels, but it cannot answer follow-up questions or explain why a specific item was included. A conversational agent can provide the initial summary and then narrow immediately.

#### D2. Explain what changed since the last visit

The doctor asks: "What changed since I last saw this patient?"

The agent should compare the most recent relevant completed encounter against newer evidence: updated meds, new or resolved problems, new allergies, new labs/procedures, recent documents, and nurse intake updates. The answer should label each item as recent, active, historical, undated, or conflicting where appropriate.

Why an agent is the right solution: "changed since last visit" spans tables and data sources with different date semantics. A simple sorted list can miss the clinical question or overstate certainty. The agent can apply a source-specific change rule and explain uncertainty in natural language.

#### D3. Follow up on nurse intake flags

The doctor asks: "What should I know from intake before I start?"

The agent should summarize the nurse-confirmed concerns, unresolved intake questions, medication or allergy discrepancies, abnormal or changed vitals, and any patient-stated reason for visit. The answer should distinguish nurse-confirmed information from older chart data.

Why an agent is the right solution: this is a handoff problem, not only a data display problem. The doctor may need to ask follow-up questions such as "what does that conflict with?" or "show me the source for the medication discrepancy." A conversational agent can bridge intake context and chart evidence while keeping the doctor in the current patient workflow.

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
| N1 | Nurse | Start of rooming | Appointment, last encounter, active problems, allergies, medications, vitals | Produce a short source-cited intake checklist |
| N2 | Nurse | Medication and allergy reconciliation | `lists`, `lists_medication`, prescriptions, allergies, verification/status fields | Flag stale, duplicate, conflicting, and unverified items without overclaiming |
| N3 | Nurse | Intake cleanup | Demographics, vitals, pharmacy/contact fields, visit reason, recent documents/results | Identify missing or stale data relevant to today's visit |
| D1 | Doctor | 90 seconds before entering room | Current appointment, patient demographics, last encounter, active clinical lists, recent results/documents | Produce a concise visit briefing with citations |
| D2 | Doctor | Pre-visit or in-room follow-up | Last completed encounter plus newer meds, problems, allergies, labs, documents, intake updates | Explain changes by source and date, including uncertainty |
| D3 | Doctor | After nurse rooming | Nurse intake notes, vitals, med/allergy discrepancies, patient-stated concerns | Summarize handoff items and connect them to chart evidence |
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
