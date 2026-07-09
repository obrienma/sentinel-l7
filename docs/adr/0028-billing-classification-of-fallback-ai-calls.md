# ADR-0028: Billing Classification of Attempted-but-Failed AI Calls

**Date:** 2026-07-09
**Status:** Proposed

## Context

Ledger-L5 is being ported to Python/FastAPI and will pull usage data from sentinel-l7 to (a) bill for AI-driven compliance calls and (b) report a "calls avoided" savings metric attributable to the semantic cache. Sentinel-l7 has no billing concept of its own today — this ADR exists to define, for an external consumer, which persisted rows represent a billable AI call versus a degraded/no-op outcome.

Two independent pipelines persist call outcomes, in two tables, with two different fields — there is no unified schema:

- **Transaction pipeline** — `transactions.source` (`app/Services/TransactionProcessorService.php`), one of `cache_hit` | `cache_miss` | `fallback` | `driver_override`. The `transactions` table migration comment (`database/migrations/2026_04_03_052019_create_transactions_table.php:22`) still lists only the first three — stale since `driver_override` was added.
- **Axiom pipeline** — `compliance_events.driver_used` (`app/Services/AxiomProcessorService.php`), nullable string: the configured driver name (e.g. `ollama`) on a successful AI call, the literal string `'fallback'` when the AI call was attempted and threw, or `null` when `routed_to_ai = false` (the Axiom's `anomaly_score` never crossed `AXIOM_AUDIT_THRESHOLD`, so no AI call was attempted at all). `routed_to_ai` is a separate boolean column on the same table.

One asymmetry worth naming: a failed `driver_override` call never persists a row. `TransactionProcessorService::process()`'s override branch (`app/Services/TransactionProcessorService.php:67-81`) does not catch exceptions — by design (ADR for the driver-override feature, Phase 17: a second provider's call must fail loudly, not silently degrade to the shared rule-based verdict). The exception propagates before `recordTransaction()` runs. So "exclude failed `driver_override` rows from billing" is already true by construction on the sentinel-l7 side — there is nothing in the `transactions` table for Ledger-L5 to filter out.

No "calls avoided" / savings metric exists in sentinel-l7 today. This ADR defines the semantics an external consumer must apply; it does not add a new metric here.

## Decision

**1. Transaction pipeline billing filter:** billable = `source IN ('cache_miss', 'driver_override')`. Non-billable = `source IN ('cache_hit', 'fallback')`.

**2. `fallback` rows are non-billable by default.** An attempted-but-failed AI call (Tier 3, rule-based degradation per ADR-0007) is never charged. Conservative default — revisit only if false-negative revenue loss becomes material.

**3. Axiom pipeline billing filter:** billable = `driver_used NOT IN ('fallback')` and not `NULL` (equivalently, `routed_to_ai = true AND driver_used <> 'fallback'`). This has two distinct non-billable cases that Ledger-L5 should keep distinguishable in its own accounting even though both net to $0: `routed_to_ai = false` (below threshold, never attempted — no cost, no risk) versus `driver_used = 'fallback'` (attempted, threw — same conservative non-billing rule as transaction-pipeline `fallback`).

**4. Savings ("calls avoided") metric:** `transactions.source = 'cache_hit'` only. The Axiom pipeline has no cache and contributes nothing to this metric. `driver_override` is excluded even though it also bypasses the cache — it's a deliberate bypass for cross-provider disagreement scoring, not a cache-savings outcome, and folding it in would misrepresent cache efficacy.

**5. No new instrumentation in sentinel-l7.** `source`, `driver_used`, and `routed_to_ai` already distinguish every case cleanly. The work is (a) this document and (b) Ledger-L5's usage-pull query applying the filters above per-pipeline.

## Consequences

**Positive:**
- Zero schema changes, zero new fields to keep in sync — billability is fully derived from columns that already exist for observability reasons (ADR-0007, Phase 14, Phase 17).
- Conservative-by-default billing (never charge for a failed call) avoids disputed invoices without requiring a reconciliation process on day one.

**Negative:**
- Never billing `fallback` rows means a sustained AI-provider outage (e.g. the Ollama-host-down scenario named in ADR-0027's consequences) is invisible in revenue, not just in compliance-quality metrics. Should be monitored via the existing `sentinel_metrics_fallback_count` Redis key rather than assumed rare.
- Two tables, two field names, two filter expressions for the same underlying question ("did we pay for an AI call?") — Ledger-L5 must implement and maintain both paths separately. Unifying the schema is out of scope for this decision.

## Alternatives Considered

**Bill for `fallback` rows too.** Rejected — a failed call delivered no compliance value; charging for it inverts the conservative default this system otherwise uses (e.g. ADR-0007's zero-transaction-loss framing).

**Fold `driver_override` into the cache-savings metric.** Rejected — `driver_override` bypasses the cache on purpose for a different reason (provider comparison), and counting it as a "cache win" would overstate the cache's actual hit-driven savings.

**Add a unified `billable` boolean column to both tables now.** Rejected — no new instrumentation is needed; `source`/`driver_used`/`routed_to_ai` already fully determine billability. A redundant derived column would need to stay hand-in-sync with this ADR's logic for no new information.
