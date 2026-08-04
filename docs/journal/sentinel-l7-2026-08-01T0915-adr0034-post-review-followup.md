---
id: sentinel-l7-2026-08-01T0915-adr0034-post-review-followup
repo: sentinel-l7
title: "ADR-0034 Post-Review Follow-Up"
date: 2026-08-01
phase: adr-0034-followup
tags: [policy-refs, fabrication-guard, code-review, test-coverage-gap]
files: [app/Services/Compliance/AbstractComplianceDriver.php, tests/Unit/OllamaDriverTest.php, tests/Unit/VertexAIDriverTest.php, docs/journal.md, CLAUDE.md]
---

# ADR-0034 Post-Review Follow-Up

A code review of the ADR-0034 diff above surfaced two test-coverage gaps
and one scope gap in what the guard actually delivers versus what this
entry claimed. Addressed same-day.

### Challenge: Guard Coverage Existed for `analyze()` But Not Its Sibling Call Site
The 3 new fabrication-guard tests added above all called `analyze()`;
`analyzeTransaction()` calls the identical
`guardPolicyRefsAgainstEmptyRetrieval()` at a second, separate call site
but had zero dedicated coverage — a regression isolated to that one
call site (wrong array passed, or the guard call dropped from that
method only) would have passed the full suite. Root cause: the original
implementation pass tested the mechanism once and treated the second
call site as "obviously the same," which a code review caught but a
test suite does not enforce on its own. Fixed by adding
`it('strips policy_refs on zero-chunk retrieval via analyzeTransaction()
too', ...)` to `OllamaDriverTest`. Separately, `VertexAIDriverTest` had
never been extended for ADR-0034 at all — despite `VertexAIDriver`
going through the identical shared guard, no test in that file made any
assertion on `policy_refs` — so two guard tests (fabrication-strips,
chunks-present-passthrough) were added there too, mirroring the Ollama
pattern.

### Decision: Deduplicate the Repeated `source_id`/`domain` Log Context, But Only Where It's Actually Identical
`AbstractComplianceDriver` had the `source_id`/`domain` context-array
pair written out three times (the guard, `logResponseQuality()`, and
`fetchPolicyContext()`'s under-indexed warning). Extracted
`auditContext(array $data): array` and used it in the first two — but
deliberately *not* the third, because its `domain` value comes from a
local variable already normalized to `string|null` a few lines above
(`$domain = isset($data['domain']) && $data['domain'] !== null ? (string)
$data['domain'] : null;`), not the raw `$data['domain'] ?? null` the
other two use. Folding all three into one helper would have silently
changed the logged type for the under-indexed warning — the safer
tradeoff was two-of-three deduplication over a "complete" refactor that
risked altering existing, tested log output.

### Decision: Defer Narrative Sanitization and Ref-to-Chunk Correspondence Rather Than Bolt On an Untested Heuristic
Two findings from the review describe real gaps in what ADR-0034
delivers, not bugs in what it implements:

1. The guard only strips the structured `policy_refs` field. It never
   touches `result['narrative']` — free text that reaches
   `compliance_events.audit_narrative` (`AxiomProcessorService`) and the
   cached transaction analysis (`TransactionProcessorService`)
   completely unmodified. A model that embeds a fabricated citation in
   narrative prose instead of the `policy_refs` array is not caught by
   ADR-0034 as shipped, which undercuts this entry's earlier "closes
   that gap at the source" framing.
2. The guard only checks `chunk_count === 0`. It never verifies that a
   non-empty `policy_refs` entry corresponds to a chunk that was
   actually retrieved when `chunk_count > 0`.

Both were left unfixed rather than patched blind: sanitizing free text
without breaking the narrative is a materially different problem than
clearing an array field, and ref-to-chunk correspondence needs the
retrieved chunk metadata to carry a stable citable identifier the model
is prompted to echo verbatim — a prompt/schema change, not a pure
guard-logic change. Silently shipping a heuristic for either risked a
worse failure mode (a sanitizer that mangles legitimate narrative text,
or a correspondence check that false-positives on a legitimately-cited
chunk) than leaving the gap documented. Logged as TODO items in
`CLAUDE.md` instead.

Also corrected this entry's own "4 existing tests" count (above) to 6,
which is what the diff and this entry's own enumerated list in the
Challenges section already showed — an internal inconsistency the
review caught in the entry itself, not just the code.

Verified: 67/67 driver tests passing (up from 64), full suite 399/399,
0 regressions. Pint clean.
