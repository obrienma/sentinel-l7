---
id: sentinel-l7-2026-07-03T0915-axiom-tier3-fallback
repo: sentinel-l7
title: "Rule-Based Tier 3 Fallback for the Axiom Pipeline"
date: 2026-07-03
phase: 14
tags: [tier-3-fallback, observability, deterministic-fallback]
files: [app/Services/AxiomThreatAnalysisService.php, app/Services/AxiomProcessorService.php, tests/Unit/AxiomThreatAnalysisServiceTest.php, tests/Unit/AxiomProcessorServiceTest.php, README.md]
---

# Rule-Based Tier 3 Fallback for the Axiom Pipeline

The transaction pipeline has had a Tier 3 rule-based fallback
(`ThreatAnalysisService`) since ADR-0007, but the Axiom pipeline never
got an equivalent: when `AxiomProcessorService::routeToAi()` caught a
`\Throwable` from the AI driver, it logged the error and moved on,
leaving `$result` at its initial defaults — `risk_level: 'unknown'`,
`narrative: null` — and persisted `driver_used` as the *configured*
driver name even though that driver never actually produced a verdict.
An Axiom that already breached `AXIOM_AUDIT_THRESHOLD` (that's the only
way `routeToAi()` gets called at all) would silently get no verdict on
an outage, with no observable signal distinguishing "AI succeeded" from
"AI failed but we said nothing."

### Decision: A Deterministic Single-Verdict Fallback, Not a Second Threshold Ladder
`AxiomThreatAnalysisService::analyze()` always returns `risk_level: 'high'`
with a narrative citing the anomaly score, the configured
`axiom_threshold`, and the domain. No second "how bad is bad" threshold
was introduced. The transaction pipeline's `ThreatAnalysisService` fallback
rule (amount vs. `high_risk` threshold) is meaningful because it's
independent of why the cache missed — a cache miss carries no signal about
transaction risk. The Axiom fallback is different: by the time
`routeToAi()` runs, the anomaly score has *already* cleared the audit
threshold, so the one fact worth restating is that the breach happened
and by how much — not re-deriving a graduated verdict the routing
decision already made. Adding a second, arbitrary "critical" cutoff would
have been complexity with no signal behind it.

### Pattern: `driver_used: 'fallback'` Mirrors the Transaction Pipeline's `source` Field
`TransactionProcessorService::process()` already makes its active tier
observable via a `source` field (`cache_hit` | `cache_miss` | `fallback`).
`AxiomProcessorService` had `driver_used`, but it was set unconditionally
to `config('sentinel.ai_driver')` regardless of whether the driver call
actually succeeded — a Gemini outage and a healthy Gemini call were
indistinguishable in the persisted `ComplianceEvent`. `driver_used` is now
set to `'fallback'` specifically in the catch branch, giving the same
per-tier observability the transaction pipeline already had.

### Challenges
Two tests in `AxiomProcessorServiceTest.php` encoded the old (buggy)
behavior as the expected outcome — `it persists the event with null
narrative when the driver throws` was asserting the absence of a
verdict as correct. Renamed to `it falls back to a rule-based verdict
when the driver throws` and rewrote its assertions around the new
`risk_level: 'high'` / `driver_used: 'fallback'` / non-null narrative.
No other test files needed changes: `WatchAxiomsTest` mocks
`AxiomProcessorService::process()` wholesale with pre-baked return
arrays (including one still using `risk_level: 'unknown'`), so it
exercises the command's handling of arbitrary result shapes rather than
`AxiomProcessorService`'s internal fallback logic, and stayed valid
unchanged.

Verified: `AxiomProcessorServiceTest` (new + existing), the new
`AxiomThreatAnalysisServiceTest`, and `WatchAxiomsTest` all green.
Full suite: 282 passed vs. 278 before this phase, same 3 pre-existing
failures (`EmbeddingServiceTest`, `TransactionStreamServiceTest` ×2) and
the same order-dependent `ArchTest` case — nothing attributable to this
change.
