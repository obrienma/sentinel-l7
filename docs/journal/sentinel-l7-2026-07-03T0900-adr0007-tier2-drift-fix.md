---
id: sentinel-l7-2026-07-03T0900-adr0007-tier2-drift-fix
repo: sentinel-l7
title: "Close ADR-0007 Tier 2 Implementation Drift"
date: 2026-07-03
phase: 13
tags: [tier-2-analysis, prompts-convention, rag, mock-drift]
files: [app/Contracts/ComplianceDriver.php, app/Services/Compliance/GeminiDriver.php, app/Services/Compliance/OpenRouterDriver.php, app/Services/TransactionProcessorService.php, prompts/transaction-compliance-analysis.md, prompts/transaction-compliance-analysis.txt, tests/Feature/WatchTransactionsTest.php, tests/Unit/GeminiDriverTest.php, tests/Unit/OpenRouterDriverTest.php, tests/Unit/TransactionProcessorServiceTest.php, README.md]
---

# Close ADR-0007 Tier 2 Implementation Drift

ADR-0007 specifies Tier 2 as "Gemini Flash + policy RAG" on a cache miss,
with `ThreatAnalysisService` reserved for Tier 3 (infra failure only). The
implementation had drifted: `TransactionProcessorService` called
`ThreatAnalysisService::analyze()` unconditionally on cache miss, so no
LLM was ever in the transaction pipeline's normal path — only the Axiom
pipeline used AI. Added `ComplianceDriver::analyzeTransaction()` (new
interface method, implemented on both `GeminiDriver` and `OpenRouterDriver`)
and wired it into `TransactionProcessorService`'s cache-miss branch;
`ThreatAnalysisService::analyze()` now runs only inside the outer
`catch (\Throwable)`, matching the ADR.

### Decision: New Prompt File and Query-Text Builder, Not Reuse of the Axiom Prompt
`analyzeTransaction()` gets its own prompt (`transaction-compliance-analysis`)
and its own RAG query-text builder (`buildTransactionQueryText()`) on both
drivers, rather than adapting the existing Axiom-shaped
`compliance-audit-narrative` prompt. The two inputs are shaped differently
(a `{merchant, amount, currency}` transaction vs. an Axiom's anomaly
payload) and forcing one template to serve both would mean conditional
logic inside the prompt text itself — the same anti-pattern the Prompts
Convention exists to prevent. Both prompts converge on the same output
schema so `parseResponse()`/`logResponseQuality()` are shared unchanged.

### Challenge: A Stale Test Mock Only Surfaced Once the Real Bug Was Fixed
After wiring in the driver call, one `WatchTransactionsTest` case —
"writes the pending count to the lag key" — failed with `Typed property
App\Services\ThreatResult::$isThreat must not be accessed before
initialization`, thrown from inside the Tier 3 fallback branch. The test's
`ThreatAnalysisService` mock was `shouldNotReceive('analyze')` (correct,
now that Tier 2 shouldn't touch it), yet `analyze()` was still being
called. Root cause: the test's `VectorCacheService::upsertNamespace` mock
still stubbed `andReturnNull()`, a leftover from the pre-fix version of
this test file — every other test in the same file had already been
updated to `andReturn(true)` to match the method's `bool` return type.
`upsertNamespace()` returning `null` against a `bool` return type is a
`TypeError`, which the outer `catch (\Throwable)` swallows and treats as
an infra failure, forcing the Tier 3 path — which is exactly what masked
the problem before this fix: on master, cache miss unconditionally called
`ThreatAnalysisService`, so the broken mock's forced fallback was
indistinguishable from the normal path and the test passed for the wrong
reason. Fixed by changing the one leftover `andReturnNull()` to
`andReturn(true)`. Lesson: a mock that silently coerces a real method
into throwing can hide behind a bug that already routes through the same
catch block — fixing the bug is what exposed the mock defect, not a
regression introduced by the fix.

Verified: `TransactionProcessorServiceTest`, `GeminiDriverTest`,
`OpenRouterDriverTest`, and `WatchTransactionsTest` all green (85/85).
Full suite has no new failures relative to master — remaining failures
(`EmbeddingServiceTest`, `TransactionStreamServiceTest` ×2, one
order-dependent `ArchTest` case) are pre-existing and identical on both
branches; this worktree additionally shows 10 Inertia/Auth/Example
failures from a missing `public/build/manifest.json`, which is a
worktree Vite-build artifact gap, not a code regression.
