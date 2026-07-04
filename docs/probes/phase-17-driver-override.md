# Probes — Phase 17: Per-Request Compliance Driver Override

See: docs/journal.md#phase-17

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-17, pattern, architecture]
---
Q: Why does `TransactionProcessorService` take both `ComplianceDriver
$driver` and `ComplianceManager $complianceManager` as constructor
dependencies, rather than just the manager and resolving the default
driver by name every time?

A: The common path (no override) never needs to know a driver name —
it just uses whatever the container already resolved as the app-wide
default, which is simpler to test and matches every existing caller's
expectations unchanged. The manager is only consulted when a caller
explicitly names a different driver, which is the rare, eval-driven
case — adding it as a second dependency rather than replacing the first
keeps the common path exactly as it was before this feature existed.

Extra: sentinel-l7 · Phase 17 · Pattern: Manager-by-Name Resolution as an Escape Hatch Alongside a Fixed Default Dependency
See: docs/journal.md#phase-17

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-17, anti-pattern, caching]
---
Q: Why does a driver-override call skip the vector cache read *and*
write, rather than just skipping the read?

A: Skipping only the read would still let the override call's result
get upserted into the cache, so the next real (non-override) transaction
close to it would incorrectly inherit whichever provider happened to run
the eval probe — a synthetic comparison call silently changing
production verdicts. Skipping both keeps eval instrumentation fully
isolated from the cache real traffic depends on; two override calls for
the same transaction always get independent answers instead of the
second short-circuiting on the first's cached verdict.

Extra: sentinel-l7 · Phase 17 · Anti-Pattern Avoided: Cache Poisoning from Synthetic Disagreement Probes
See: docs/journal.md#phase-17

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-17, anti-pattern, resilience]
---
Q: The normal cache-miss path falls back to the rule-based
`ThreatAnalysisService` (Tier 3) on any driver exception. Why must a
driver-override failure propagate as an exception instead of falling
back the same way?

A: Cross-provider disagreement scoring needs to know when a provider
genuinely didn't answer, not receive a deterministic rule-based verdict
standing in for it. If both paths fell back the same way, two providers
that both failed (e.g. Gemini quota exhausted and OpenRouter down) would
produce the identical rule-based verdict and a disagreement scorer would
read that as "the providers agree" — corrupting the exact signal the
feature exists to measure.

Extra: sentinel-l7 · Phase 17 · Anti-Pattern Avoided: Silently Falling Back to Tier 3 on an Override Failure
See: docs/journal.md#phase-17

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-17, decision, refactor]
---
Q: Why was `gradeAiResult()` extracted into its own private method as
part of adding the driver-override path, rather than left as inline
logic duplicated in both branches?

A: The override path needs the exact same risk_level/is_threat/
narrative/confidence/policy_refs/message derivation as the normal
cache-miss path — this was concrete, present duplication introduced by
adding the second branch, not a hypothetical future one. Extracting it
into a shared `gradeAiResult(array $aiResult, string $merchant): array`
is a genuine simplification the new branch required, not premature
abstraction added speculatively.

Extra: sentinel-l7 · Phase 17 · Decision: Shared gradeAiResult() Helper Instead of Duplicating the Derivation Logic
See: docs/journal.md#phase-17

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-17, decision, observability]
---
Q: The `source` field on a transaction result previously only took
`cache_hit`/`cache_miss`/`fallback`. Why add a fourth `driver_override`
value instead of reusing `cache_miss` (since the override path also
calls a `ComplianceDriver` fresh, same as a cache miss)?

A: So the override path is unambiguously distinguishable in logs, the
Redis feed, and the `transactions` table — matching the existing
`fallback` value's role as an observability signal for a distinct
pipeline path. Reusing `cache_miss` would make synthetic eval-driven
calls indistinguishable from real production cache misses in every
downstream consumer of that field, which is exactly the ambiguity a
dedicated value avoids.

Extra: sentinel-l7 · Phase 17 · Decision: source: 'driver_override' as a Fourth Pipeline-Source Value
See: docs/journal.md#phase-17

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-17, challenge, testing]
---
Q: `AnalyzeTransactionToolTest`'s "is_threat false for a low-value
transaction on cache miss" started flaking intermittently, each run
taking 2-3 seconds — suspiciously slow for a fully-mocked test. What was
actually happening, and why did it only start failing now?

A: That test (and two siblings) never mocked the compliance-analysis
HTTP call, only the embedding and vector-cache endpoints —
`Http::fake()`'s partial pattern list lets genuinely unmatched URLs
through to the real network. With Gemini/OpenRouter as the ambient
default and a placeholder `test-key`, the unmocked call failed with a
real 401, which `TransactionProcessorService` silently routed to the
Tier 3 rule-based fallback — and the test's chosen amounts ($9000 /
$12.50 against the $400 threshold) happened to produce the exact
`is_threat` values expected, purely by coincidence of the fallback's own
threshold. The tests were never actually exercising the AI-analysis path
their names claimed to. Once Ollama became the real default (no API key
needed, so the call succeeds), the same unmocked request started
reaching a live, non-deterministic LLM instead of failing predictably —
and "is a $12.50 coffee purchase low risk" isn't a guaranteed answer
from a live model on every run.

Extra: sentinel-l7 · Phase 17 · Challenge: The Feature's Own New Test Was Correct — an Older Sibling Test Wasn't
See: docs/journal.md#phase-17
