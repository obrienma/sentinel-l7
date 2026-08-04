---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-21, adr, decision]
---
Q: Why was a new ADR-0029 opened for the `GET /usage` cursor contract
instead of amending ADR-0028 in place?

A: The incoming draft conflated two decisions of different maturity: the
billing classification (already Accepted, gated safe by Phase 19's
idempotency fix) and the HTTP delivery mechanism for that data (brand
new, unbuilt, genuinely still open). Amending 0028 in place would have
reverted its Accepted status to Proposed while the same commit still
contained journal and README text asserting Accepted had already
happened — self-contradictory within one commit. Opening 0029 keeps one
ADR per decision, at the maturity level that decision has actually
reached, and cross-references 0028 for classification instead of
restating it.

Extra: sentinel-l7 · Phase 21 · Decision: Split Into a New ADR Instead of Amending 0028
See: docs/journal/sentinel-l7-2026-07-09T0930-usage-endpoint-cursor-contract-adr0029.md

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-21, adr, supersession]
---
ADR-0029 explicitly names its supersession of ADR-0028's
{{c1::"no new instrumentation"}} line — 0028 assumed Ledger-L5 would
query the source tables directly, but 0029 reverses that (Ledger-L5
pulls over {{c2::HTTP}}, per Ledger-L5 ADR-0003) and requires real
endpoint code.

Extra: sentinel-l7 · Phase 21 · Decision: 0029 Explicitly Names Its Supersession of 0028's "No New Instrumentation" Line
See: docs/journal/sentinel-l7-2026-07-09T0930-usage-endpoint-cursor-contract-adr0029.md

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-21, verification]
---
Before accepting the draft, both of its checkable technical claims were
verified against the real schema: `transactions` and `compliance_events`
use {{c1::$table->id()}} (bigint auto-increment, no UUID PK), and
`GET /usage` did not exist yet — confirmed by grepping routes and
controllers for {{c2::"usage"}}, zero hits.

Extra: sentinel-l7 · Phase 21 · Verified the draft's claims rather than accepting them on its own account
See: docs/journal/sentinel-l7-2026-07-09T0930-usage-endpoint-cursor-contract-adr0029.md
