---
id: sentinel-l7-2026-07-09T0900-transaction-idempotency-guard
repo: sentinel-l7
title: "Transaction-Pipeline Idempotency Guard (ADR-0028 Prerequisite)"
date: 2026-07-09
phase: 19
tags: [idempotency-guard, partial-unique-index, xautoclaim, driver-override]
files: [database/migrations/2026_07_09_000001_add_txn_id_unique_partial_to_transactions.php, app/Services/TransactionProcessorService.php, app/Console/Commands/WatchTransactions.php, tests/Unit/TransactionProcessorServiceTest.php]
---

# Transaction-Pipeline Idempotency Guard (ADR-0028 Prerequisite)

While drafting ADR-0028 (billing classification of `transactions.source`/
`compliance_events.driver_used` rows for Ledger-L5), reviewing what "one
row = one billable event" actually requires surfaced a real gap:
`WatchTransactions.php` only ACKs a stream message after
`TransactionProcessorService::process()` fully completes (embed → vector
search → AI call → cache upsert → DB write), and `transactions.txn_id` had
no uniqueness guarantee. A worker crash in that window — a Railway deploy
restart, OOM, host crash, all routine — leaves the message unacked;
`XAUTOCLAIM` (ADR-0022) reclaims and reprocesses it, and nothing stopped a
second billable `cache_miss` row from being written for the same
transaction. Mirrors the Axiom pipeline's existing fix for the identical
`XAUTOCLAIM` redelivery risk: a partial unique index on
`compliance_events.source_id`, an early-exit `EXISTS` check in `process()`,
and `firstOrCreate` + a caught `UniqueConstraintViolationException`.

### Pattern: Partial Unique Index Scoped to the At-Risk Subset, Not the Whole Column
`CREATE UNIQUE INDEX ... WHERE source != 'driver_override'`, raw SQL via
`DB::statement` (Laravel's `Schema` builder / `PostgresGrammar::compileUnique()`
has no fluent way to express a partial index — the same reason the Axiom
migration used raw SQL instead of `$table->unique()`). Structurally
identical to Axiom's `WHERE source_id != 'unknown'`, but for the opposite
reason: Axiom excludes a *shared sentinel value causing accidental
collisions*; this excludes a *source value whose repeats are intentional*
(see next section).

### Anti-Pattern Avoided: A Literal Axiom Mirror Would Have Broken Cross-Provider Comparison
The obvious port — a plain unique index on `txn_id`, and a dedup check
applied to all three of `process()`'s branches — would have silently
broken the `driver_override` feature from Phase 17: arbiter-l8 calls
`process()` once per provider against the *same* transaction on purpose,
to compare verdicts, and each call must persist its own row. Found by
tracing the `driverOverride` branch's own docblock before writing any
code, not by a test catching it after the fact — the index, the early-exit
`EXISTS` check, and the `firstOrCreate` scope in `recordTransaction()` all
explicitly exclude `source = 'driver_override'` for this reason.

### Decision: `source: 'duplicate'` Is Return-Shape-Only, Never Persisted
The early-exit check returns `source: 'duplicate'` to the caller (so
`WatchTransactions.php` can label it correctly instead of falling into the
`default` "⚠️ Fallback" arm), but `recordTransaction()` is never called on
that path — `'duplicate'` never lands in the `transactions.source` column.
Keeps ADR-0028's billing filter untouched at exactly the same 4
`source` values (`cache_hit`/`cache_miss`/`fallback`/`driver_override`)
it already specifies, and keeps the still-deferred migration-comment fix
(`database/migrations/2026_04_03_052019_create_transactions_table.php:22`)
a 4-value fix, not 5.

### Decision: Deterministic Model-Event-Hook Test for the DB-Constraint-Catch Branch
`firstOrCreate()`'s own `SELECT`-then-`INSERT` sequence means a second
synchronous call in a single-threaded test always finds the row via the
read and never reaches the insert — so the `UniqueConstraintViolationException`
catch can't be triggered through the service's normal call path in tests,
and `sqlite :memory:` (this suite's test DB) is single-connection, ruling
out a real concurrent-connection race either way. `AxiomProcessorService`'s
own equivalent catch block has no test for the same reason. Rather than
leave the branch as untested dead code to match that precedent exactly,
forced the exact exception via a `Transaction::creating()` model event
hook (flushed after) — a deliberate, deterministic improvement over the
Axiom test suite's gap, not an oversight.

### Challenges
The real challenge was in the design, not the implementation: the first
instinct was to mirror Axiom's fix literally (plain unique index, dedup
check applied uniformly across all of `process()`'s branches). That would
have compiled, passed a naive test, and silently dropped every second
`driver_override` row in production — a regression in a feature (Phase 17)
that has no equivalent safety net of its own precisely because it's
designed to write multiple rows per `txn_id`. Caught before any code was
written by re-reading `driverOverride`'s docblock during planning, not by
a test failing after the fact — worth noting because it's the kind of
regression a superficially-passing test suite would not have caught
without a test specifically for it (which this phase adds:
`it('allows two driver_override calls for the same txn_id ...')`).

Verified: 12 new `TransactionProcessorServiceTest` cases under a new
Idempotency section (redelivery-after-`cache_hit`/`cache_miss`/`fallback`
returns `duplicate` and does not re-call the AI driver or analyzer;
`observe: false` bypasses the check entirely; `driver_override` is neither
blocked by nor blocks a prior/subsequent normal row for the same `txn_id`;
two `driver_override` calls for the same `txn_id` both persist; schema-level
constraint checks; the `UniqueConstraintViolationException` suppression
path). Zero existing tests needed changes — confirmed no existing test
calls `process()` more than once, so the new `EXISTS` check is a no-op
against an empty table on every prior test. Full suite: 332/332 passing.
