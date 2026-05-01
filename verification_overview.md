# Verification Overview

A look at *what kinds* of checks
[`AgentAnswerVerifier`](src/Services/Agent/Verification/AgentAnswerVerifier.php)
actually performs.

## Is the verifier mostly string matching?

Honest answer: it's mostly lexical checks — regex, set membership, map
lookups, token overlap. There's no semantic similarity, no embeddings, no
LLM-as-judge.

## Techniques used

| Technique | Where | What it actually does |
| --- | --- | --- |
| **Shape validation** | `is_array()`, `array_is_list()`, key/text presence | enforces the JSON contract: `answer_blocks[]`, `claims[]`, `missing_or_uncertain[]`, required `heading` / `text` |
| **Numeric thresholds** | `strlen($totalText) > 4000`, `count($citationIds)` | length cap and citation-count gating |
| **Set membership against a fixed list** | `in_array($certainty, UNCITED_CERTAINTIES)`, `in_array($sourceType, ['medication','allergy','problem','result','event'])` | the only literal "match against a fixed string list" — and it's small (3 and 5 entries) |
| **Regex pattern matching** | `containsOutOfScopeAdvice`, `soundsLikeMissingness`, `usesSafeMissingness`, `isCompletenessStatement`, active-status `\bactive\b` | the heuristic clinical-safety layer — not literal strings, regex with word boundaries |
| **Map lookup against the evidence packet** | `isset($sourceMap[$citationId])` | "did this `citation_id` actually come back from a tool?" |
| **ID equality** | `(int) $source['patient_id'] !== $accessToken->getPatientContext()->getPid()` | patient binding — type-coerced integer compare |
| **Substring search on JSON-encoded answer** | `str_contains($answerText, 'unavailable' \| 'not checked' \| 'tool')` | tool-failure disclosure check; runs against the whole serialized answer, not a specific field |
| **Tokenization + set intersection** | `claimTextSupportedBySources` → `significantTokens` (regex `/[a-z0-9][a-z0-9-]{3,}/i`, stop-word filter, `array_intersect`) | the only "is the claim supported by the source" check; lexical token overlap, not semantic |

## Two things worth calling out

1. **`claimTextSupportedBySources` is the weakest link.** It only proves the
   claim shares at least one significant token with one cited source.
   "Active metoprolol 50mg" overlaps with a metoprolol source row, but a
   wrong dose would still pass — the verifier trusts the LLM not to
   fabricate values inside the matched token's neighborhood. The strong
   protection here is *patient binding + active-status + small evidence
   packet*, not the token check itself.

2. **The clinical-safety regex is a denylist.** It blocks
   `should | recommend | start | stop | prescribe | diagnose | order | …`.
   Novel phrasings the regex doesn't match would slip through. It's a
   coarse filter, not a semantic guard.

## Trust model

The verifier's job is mainly:

- **Structural integrity** — right shape, valid citations, right patient,
  matched status, no hidden tool failures.
- **Coarse linguistic guards** — advice denylist, missingness phrasing,
  completeness-statement rejection.

The MVP assumes a small evidence packet retrieved under a BAA-covered model.
The verifier is the cheap last line, not the only line of defense — the
access broker, per-intent caps, anonymizer, and the closed-intent UI all
sit in front of it.

## Improvements

Ordered by impact. The biggest weakness is that "is this claim supported by
the source" reduces to lexical token overlap; most other gaps follow from
that or from rules being a wall of imperative `if`s.

1. **Replace token-overlap with structured-value verification.** The packet
   already carries structured source fields (dose, status, date, count).
   When a claim mentions a value, extract numerals / units / dates with
   regex and require they match the cited source's fields, not just share
   a word. "Active metoprolol 50mg" should be checked against
   `source.dose=50`, `source.unit=mg`, `source.status=active` — not just
   a shared token.

2. **Validate the envelope with JSON Schema, not hand-rolled `is_array` +
   `array_is_list`.** The schema already exists in
   `AgentAnswerSchema::jsonSchema()`. Run the answer through a validator
   (`opis/json-schema`) before content checks; the verifier then assumes
   shape.

3. **Refactor into rule objects with stable error codes.** Each rule
   implements `evaluate(Answer, Packet, Token): RuleResult`; results carry
   codes like `CLAIM_UNSUPPORTED_BY_SOURCE`, `OUT_OF_SCOPE_ADVICE`. Easier
   to test in isolation, easier to dashboard, easier to add rules without
   touching the 70-line body of `verifyClaim`.

4. **Citation coverage check.** Today the verifier ensures every cited
   source exists; it doesn't ensure the answer cites everything relevant
   in the packet. If five active medications came back and the answer
   cites three, that silent omission is more dangerous than a citation
   error. Surface it as a warning at minimum.

5. **Tool-failure disclosure as a structured field.** Replace the JSON
   substring search for `unavailable | not checked | tool` with a required
   `answer.tool_failures: [{tool, status}]` field whenever
   `tool_runs[].error_class` is set. Structural check, not text matching.

6. **Intent-aware constraints.** A "current medications" intent should cite
   at least one source of `source_type=medication` unless the answer
   declares `not_found`. Each intent declares its expected source types in
   `AgentIntentCatalog`; the verifier reads them.

7. **PHI-leak guard on output.** The LLM sees raw evidence (BAA), but the
   verifier could check that the answer doesn't echo identifiers
   (SSN / phone / email / address) that aren't required to answer the
   intent. Cheap regex pass on `claim.text`.

8. **Replace the advice denylist with a tagged-claim contract.** Have the
   schema force each claim to declare
   `clinical_action: none | factual_recall`, and reject anything else.
   Pushes the obligation onto the LLM via the schema rather than a fragile
   keyword list.

9. **Eval harness (Phase 6 P6.5).** Golden cases per rule —
   `unsupported_dose.json`, `wrong_patient.json`,
   `hidden_tool_failure.json` — run on every PR. Without it, rule edits
   regress silently.

10. **Optional LLM-as-judge tie-breaker.** Only on first-pass borderline
    cases (e.g. token overlap below threshold but no other rule fires).
    Bounded latency / cost, off by default.
