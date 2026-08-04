---
id: sentinel-l7-2026-07-09T0915-adr0028-billing-classification-accepted
repo: sentinel-l7
title: "ADR-0028 Finalized: Billing Classification Accepted"
date: 2026-07-09
phase: 20
tags: [billing-classification, adr-status, ledger-l5]
files: [docs/adr/0028-billing-classification-of-fallback-ai-calls.md, database/migrations/2026_04_03_052019_create_transactions_table.php, README.md, docs/journal.md]
---

# ADR-0028 Finalized: Billing Classification Accepted

Decision-only step, no new code — closes out ADR-0028 now that its
prerequisite (Phase 19's idempotency guard) is landed and verified.
`transactions.source IN ('cache_miss', 'driver_override')` is billable,
`source = 'cache_hit'` is the only cache-savings signal, and `fallback`/
`compliance_events.driver_used = 'fallback'` rows are excluded from both
by default — the classification Ledger-L5's Python/FastAPI port will
query against directly, no sentinel-l7 API surface required.

### Decision: Status Proposed → Accepted, First Transition of Its Kind in This Repo
Every other ADR in this repo was written as `Accepted` from the start, or
— for ADR-0015 and ADR-0017, the only other two ever marked `Proposed` —
has sat unresolved for months with no flip. ADR-0028 is the first
`Proposed`→`Accepted` transition this repo has ever recorded. No
mechanical convention existed to follow beyond the plain `Accepted`
string every other ADR already uses; adopted that as-is rather than
inventing new ADR-status ceremony for a one-off.

### Decision: Gate Acceptance on the Idempotency Fix, Not on the ADR's Own Content
The ADR's billing logic itself was sound on first review (see Phase 19's
predecessor journal entries: the pre/post-send cost-ambiguity merge, the
`driver_override`-never-persists-a-row finding). What blocked Accepted
status wasn't the classification rule — it was that the rule's premise
("one row = one billable event") didn't hold yet. Treating a discovered
correctness gap as a hard prerequisite for accepting a *documentation*
decision, rather than accepting the document and filing the gap as a
follow-up TODO, keeps "Accepted" meaning "safe to build on" rather than
"written down."

### Challenges
None in this step specifically — the substantive work (finding and
closing the dedup gap) already happened in Phase 19. This phase is
process/documentation closure: status flip, a stale migration comment
fixed to list all 4 `source` values (`cache_hit`/`cache_miss`/`fallback`/
`driver_override` — confirmed still 4, not 5, since Phase 19's `duplicate`
value is return-shape-only and never persisted), and README Roadmap
graduation (removed from Planned, added citation bullets under
`📦 Persistence & Audit` for both the idempotency guard and the ADR
itself, following the precedent every other graduated Planned item sets
even when — as with ADR-0025's Phase 8 — the graduated step was
decision-only). `LEARNING_LOG.md` and `docs/USER_STORIES.md` were
confirmed to have no applicable update: the former is frozen since
2026-06-06 (superseded by this file), the latter has no precedent for a
standalone external-consumer entry and no local user-facing story to
attach one to.

Verified: no test-suite changes in this step; Phase 19's 332/332 still
holds. Manual check: `docs/adr/0028-*.md` Status line reads `Accepted`;
migration comment lists all 4 values; README Roadmap no longer lists
ADR-0028 under Planned.
