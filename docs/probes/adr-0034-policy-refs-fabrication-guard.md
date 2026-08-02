# Probes — ADR-0034 Implementation: `policy_refs` Fabrication Guard on Empty Retrieval, 2026-08-01

See: docs/journal.md — "ADR-0034 Implementation — `policy_refs` Fabrication Guard on Empty Retrieval — 2026-08-01"

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, compliance-driver, rag]
---
Across 49 live `cache_miss` calls with `chunk_count: 0`, the model still
returned a non-empty `policy_refs` on {{c1::18}}/49 of them, at an
average stated confidence of {{c2::0.88}} — despite the prompt already
stating "No specific policy context retrieved."

Extra: sentinel-l7 · Arbiter-L8 spike 0001 finding that motivated ADR-0034
See: docs/journal.md — ADR-0034 Implementation 2026-08-01

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, compliance-driver, anti-pattern]
---
ADR-0034 rejected {{c1::prompt wording alone}} as the fix for
`policy_refs` fabrication, because the prompt already instructed the
model not to cite anything on empty retrieval and it did so anyway on
18/49 zero-chunk calls — the fix instead enforces the guard
{{c2::programmatically}}, in code, independent of what the prompt says.

Extra: sentinel-l7 · Anti-Pattern Avoided: Trusting Prompt Instructions as the Sole Safety Mechanism
See: docs/journal.md — ADR-0034 Implementation 2026-08-01

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, compliance-driver, architecture]
---
`guardPolicyRefsAgainstEmptyRetrieval()` lives once in
{{c1::`AbstractComplianceDriver`}} rather than in each driver, because
`OllamaDriver`, `GeminiDriver`, `OpenRouterDriver`, and `VertexAIDriver`
all extend it without overriding `analyze()`, `analyzeTransaction()`, or
`parseResponse()`.

Extra: sentinel-l7 · One shared fix covers all four compliance drivers
See: docs/journal.md — ADR-0034 Implementation 2026-08-01

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, compliance-driver, pattern]
---
The fabrication guard runs {{c1::between `parseResponse()` and
`logResponseQuality()`}} in `analyze()`/`analyzeTransaction()`, so that
ADR-0019's `has_policy_refs` quality signal automatically reads the
already-corrected `policy_refs` — no separate `chunk_count` check is
needed inside the scoring method itself.

Extra: sentinel-l7 · Pattern: Programmatic Guard Placed to Make a Downstream Fix Automatic
See: docs/journal.md — ADR-0034 Implementation 2026-08-01

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, testing]
---
The guard silently broke pre-existing tests in three driver test files
that mocked a {{c1::zero-chunk `searchNamespace` return}} paired with a
model response asserting non-empty `policy_refs` and
`Log::shouldReceive('warning')->{{c2::never()}}` — each test had been
unknowingly relying on the pre-guard fabrication behavior.

Extra: sentinel-l7 · Challenge: fragile test pattern found independently in OllamaDriverTest, GeminiDriverTest, OpenRouterDriverTest
See: docs/journal.md — ADR-0034 Implementation 2026-08-01

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0034, adr-0019, observability]
---
When the fabrication guard fires, it logs
`'{Driver}: policy_refs fabricated on empty retrieval'` with the
model's original value preserved under the
{{c1::`model_asserted_refs`}} key — so the correction is observable
rather than disappearing silently once fixed.

Extra: sentinel-l7 · Surfacing the correction, not just applying it
See: docs/journal.md — ADR-0034 Implementation 2026-08-01

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
See: docs/journal.md — ADR-0034 Post-Review Follow-Up 2026-08-01

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
See: docs/journal.md — ADR-0034 Post-Review Follow-Up 2026-08-01

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
See: docs/journal.md — ADR-0034 Post-Review Follow-Up 2026-08-01

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
See: docs/journal.md — ADR-0034 Post-Review Follow-Up 2026-08-01
