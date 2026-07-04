# Probes — Phase 14: Rule-Based Tier 3 Fallback for the Axiom Pipeline

See: docs/journal.md#phase-14

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-14, decision, architecture]
---
Q: `ThreatAnalysisService`'s Tier 3 rule compares transaction amount
against a threshold — an independent signal from whatever caused the
cache miss. Why doesn't `AxiomThreatAnalysisService` introduce a second,
more severe threshold to grade "how bad" an Axiom is when AI fails?

A: Because the two fallbacks sit in different positions relative to
their threshold check. A vector-cache miss carries no information about
transaction risk, so the amount-threshold rule in `ThreatAnalysisService`
is genuinely new signal. `AxiomProcessorService::routeToAi()` only runs
once `anomaly_score` has already cleared `AXIOM_AUDIT_THRESHOLD` — the
breach already happened by construction. A second arbitrary "critical"
cutoff would grade severity with no additional signal behind it; the
fallback instead restates the breach (score, threshold, domain) as a
deterministic `risk_level: 'high'` verdict.

Extra: sentinel-l7 · Phase 14 · Decision: A Deterministic Single-Verdict Fallback, Not a Second Threshold Ladder
See: docs/journal.md#phase-14

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-14, pattern, observability]
---
Q: Before this phase, `AxiomProcessorService` set `driver_used` to
`config('sentinel.ai_driver')` unconditionally inside `routeToAi()`. What
was wrong with that, and what pattern from the transaction pipeline was
mirrored to fix it?

A: `driver_used` was set to the configured driver name regardless of
whether `$this->driver->analyze($data)` actually succeeded, so a Gemini
outage and a healthy Gemini call produced an identical `driver_used`
value in the persisted `ComplianceEvent` — the degraded path was
invisible. The fix mirrors `TransactionProcessorService`'s `source` field
(`cache_hit` | `cache_miss` | `fallback`): `driver_used` is now set to the
literal string `'fallback'` specifically inside the `catch (\Throwable)`
branch, so the active tier is observable per event, the same way it
already was for transactions.

Extra: sentinel-l7 · Phase 14 · Pattern: driver_used: 'fallback' Mirrors the Transaction Pipeline's source Field
See: docs/journal.md#phase-14

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-14, challenge, testing]
---
Q: Fixing the Axiom Tier 3 gap required rewriting one existing test in
`AxiomProcessorServiceTest.php`. Why did `WatchAxiomsTest` need no changes
at all, even though it exercises the same `AxiomProcessorService` and one
of its test cases still hard-codes `risk_level: 'unknown'`?

A: `WatchAxiomsTest` mocks `AxiomProcessorService::process()` wholesale
with pre-baked return arrays — it's testing the `sentinel:watch-axioms`
command's handling of arbitrary result shapes (including a null
narrative or an `'unknown'` risk_level), not `AxiomProcessorService`'s
internal fallback logic. Only `AxiomProcessorServiceTest`'s `it persists
the event with null narrative when the driver throws` directly asserted
on the old buggy behavior as correct, so only it needed rewriting (to
`it falls back to a rule-based verdict when the driver throws`, asserting
`risk_level: 'high'`, `driver_used: 'fallback'`, and a non-null
narrative).

Extra: sentinel-l7 · Phase 14 · Challenge: A stale test encoded the bug as the expected behavior
See: docs/journal.md#phase-14
