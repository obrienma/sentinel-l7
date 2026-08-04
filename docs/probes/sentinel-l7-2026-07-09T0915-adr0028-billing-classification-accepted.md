---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-20, adr-status]
---
ADR-0028 is the first {{c1::Proposed}} → {{c2::Accepted}} transition ever
recorded in this repo; ADR-0015 and ADR-0017 are the only other two ADRs
ever marked Proposed, and both have sat unresolved for months with no
flip.

Extra: sentinel-l7 · Phase 20 · Decision: Status Proposed → Accepted, First Transition of Its Kind in This Repo
See: docs/journal/sentinel-l7-2026-07-09T0915-adr0028-billing-classification-accepted.md

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-20, decision, billing-classification]
---
Q: Why was ADR-0028's acceptance gated on Phase 19's idempotency fix
rather than on the billing-classification content itself?

A: The classification rule was already sound on review — what blocked
Accepted status was that its premise ("one row = one billable event")
didn't hold until the dedup gap was closed. Treating a discovered
correctness gap as a hard prerequisite for accepting a documentation
decision — rather than accepting the document and filing the gap as a
follow-up TODO — keeps "Accepted" meaning "safe to build on," not merely
"written down."

Extra: sentinel-l7 · Phase 20 · Decision: Gate Acceptance on the Idempotency Fix, Not on the ADR's Own Content
See: docs/journal/sentinel-l7-2026-07-09T0915-adr0028-billing-classification-accepted.md

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-20, billing-classification, ledger-l5]
---
Under ADR-0028, `transactions.source IN ({{c1::cache_miss}}, {{c2::driver_override}})`
is billable; `source = {{c3::cache_hit}}` is the only cache-savings
signal; `fallback` rows are excluded from both by default.

Extra: sentinel-l7 · Phase 20 · ADR-0028 billing classification, queried directly by Ledger-L5
See: docs/journal/sentinel-l7-2026-07-09T0915-adr0028-billing-classification-accepted.md

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-20, migration-comment]
---
The stale migration comment fix confirmed the `source` column still has
exactly {{c1::4}} distinct values — Phase 19's `duplicate` value is
return-shape-only and is {{c2::never persisted}}, so it doesn't become a
5th value in the comment or the ADR.

Extra: sentinel-l7 · Phase 20 · Process/documentation closure
See: docs/journal/sentinel-l7-2026-07-09T0915-adr0028-billing-classification-accepted.md
