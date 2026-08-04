---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, testing, code-review]
---
`analyze()` and `analyzeTransaction()` both call
`guardPolicyRefsAgainstEmptyRetrieval()` at their own separate call
site, but only {{c1::`analyze()`}} had a dedicated fabrication-guard
test until a post-review pass added one for the other — a code review
catches this kind of per-call-site gap that a passing test suite does
not surface on its own.

Extra: sentinel-l7 · Challenge: Guard Coverage Existed for analyze() But Not Its Sibling Call Site
See: docs/journal/sentinel-l7-2026-08-01T0915-adr0034-post-review-followup.md

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, testing]
---
`VertexAIDriverTest` had zero assertions on `policy_refs` anywhere in
the file even though `VertexAIDriver` goes through the same shared
{{c1::`guardPolicyRefsAgainstEmptyRetrieval()`}} as the other three
drivers — sharing a base class doesn't guarantee shared *test*
coverage, each driver's test file still needs its own exercise of
inherited behavior.

Extra: sentinel-l7 · Challenge: Guard Coverage Existed for analyze() But Not Its Sibling Call Site
See: docs/journal/sentinel-l7-2026-08-01T0915-adr0034-post-review-followup.md

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, refactoring]
---
Q: Why was the `source_id`/`domain` log-context pair deduplicated into
`auditContext()` in only two of the three places it appeared in
`AbstractComplianceDriver`, not all three?

A: The third occurrence — `fetchPolicyContext()`'s under-indexed
warning — uses a `domain` value already normalized to `string|null` by
a local variable a few lines above it, not the raw `$data['domain'] ??
null` the other two use. Folding it into the shared helper would have
silently changed the logged type for that one warning. Deduplicating
only the two truly-identical call sites avoided altering existing,
tested log output for the sake of a "complete" refactor.

Extra: sentinel-l7 · Decision: Deduplicate Only Where Actually Identical
See: docs/journal/sentinel-l7-2026-08-01T0915-adr0034-post-review-followup.md

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, scope, decision]
---
Q: A code review found the ADR-0034 guard doesn't sanitize `narrative`
(only `policy_refs`) and doesn't check ref-to-chunk correspondence when
`chunk_count > 0`. Why weren't both auto-fixed in the same pass?

A: Both need a design decision the fix pass isn't positioned to make
unilaterally: narrative sanitization means detecting and redacting an
inline citation from free text without mangling legitimate prose — a
different, harder problem than clearing an array field — and
ref-to-chunk correspondence needs the retrieved chunk metadata to carry
a stable citable identifier the model is prompted to echo verbatim, a
prompt/schema change, not a guard-logic change. Shipping an untested
heuristic for either risked a worse failure mode than documenting the
gap as a TODO and leaving it for a deliberate follow-up.

Extra: sentinel-l7 · Decision: Defer Narrative Sanitization and Ref-to-Chunk Correspondence
See: docs/journal/sentinel-l7-2026-08-01T0915-adr0034-post-review-followup.md
