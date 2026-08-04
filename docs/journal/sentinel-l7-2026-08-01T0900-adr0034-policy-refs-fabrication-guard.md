---
id: sentinel-l7-2026-08-01T0900-adr0034-policy-refs-fabrication-guard
repo: sentinel-l7
title: "ADR-0034 Implementation — policy_refs Fabrication Guard on Empty Retrieval"
date: 2026-08-01
phase: adr-0034
tags: [policy-refs, fabrication-guard, rag, adr-0034, compliance-driver]
files: [app/Services/Compliance/AbstractComplianceDriver.php, docs/adr/0019-output-quality-scoring.md, tests/Unit/OllamaDriverTest.php, tests/Unit/GeminiDriverTest.php, tests/Unit/OpenRouterDriverTest.php, README.md]
---

# ADR-0034 Implementation — policy_refs Fabrication Guard on Empty Retrieval

Implemented the accepted ADR-0034 decision: Arbiter-L8's spike found that
across 49 live `cache_miss` calls with `chunk_count: 0` (no policy chunk
cleared the retrieval threshold), the model still returned a non-empty
`policy_refs` on 18/49 of them, at an average stated confidence of 0.88 —
a fabricated regulatory citation landing in `compliance_events`, an audit
trail. Prompt wording alone ("No specific policy context retrieved.") was
already present in that run and didn't stop the fabrication, so ADR-0034
rejected prompting as the sole fix.

Since all four compliance drivers (`OllamaDriver`, `GeminiDriver`,
`OpenRouterDriver`, `VertexAIDriver`) extend `AbstractComplianceDriver` and
none override `analyze()`, `analyzeTransaction()`, or `parseResponse()`,
one method in the shared base class covers all of them:
`guardPolicyRefsAgainstEmptyRetrieval()` forces `policy_refs = []`
whenever the retrieval that produced the response had zero chunks,
regardless of what the model asserted, and logs the correction
(`'{Driver}: policy_refs fabricated on empty retrieval'`, with the
model's original `policy_refs` preserved as `model_asserted_refs`) so the
fabrication rate stays observable rather than silently disappearing.

### Pattern: Programmatic Guard Placed to Make a Downstream Fix Automatic
`guardPolicyRefsAgainstEmptyRetrieval()` runs between `parseResponse()`
and `logResponseQuality()` in both `analyze()` and `analyzeTransaction()`.
ADR-0034 also required amending ADR-0019's `has_policy_refs` quality
signal to require `chunk_count > 0`, not just a non-empty `policy_refs`.
Placing the guard before the quality-scoring call means
`logResponseQuality()` reads the already-corrected `policy_refs` —
`has_policy_refs` inherits the right value with no separate change to the
scoring logic itself. The rubric doc (ADR-0019) is amended to state the
real check, but the code doesn't need to know about `chunk_count` twice.

### Anti-Pattern Avoided: Trusting Prompt Instructions as the Sole Safety Mechanism
The prompt already told the model "No specific policy context retrieved,"
and the model cited nonexistent policies anyway on 18/49 zero-chunk
calls at high confidence. ADR-0034 explicitly rejected relying on
prompt wording as the sole safeguard for something with audit-trail
consequences — the fix has to hold structurally (a programmatic check
against `chunk_count`) regardless of what any given model does under a
given prompt, since prompt compliance isn't guaranteed and can't be
verified per-call without exactly this kind of check.

### Challenges
Placement was the real decision point, not the guard logic itself: the
guard has to see `$policyChunks` (available in `analyze()`/
`analyzeTransaction()`, since `fetchPolicyContext()` already returns it
there) but must run before `logResponseQuality()` computes
`has_policy_refs`, otherwise ADR-0019's rubric fix wouldn't fall out
automatically and would need its own separate `chunk_count` check
duplicated inside the scoring method. Getting that ordering right up
front avoided a second pass through the scoring logic.

A secondary complication surfaced only once tests ran: the identical
fragile test pattern — a mocked zero-chunk `searchNamespace` return
paired with a model response asserting non-empty `policy_refs`, plus
`Log::shouldReceive('warning')->never()` — existed independently in
`OllamaDriverTest`, `GeminiDriverTest`, and `OpenRouterDriverTest`
(the "quality score 4" and "null mean_score" tests in each file, plus
OpenRouterDriverTest's "well-formed response" parsing test). Each one
was, by construction, relying on the pre-guard fabrication behavior
without knowing it. Found and fixed one at a time via `composer test`
failures rather than a single upfront grep across all three files.

Verified: 3 new `OllamaDriverTest` cases covering the guard directly
(strips + warns on fabrication, passes through when chunks exist, no-op
when already empty), plus fixes to 6 existing tests across
`OllamaDriverTest`, `GeminiDriverTest`, and `OpenRouterDriverTest` whose
mocks had been accidentally exercising the fabrication path — the
"quality score 4" and "null mean_score" tests in each of the three
files. Full suite: 396/396 passing, 0 regressions. Pint clean on all
new/modified files (pre-existing style findings in
`GeminiDriverTest.php`/`OpenRouterDriverTest.php` confirmed unrelated to
this change via `git stash`).
