# ADR-0031: Tenant Label Passthrough on `compliance_events`

**Date:** 2026-07-16
**Status:** Proposed — redesigned 2026-07-18 to source `tenant` from Synapse-L4's resolved producer identity rather than a self-reported payload field; blocked on Synapse-L4 ADR-0009 (rewritten) landing first

## Context

ADR-0020 (2026-05-07) decided against implementing multi-tenancy or RBAC in Sentinel-L7, routing that work instead to a separate TypeScript project (`rhizo-book`) for portfolio-audience reasons. That decision stands and is not reopened here.

Since then, two things have changed the shape of the problem:

1. Ledger-L5's ADR-0005 documents a real billing gap: `usage_events` has no `customer_id`, and `rate_cards.customer_id` (Phase 4) has no column to join against, precisely because — per that ADR — "Sentinel-L7 has no customer identity to attribute it to" (citing this repo's own ADR-0020).
2. Xylem-L6's ADR-0006 (Accepted) added an optional `tenant` field to its event schema at the source, and demonstrated it via a real fixture collision. Xylem-L6's ADR-0007 (Accepted) fixed the actual transport: Xylem-L6 integrates as a second producer into **Synapse-L4's `POST /ingest`**, not directly to Sentinel-L7 — correcting ADR-0004's earlier implicit framing, which this repo's own ADR-0031 work had assumed at draft time. ADR-0008 (Accepted) specifies the exact payload Synapse-L4 receives (`source_id`, `status`, `metric_value`, `domain`) — and confirms, by omission, that `tenant` is not currently part of that contract.

This repo's own ADR-0029 was already amended (2026-07-16) in anticipation of this ADR, confirming the exact shape ahead of the column existing: `tenant` (not `customer_id`), nullable, added verbatim to the documented `compliance_events[]` row in `GET /usage`'s response example, carrying no billing-classification meaning of its own — a passthrough correlation label for Ledger-L5, not a filter condition on the endpoint.

What Ledger-L5 needs is not tenant *isolation* — no access-control boundary, no auth changes, no per-tenant infrastructure. It needs a **correlation key**: a value that can flow into `usage_events` so `rate_cards.customer_id` has something to join against. That is a narrower ask than anything ADR-0020 evaluated and rejected.

This applies only to the async/Axiom pipeline (`compliance_events`), which is what the Xylem-L6-via-Synapse-L4 integration populates. The sync `transactions` pipeline (financial events, unrelated source) is out of scope here.

**Confirmed current gap, checked directly against code:** `tenant` does not exist anywhere in Synapse-L4 today — not in `RawTelemetry` (`src/models/axiom.py`), not in `src/api/ingest.py`. Xylem-L6's `POST /ingest` payload (ADR-0008) doesn't send it either. This ADR's Decision below is therefore not actionable until Synapse-L4 ADR-0009 (rewritten to resolve tenant from authenticated producer identity rather than trust a self-reported field) lands.

**Why this ADR changed shape:** the original design (Sentinel-L7 ADR-0031 draft, Xylem-L6 ADR-0008 addendum) had Xylem-L6 self-report its own `tenant` value in the request body. That's fine for a system Xylem-L6 controls end-to-end in a demo, but EventHorizon and Xylem-L6 are customer-owned in the real-world framing this project is targeting — a self-reported field in a payload the producer controls is spoofable by construction, which is a real integrity gap specifically for a system whose job is compliance evaluation. The fix moves tenant resolution to the boundary where producers authenticate, not the payload they send.

## Decision

Add a nullable `tenant` column to `compliance_events`, populated from the **tenant identity Synapse-L4 resolves from the authenticated producer connection** — not from a self-reported field in the event payload. This is a deliberate change from this ADR's earlier draft, made once it became clear that EventHorizon and Xylem-L6 are customer-owned systems: a payload-embedded `tenant` string is self-reported and spoofable by whichever producer sends it, which is a real integrity gap for a compliance-evaluation system specifically. Synapse-L4 ADR-0009 (rewritten) covers the resolution mechanism; this ADR only covers what Sentinel-L7 does with the resolved value once it arrives.

`AxiomProcessorService` needs the same one-line treatment it already gives `domain` — read `$data['tenant'] ?? null` and persist it — since it already reads, persists, and forwards optional fields of this shape. What changes is only the trustworthiness of the value arriving in that field, not how Sentinel-L7 handles it once it's there.

`GET /usage`'s `compliance_events[]` row already documents this field (ADR-0029, amended 2026-07-16) — this ADR is what makes that documentation accurate rather than aspirational.

This does not touch:
- Sentinel-L7's own access model or MCP auth (separate ADR — 0033 in this batch)
- The `transactions` pipeline
- Any WorkOS/Auth0/isolation infrastructure ADR-0020 declined

## Rationale

This is a narrow, explicit override of ADR-0020's *isolation* scope, not a reversal of its reasoning. ADR-0020 evaluated and rejected building tenant-scoped middleware, tenant-prefixed Redis keys, and managed multi-tenant auth (WorkOS Organizations) as unnecessary complexity for a portfolio piece whose audience is TypeScript-role employers. None of that changes here. What's being accepted is a passive label — the kind of field that could sit in `raw_payload` today without requiring any of the infrastructure ADR-0020 was actually weighing.

Ledger-L5 existing as a real forcing function is the legitimate "why now": ADR-0020 never had to weigh a billing service with no key to bill against, because Ledger-L5 didn't exist yet.

## Alternatives Considered

| Option | Pro | Con |
|---|---|---|
| Do nothing; leave the join gap as Ledger-L5's Phase 4 problem | Fully respects ADR-0020 as originally scoped | Ledger-L5 cannot bill per-customer for Sentinel-L7 usage without inventing a correlation key from nothing |
| Full reversal of ADR-0020 (tenant-scoped auth, isolation) | Solves this and any future isolation need at once | Reopens and reverses a decision ADR-0020 made for reasons (portfolio audience targeting) that haven't changed; far more than this problem requires |
| Add `customer_id` directly to `transactions` and `compliance_events` independent of Xylem-L6 | Solves both pipelines' billing gap at once | Sentinel-L7 has no natural source of tenant identity for the `transactions` pipeline today; inventing one here would be exactly the kind of speculative infrastructure ADR-0020 declined |

## Consequences

- Blocked on two not-yet-written changes: an addendum to Xylem-L6 ADR-0008 adding `tenant` to the `POST /ingest` body, and a new Synapse-L4 ADR adding `tenant` to `RawTelemetry` and its extraction path. Neither is authorized by this ADR — both belong in their own repos.
- Only closes the billing-correlation gap for the SaaS-activity (`compliance_events`) slice of usage. The `transactions` pipeline's equivalent gap remains open and unaddressed by this decision.
- No change to `routes/web.php` or `DashboardController`'s existing tenant-scoping placeholder comments (ADR-0020) — those remain honest markers of genuinely deferred, unrelated isolation work.
- `GET /usage`'s documented shape (ADR-0029) is already correct in anticipation of this ADR — no further Ledger-L5-facing contract change needed once the column exists.