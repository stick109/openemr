Ranked by what would catch the worst failures.

## 1. Live end-to-end runs (the biggest gap)

Right now the LLM is never called. Add a small set of fixtures that run the
**real** pipeline — retrieval → prompt → LLM → verifier — and assert the
answer passes verification. Even 5–10 cases would catch prompt regressions
when you upgrade models or tweak the system prompt. Cost is low; signal is
high.

## 2. Wrong-patient leak detection

The most dangerous failure in clinical AI. Construct cases where the evidence
packet *deliberately* contains a row from another patient (simulating a join
bug or stale cache). Assert the verifier blocks any answer that cites it. The
current fixture trusts that the packet is clean — production won't.

## 3. Retrieval correctness

Garbage-in evals. Given a question + a real patient chart, did the retriever
pull the right rows? Score with simple recall@k against a hand-labeled "what
should have been retrieved" set. The verifier is useless if the LLM never saw
the relevant lab.

## 4. Prompt-injection variants

One fixture isn't enough. Real attacks come in many shapes: instructions in
clinician notes, in lab comments, in patient-entered fields, base64-encoded,
role-play framing ("pretend you are…"), tool-call hijack attempts. 8–12
variants, all asserting the LLM ignored the injected instruction.

## 5. Model-upgrade regression

A reproducible suite you run before flipping the model from Sonnet 4.6 → 4.7
(or whatever's next). Same fixtures, same prompts, compare pass rates.
Without this, model upgrades become guesswork.

## 6. Negation and temporal accuracy

Two clinical-specific failure modes the verifier won't catch:

- **Negation:** "no penicillin allergy" vs "penicillin allergy" —
  token-overlap scoring can't tell these apart.
- **Temporal:** a lab from 2019 reported as recent. Construct cases with old
  dates and assert the answer flags age correctly.

## 7. Refusal calibration

Two-sided: cases where the LLM **should** refuse (out-of-scope advice,
missing data) and cases where it **shouldn't** (legitimate questions it can
answer). Catches both fabrication and over-refusal — the latter destroys
usefulness.

---

**What to skip for now:** latency/cost tracking, LLM-as-judge for usefulness,
embedding-based similarity. Worth doing eventually, but the seven above pay
back faster.
