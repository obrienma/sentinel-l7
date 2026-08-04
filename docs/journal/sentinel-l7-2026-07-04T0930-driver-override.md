---
id: sentinel-l7-2026-07-04T0930-driver-override
repo: sentinel-l7
title: "Per-Request Compliance Driver Override"
date: 2026-07-04
phase: 17
tags: [driver-override, cache-poisoning, arbiter-l8, mcp]
files: [app/Services/TransactionProcessorService.php, app/Mcp/Tools/AnalyzeTransaction.php, tests/Unit/TransactionProcessorServiceTest.php, tests/Unit/Mcp/AnalyzeTransactionToolTest.php, README.md]
---

# Per-Request Compliance Driver Override

Added an optional `?string $driverOverride` parameter to
`TransactionProcessorService::process()` and a matching `driver` field on
the `analyze_transaction` MCP tool, so a caller can force a specific
`ComplianceManager` driver (`gemini`/`openrouter`/`ollama`) instead of the
app-wide `SENTINEL_AI_DRIVER` default. Built for arbiter-l8's
cross-provider disagreement layer, which needs to run the same
transaction through two providers and compare verdicts — something
app-wide, config-only driver selection couldn't support. `ComplianceManager`
is now injected into `TransactionProcessorService` as a second dependency
alongside the already-resolved default `ComplianceDriver`, so the override
path can resolve a driver by name at call time without disturbing the
common path.

### Pattern: Manager-by-Name Resolution as an Escape Hatch Alongside a Fixed Default Dependency
`ComplianceManager::driver($name)` already existed for the app-wide
default (`getDefaultDriver()`), but `TransactionProcessorService` only
ever consumed the already-resolved `ComplianceDriver` instance via
constructor injection. Adding `ComplianceManager` itself as a second,
additional dependency — rather than replacing the first — lets the
common path keep its simple, already-resolved driver (matching every
existing caller's expectations unchanged) while the override path gets
late-bound resolution by name only when a caller actually asks for it.

### Anti-Pattern Avoided: Cache Poisoning from Synthetic Disagreement Probes
The obvious naive implementation would let a driver override flow
through the normal cache read/write path. That breaks the entire feature
it exists for: two calls for the same (or a near-duplicate) transaction
with different override drivers would have the second short-circuit on
the first's cached verdict instead of getting an independent answer, and
the shared semantic cache — which real production traffic relies on —
would get poisoned with whichever synthetic eval-driven provider ran
last. The override path skips both the cache read and the write, not
just the read, so eval instrumentation stays fully isolated from the
cache real traffic depends on.

### Anti-Pattern Avoided: Silently Falling Back to Tier 3 on an Override Failure
The normal path's `catch (\Throwable)` block routes any AI-driver failure
to the deterministic rule-based `ThreatAnalysisService` — appropriate for
production traffic that needs *some* answer regardless of provider
health. The override path deliberately does not do this: if it did, two
providers both failing (e.g. Gemini quota exhausted and OpenRouter down)
would both silently produce the same rule-based verdict, and a
disagreement scorer would read that as "the providers agree" when in
fact neither one ever answered. A driver-override failure propagates as
an exception; callers that want this signal handle it themselves.

### Decision: Shared `gradeAiResult()` Helper Instead of Duplicating the Derivation Logic
The override path needs the exact same risk_level/is_threat/narrative/
confidence/policy_refs/message derivation as the normal cache-miss path.
Rather than duplicating those lines in a second branch, extracted them
into a private `gradeAiResult(array $aiResult, string $merchant): array`
called from both places — concrete present duplication, not a
hypothetical future one, so extracting it is a genuine simplification
rather than premature abstraction.

### Decision: `source: 'driver_override'` as a Fourth Pipeline-Source Value
The existing `source` field only ever took `cache_hit`/`cache_miss`/
`fallback`. Rather than overload one of those, added a fourth explicit
value so the override path is unambiguously distinguishable in logs, the
Redis feed, and the `transactions` table — matching the existing
`fallback` value's role as an observability signal for a distinct
pipeline path.

### Challenge: The Feature's Own New Test Was Correct — an Older Sibling Test Wasn't
No implementation challenge in the driver-override code itself — a
straightforward additive extension of an existing, well-tested pipeline.
The one non-trivial decision (cache read/write behavior under override)
was resolved by asking directly rather than guessing, since it carries
production-traffic side-effect implications not specified anywhere in
the originating ADR.

Running the full suite after landing this phase surfaced a real,
pre-existing bug in a *different*, older test in the same file:
`AnalyzeTransactionToolTest`'s "is_threat false for a low-value
transaction on cache miss" flaked intermittently, taking 2-3s per run
(mocked HTTP calls execute in milliseconds — the duration alone was the
tell). Three of the file's cache-miss tests had never actually mocked
the compliance-analysis HTTP call, only the embedding and vector-cache
endpoints — `Http::fake()`'s partial pattern list lets genuinely
unmatched URLs through to the real network. With Gemini/OpenRouter as
the ambient default and a placeholder `test-key`, that unmocked call
failed with a real 401, which `TransactionProcessorService`'s
`catch (\Throwable)` silently routed to the Tier 3 rule-based fallback —
and the test's chosen amounts ($9000/$12.50 against the $400 threshold)
happened to produce the exact `is_threat` values the tests expected. The
tests were never actually exercising the AI-analysis path their names
claimed to test; they were accidentally passing via Tier 3. Once Ollama
became the real default (no API key required, so the call succeeds for
real), the unmocked request started reaching a live, non-deterministic
LLM instead of failing predictably — and "is a $12.50 coffee purchase
low risk" isn't a 100%-guaranteed answer from a live model. Fixed by
adding an explicit `config(['sentinel.ai_driver' => 'ollama'])` plus a
`'*/api/chat'` fake with a fixed risk_level to all three affected tests,
matching the pattern the file's own `driver_override` test already used
for its forced `openrouter` case. Suite duration dropped from ~27s to
~11s across the full run, confirming no other test has the same gap.

Verified: 4 new `TransactionProcessorServiceTest` cases (driver
resolution + `source: driver_override`, cache bypass, exception
propagation instead of Tier 3 fallback, `observe: false` behavior) and 2
new `AnalyzeTransactionToolTest` cases (cache bypass through the MCP
tool, validation error on an unrecognized `driver` name via `Rule::in`).
Full suite: 318/318 passing, stable across 3 consecutive runs.
