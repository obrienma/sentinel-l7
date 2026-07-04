# Probes — Phase 13: Close ADR-0007 Tier 2 Implementation Drift

See: docs/journal.md#phase-13

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-13, decision, architecture]
---
Q: Why did `analyzeTransaction()` get its own prompt and RAG query-text
builder on `GeminiDriver`/`OpenRouterDriver` instead of reusing the
existing Axiom-shaped `compliance-audit-narrative` prompt?

A: The two inputs are shaped differently — a `{merchant, amount, currency}`
transaction versus an Axiom's anomaly payload — and forcing one template
to serve both would mean branching logic inside the prompt text itself,
the exact anti-pattern the Prompts Convention exists to prevent. The new
`transaction-compliance-analysis` prompt is a separate file with its own
version/changelog, but both prompts converge on the same output schema so
`parseResponse()`/`logResponseQuality()` are shared unchanged across
drivers.

Extra: sentinel-l7 · Phase 13 · Decision: New Prompt File and Query-Text Builder, Not Reuse of the Axiom Prompt
See: docs/journal.md#phase-13

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-13, challenge, testing]
---
Q: After wiring `ComplianceDriver::analyzeTransaction()` into the
cache-miss branch, one `WatchTransactionsTest` case failed with
`Typed property App\Services\ThreatResult::$isThreat must not be accessed
before initialization`, even though its `ThreatAnalysisService` mock was
`shouldNotReceive('analyze')`. What was actually going wrong, and why had
it never surfaced before?

A: The test's `VectorCacheService::upsertNamespace` mock still stubbed
`andReturnNull()` — a leftover from before the fix, while every other
test in the file had already moved to `andReturn(true)` to match the
method's `bool` return type. Returning `null` against a `bool` return
type is a `TypeError`, which the outer `catch (\Throwable)` in
`TransactionProcessorService` swallows and treats as an infra failure,
forcing the Tier 3 fallback — which then called the mocked `analyze()`
in violation of `shouldNotReceive`. It never surfaced on master because
master's cache-miss path called `ThreatAnalysisService` unconditionally
anyway, so the broken mock's forced fallback was indistinguishable from
the normal path. Fixing the real ADR-0007 drift bug is what exposed the
stale mock, not a regression the fix introduced.

Extra: sentinel-l7 · Phase 13 · Challenge: A Stale Test Mock Only Surfaced Once the Real Bug Was Fixed
See: docs/journal.md#phase-13
