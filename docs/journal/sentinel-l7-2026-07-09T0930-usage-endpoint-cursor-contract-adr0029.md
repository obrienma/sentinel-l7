---
id: sentinel-l7-2026-07-09T0930-usage-endpoint-cursor-contract-adr0029
repo: sentinel-l7
title: "GET /usage Cursor Contract Drafted as ADR-0029, Not Folded Into ADR-0028"
date: 2026-07-09
phase: 21
tags: [adr, cursor-pagination, usage-endpoint, supersession]
files: [docs/adr/0029-usage-endpoint-cursor-contract.md, README.md]
---

# GET /usage Cursor Contract Drafted as ADR-0029, Not Folded Into ADR-0028

Decision-only step, no code yet (mirrors Phase 8's structure for
ADR-0025). A second draft of the billing-classification work arrived
proposing to rewrite ADR-0028 in place — same filename, new title, new
`GET /usage` endpoint + dual-cursor pagination contract, and `Status`
reset to `Proposed`. Reviewed before applying anything, since amending
`0028` directly would have silently reverted Phase 20's Accepted
transition while the commit being amended still contained Phase 20's own
journal entry and README bullet asserting Accepted had happened —
self-contradictory within one commit if merged as one file.

### Decision: Split Into a New ADR Instead of Amending 0028
The incoming draft conflated two decisions of different maturity: the
billing classification itself (settled — gated on and confirmed safe by
Phase 19's idempotency fix, already Accepted) and the HTTP delivery
mechanism for that data (brand new, unbuilt, genuinely still open).
Reverting `0028` to `Proposed` to accommodate the second would have
downgraded a decision that's actually done. Kept `0028` untouched;
opened `0029` for the endpoint/cursor contract alone, cross-referencing
`0028` for classification instead of restating it — one ADR per
decision, at the maturity level that decision has actually reached.

### Decision: `0029` Explicitly Names Its Supersession of `0028`'s "No New Instrumentation" Line
`ADR-0028` decision #5 said no new sentinel-l7 instrumentation was
needed, written on the assumption Ledger-L5 would query the two source
tables directly. `0029` reverses that assumption (Ledger-L5 pulls over
HTTP, per Ledger-L5 ADR-0003) and requires real endpoint code. Recorded
as an explicit, named supersession in `0029`'s Context rather than a
silent contradiction a future reader would have to reconcile themselves.

### Challenges
None — this was a documentation/triage step, not implementation.
Verified the draft's two checkable technical claims against the actual
schema before accepting them: both `transactions` and `compliance_events`
use `$table->id()` (bigint auto-increment, no UUID PK), and `GET /usage`
does not exist yet (grepped `routes/*.php` and
`app/Http/Controllers/*.php` for "usage", zero hits).

Verified: no test-suite changes. README Roadmap gains a Planned entry for
ADR-0029, unchanged otherwise.
