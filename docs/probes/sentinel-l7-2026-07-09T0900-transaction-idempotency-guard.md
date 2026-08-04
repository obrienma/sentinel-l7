---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-19, idempotency-guard, partial-unique-index]
---
The transaction idempotency guard uses a {{c1::partial unique index}}
(`WHERE source != 'driver_override'`) applied via raw SQL through
{{c2::DB::statement}}, not Laravel's `$table->unique()` — because
`PostgresGrammar::compileUnique()` has no fluent way to express a partial
index.

Extra: sentinel-l7 · Phase 19 · Pattern: Partial Unique Index Scoped to the At-Risk Subset, Not the Whole Column
See: docs/journal/sentinel-l7-2026-07-09T0900-transaction-idempotency-guard.md

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-19, driver-override, anti-pattern]
---
A literal port of the Axiom pipeline's plain-unique-index dedup fix would
have silently broken {{c1::driver_override}} (Phase 17) — arbiter-l8 calls
`process()` once per provider against the same {{c2::txn_id}} on purpose to
compare verdicts, and each call must persist its own row.

Extra: sentinel-l7 · Phase 19 · Anti-Pattern Avoided: A Literal Axiom Mirror Would Have Broken Cross-Provider Comparison
See: docs/journal/sentinel-l7-2026-07-09T0900-transaction-idempotency-guard.md

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-19, idempotency-guard, billing]
---
The early-exit dedup check returns `source: {{c1::duplicate}}` to the
caller but never calls {{c2::recordTransaction()}} — so it never lands in
the `transactions.source` column, keeping ADR-0028's billing filter at
exactly {{c3::4}} distinct persisted `source` values.

Extra: sentinel-l7 · Phase 19 · Decision: source: 'duplicate' Is Return-Shape-Only, Never Persisted
See: docs/journal/sentinel-l7-2026-07-09T0900-transaction-idempotency-guard.md

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-19, testing, idempotency-guard]
---
Q: The `UniqueConstraintViolationException` catch branch couldn't be
triggered through `TransactionProcessorService`'s normal call path in a
test. Why not, and how was it tested instead?

A: `firstOrCreate()`'s own SELECT-then-INSERT sequence means a second
synchronous call in a single-threaded test always finds the row via the
read and never reaches the insert, and the suite's `sqlite :memory:` test
DB is single-connection, ruling out a real concurrent-connection race
either way. Rather than leave the branch untested (matching the Axiom
pipeline's own equivalent gap), the exact exception was forced via a
`Transaction::creating()` model event hook — a deliberate, deterministic
improvement over the precedent, not an oversight.

Extra: sentinel-l7 · Phase 19 · Decision: Deterministic Model-Event-Hook Test for the DB-Constraint-Catch Branch
See: docs/journal/sentinel-l7-2026-07-09T0900-transaction-idempotency-guard.md

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-19, xautoclaim, redis-streams]
---
`WatchTransactions` only ACKs a stream message after `process()` fully
completes; a worker crash in that window (a Railway deploy restart, OOM,
host crash) leaves the message unacked, so {{c1::XAUTOCLAIM}} (ADR-0022)
reclaims and reprocesses it — risking a second billable row for the same
transaction without a dedup guard.

Extra: sentinel-l7 · Phase 19 · Motivation for the idempotency guard
See: docs/journal/sentinel-l7-2026-07-09T0900-transaction-idempotency-guard.md
