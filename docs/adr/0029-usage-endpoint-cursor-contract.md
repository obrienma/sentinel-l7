# ADR-0029: `GET /usage` Endpoint — Cursor Contract for Ledger-L5

**Date:** 2026-07-09
**Status:** Proposed

## Context

ADR-0028 (Accepted) already defines which persisted rows are billable, count as cache-savings, or are excluded from both. This ADR is a separate, later-maturity decision: the delivery mechanism. Ledger-L5, a separate billing service being built in Python/FastAPI, needs to *pull* that data from Sentinel-L7 (per Ledger-L5 ADR-0003, pull not push; ADR-0005, the usage-pull contract) — and no endpoint for that exists yet. This ADR defines `GET /usage`'s response shape and pagination contract; it does not revisit ADR-0028's billing classification, which it treats as settled input.

This is also a scope reversal worth naming explicitly: ADR-0028 decision #5 states "no new instrumentation in sentinel-l7," written on the assumption that Ledger-L5 would query the two source tables directly. That assumption no longer holds — Ledger-L5 pulling over HTTP instead of direct DB access is the new direction (Ledger-L5 ADR-0003) — so this ADR does require new endpoint code, superseding that one line of ADR-0028 rather than extending it.

Usage lives in two independently-keyed tables:
- `transactions` (sync pipeline) — `id` is Laravel's default auto-increment integer (`$table->id()`, confirmed in `database/migrations/2026_04_03_052019_create_transactions_table.php`), no UUID.
- `compliance_events` (async pipeline) — same, confirmed in `database/migrations/2026_03_31_000001_create_compliance_events_table.php`.

Neither table shares a sequence with the other, so a single unified cursor across both isn't meaningful without a merge step — see Alternatives.

Ledger-L5 flagged a correctness problem with a timestamp-based cursor (`since=<timestamp>`) for a billing audit trail: a row's `created_at` is assigned when its transaction *starts*, not when it commits. Under concurrent writes, a row can commit after another row with a later timestamp has already advanced the cursor past it — making it permanently and silently invisible to every future pull. A monotonic integer cursor over each table's existing auto-increment `id` avoids this without a schema change, provided reads account for in-flight (not-yet-committed) lower-`id` rows — see the safety-lag window below.

This response shape also supersedes an earlier, informal assumption on the Ledger-L5 side: a flat, pipeline-tagged array with cursors derived client-side from each row's `raw_payload`. The nested-per-table shape with a server-provided `next_cursor` (decision #2 below) replaces that specific mechanism — but per Ledger-L5's own ADR-0003 ("Pull, Not Push," Accepted), Ledger-L5 still independently tracks "the max `id` returned by each pull, per pipeline" as its own durable cursor state; `next_cursor` is a convenience that matches what Ledger-L5 would derive from the row batch anyway, not a replacement for Ledger-L5 owning that state itself.

Companion ADR: Ledger-L5's ADR-0003 is the client-side half of this contract — it makes the pull-not-push call, specifies the same dual per-pipeline integer-cursor design independently, and is where Ledger-L5's own cursor-storage and gap-logging obligations (decision #5 below) are recorded.

## Decision

**1. Endpoint:** `GET /usage?since_transactions=<id>&since_compliance_events=<id>` — two independent cursors, one per source table, since each has its own `id` sequence.

**2. Response shape:**
```json
{
  "transactions": [ ... rows with id > since_transactions ... ],
  "compliance_events": [ ... rows with id > since_compliance_events ... ],
  "next_cursor": { "since_transactions": <max id returned>, "since_compliance_events": <max id returned> }
}
```
Both arrays ordered by `id ASC`. Nested per-table arrays (not one flat array with a per-row `pipeline` tag) because the array key already carries that information, and a flat array would force a single merged ordering across two independently-sequenced tables — the same problem the rejected unified cursor has (see Alternatives).

**2a. Row shape.** Each array serializes its model's full column set as persisted — `GET /usage` doesn't project down to a billing-only subset, consistent with decision #4 below. Field lists below are the actual `$fillable`/`$casts` sets (`app/Models/Transaction.php`, `app/Models/ComplianceEvent.php`), not a new schema:

`transactions[]`:
```json
{
  "id": 10482,
  "txn_id": "a1b2c3d4-...",
  "merchant": "ACME Corp",
  "amount": "150.00",
  "currency": "AUD",
  "is_threat": false,
  "message": "Layer 7 Clear: ACME Corp - OK",
  "source": "cache_miss",
  "created_at": "2026-07-09T21:41:00Z"
}
```
`source` is the ADR-0028 billing-classification field (`cache_hit`/`cache_miss`/`fallback`/`driver_override`). `txn_id` is the pipeline's own idempotency key (Phase 19) — distinct from `id`, which is cursor-only and has no meaning outside pagination.

`compliance_events[]`:
```json
{
  "id": 6031,
  "source_id": "sensor-42",
  "domain": "aml",
  "status": "warn",
  "metric_value": 812.4,
  "anomaly_score": 0.91,
  "emitted_at": "2026-07-09T21:40:52Z",
  "routed_to_ai": true,
  "audit_narrative": "Structuring pattern detected...",
  "driver_used": "ollama",
  "created_at": "2026-07-09T21:40:55Z"
}
```
`driver_used` + `routed_to_ai` together are the ADR-0028 billing-classification signal: `routed_to_ai = false` means never attempted (no AI call, `driver_used` is `null`); `routed_to_ai = true` with a real driver name means a successful, billable call; `driver_used = 'fallback'` means attempted and thrown, excluded per ADR-0028. `source_id` is the Axiom pipeline's own idempotency key (ADR-0016-era dedup), analogous to `txn_id` above — not the cursor. `emitted_at` and `created_at` are not interchangeable: `emitted_at` is nullable business time (when Synapse-L4 emitted the Axiom), `created_at` is insertion time (when Sentinel-L7 persisted the row) — the safety-lag filter in decision #3 operates on `created_at` only. This distinction is also why a timestamp cursor doesn't map cleanly onto this table at all (see Alternatives).

`updated_at` is omitted from both — neither model is ever updated in place (`transactions` rows are insert-only per Phase 19's `firstOrCreate`; `compliance_events` rows are insert-only per its own dedup pattern), so `updated_at` is always identical to `created_at` and carries no information `created_at` doesn't already have.

**3. Safety-lag window:** exclude rows created within the last 60 seconds (`created_at < NOW() - INTERVAL '60 seconds'`). This gives any in-flight transaction with a lower `id` time to commit before a cursor can advance past it. To state this precisely (matching Ledger-L5 ADR-0003's own framing, not overclaiming beyond it): the safety lag does **not** eliminate the underlying race — IDs can still be allocated pre-commit, so a pathological case (a transaction held open longer than the safety-lag window) can still produce a gap. What it does is make that failure mode *bounded and detectable instead of silent*, combined with the discontinuity check in decision #5. 60 seconds is a starting default, not a measured value; revisit if Sentinel-L7's typical transaction duration under load needs more headroom.

**4. Rows are returned as-is; billing classification stays client-side.** The endpoint does not filter by ADR-0028's billable/savings/excluded categories — it returns every row past the cursor, and Ledger-L5 applies that classification itself. This keeps "total items processed" auditing possible from one endpoint without a second query parameter, and keeps ADR-0028's classification rules (which may evolve independently) out of this endpoint's API surface and versioning concerns.

**5. Gap handling is Ledger-L5's responsibility.** A discontinuity between Ledger-L5's last cursor and the next batch's starting `id` is a signal worth logging and investigating on the Ledger-L5 side (its ADR-0003), not proof of a lost row — Sentinel-L7 doesn't attempt to explain or backfill it here, it only applies the safety-lag window from decision #3 above.

## Consequences

**Positive:**
- The safety-lag + monotonic-id cursor makes gaps bounded and detectable instead of silent, with no schema change — both tables already have the auto-increment `id` this depends on.
- Decouples Ledger-L5 from direct access to Sentinel-L7's Postgres instance — a real service boundary instead of a shared-database integration.

**Negative:**
- Real endpoint code is required: new route, controller, dual-cursor query, safety-lag filter. This is the concrete cost of superseding ADR-0028's "no new instrumentation" line.
- The 60-second safety lag means Ledger-L5's view of usage is always at least a minute stale, on top of whatever its own poll interval adds. Acceptable for billing; would not be acceptable if this endpoint were ever reused for something latency-sensitive.
- Two independent per-table cursors is more state for Ledger-L5 to track than one global cursor — accepted because `transactions` and `compliance_events` have independent `id` sequences with no shared ordering guarantee between them.

## Alternatives Considered

**Timestamp-based cursor (`since=<timestamp>`).** Rejected — commit-order vs. assigned-timestamp mismatch under concurrent writes creates silently-invisible rows, unacceptable for a billing audit trail. Also doesn't map cleanly onto the actual schema either way: `transactions` has no business-time field at all (only `created_at`), and `compliance_events` has two non-interchangeable timestamps (`emitted_at`, nullable business time; `created_at`, insertion time) — there's no single obvious column a timestamp cursor would even read from.

**Single unified cursor across both tables.** Rejected — the two tables have independent `id` sequences; one cursor value can't meaningfully order rows across both without a shared sequence or a merge-by-timestamp step, which reintroduces the timestamp problem above.

**Server-side billing-classification filter as a query param (e.g. `?class=billable`).** Rejected — returning all rows and filtering client-side keeps "total processed" auditing possible from one endpoint, and keeps ADR-0028's classification logic out of this endpoint's versioning surface.
