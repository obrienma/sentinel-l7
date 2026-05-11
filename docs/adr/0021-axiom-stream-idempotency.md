# ADR-0021: Idempotent ComplianceEvent Persistence for Axiom Re-delivery

**Date:** 2026-05-11
**Status:** Accepted

---

## Context

The Axiom pipeline worker reads from the `synapse:axioms` Redis Stream via `XREADGROUP`. A message stays in the Pending Entry List (PEL) until the worker calls `XACK`. The reclaimer (`XAUTOCLAIM`, 60s idle threshold) re-delivers any unACKed message to another worker — the intended recovery path for crashes.

Before this decision, `AxiomProcessorService::routeToAi()` and `recordSubThreshold()` both called `ComplianceEvent::create()` unconditionally. If a worker completed `create()` but crashed before calling `XACK`, the reclaimer would re-deliver the same message and a second `ComplianceEvent` row would be inserted for the same `source_id`. There was no unique constraint on `source_id` and no application-level guard.

A secondary failure mode was identified during review: if two workers raced on the same message (possible when a slow worker survives while the reclaimer also delivers the message), both could pass a naïve SELECT check, both attempt INSERT, and the second would throw an unhandled `QueryException`. Because `WatchAxioms` only calls `XACK` after `process()` returns normally, the unhandled exception would leave the message in the PEL and trigger an infinite retry loop.

---

## Decision

Three changes in combination close both failure modes:

**1. `firstOrCreate` keyed on `source_id`**

Both `routeToAi()` and `recordSubThreshold()` now call a shared private `persist(string $sourceId, array $fields): void` method. Inside it, when `$sourceId` is a real identifier, `ComplianceEvent::firstOrCreate(['source_id' => $sourceId], $fields)` is used — first write wins. On re-delivery, the SELECT finds the existing row and returns it without inserting.

When `$sourceId` is `'unknown'` (a malformed Axiom that arrived without a stable identifier), `ComplianceEvent::create()` is used instead. These events cannot be deduplicated by ID, and suppressing them all into a single row would be worse than accepting occasional duplicates.

**2. PostgreSQL partial unique index**

```sql
CREATE UNIQUE INDEX compliance_events_source_id_unique
    ON compliance_events (source_id)
    WHERE source_id != 'unknown'
```

Defense-in-depth. The application-level `firstOrCreate` handles the common retry case; the DB constraint catches any future code path that bypasses `persist()`. The `'unknown'` exclusion matches the application logic.

**3. `UniqueConstraintViolationException` catch in `persist()`**

The concurrent worker race cannot be prevented at the application layer — `firstOrCreate` is a SELECT then INSERT, not atomic. The catch ensures that when the unique constraint fires (Worker B losing the race to Worker A), `persist()` returns normally, `process()` returns its result, and `XACK` fires for both workers. Without the catch, the second worker's exception would skip ACK and trigger the retry loop.

```php
} catch (\Illuminate\Database\UniqueConstraintViolationException) {
    Log::info('AxiomProcessorService: duplicate source_id suppressed by DB constraint', [
        'source_id' => $sourceId,
    ]);
}
```

**4. Warning log on `'unknown'` source_id**

`process()` emits a `Log::warning` when `$sourceId === 'unknown'`. This makes it detectable if a misconfigured emitter starts sending Axioms without identifiers at volume — a systemic failure that would otherwise accumulate duplicate rows silently.

---

## Alternatives Considered

**`updateOrCreate` instead of `firstOrCreate`**

Would update the existing row on every re-delivery rather than leaving the first write untouched. Rejected because it creates a correctness risk: if the AI analysis succeeded on the first pass and the worker crashed before ACK, a retry that fails the AI analysis would overwrite a valid `audit_narrative` with `null`. First-write-wins is the correct semantics for idempotent event persistence.

**Pass the Redis stream message ID as the idempotency key**

The stream message ID (e.g. `1746000000000-0`) is guaranteed unique by Redis and would sidestep the `'unknown'` edge case entirely. Rejected because it requires threading the message ID through `WatchAxioms` → `AxiomProcessorService` → `persist()`, adds a `stream_message_id` column to `compliance_events`, and couples the persistence model to the delivery mechanism. The `source_id` is the natural business key for a ComplianceEvent; using it keeps the model clean.

**Pre-check SELECT before calling AI**

Read `ComplianceEvent::where('source_id', $sourceId)->exists()` at the top of `process()` and return early if already persisted — avoiding a wasted AI call on re-delivery. Rejected for the common path: this adds a DB round-trip to every single Axiom (the retry scenario is rare). The current approach runs the AI call twice in the rare crash+reclaim case, which wastes one AI call but does not affect correctness. If quota cost becomes a concern, this optimization can be layered in.

---

## Consequences

**Positive**

- Duplicate `compliance_events` rows on stream re-delivery are eliminated. The DB constraint enforces this independently of application code.
- The concurrent race (two workers on the same message) no longer causes an infinite retry loop; both workers ACK cleanly.
- `persist()` is a single method that owns all idempotency logic for ComplianceEvent writes — easier to audit and extend.
- Malformed Axioms without a `source_id` are now visible in logs at warning level.

**Negative / Trade-offs**

- The AI analysis (`driver->analyze()`) is still called on every re-delivery. In the crash-before-ACK scenario, the AI call runs twice and the second result is discarded. This wastes one API call per crashed message. Acceptable at current scale; the pre-check SELECT optimization (above) would close this gap if needed.
- `'unknown'` Axioms remain undeduplicatable. If a source emitter is misconfigured to omit `source_id`, the warning log surfaces this but does not prevent accumulation. An upstream fix (enforcing `source_id` at the emitter) is the real solution.
- The partial unique index means `source_id` values of `'unknown'` are excluded from the uniqueness guarantee at the DB layer. This is a documented and intentional asymmetry, but it means the DB constraint alone is not sufficient for the `'unknown'` case.
