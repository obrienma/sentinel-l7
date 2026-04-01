# ADR 0016 — Synapse-L4 Axiom Ingestion

**Date:** 2026-03-31
**Status:** Accepted

---

## Context

Synapse-L4 is a Python/FastAPI sidecar that sits between EventHorizon (TypeScript telemetry pipeline) and Sentinel-L7. It validates raw telemetry through an LLM extraction + Judge pass and emits a typed, immutable **Axiom**:

```json
{
  "status": "critical",
  "metric_value": 94.0,
  "anomaly_score": 0.91,
  "source_id": "sensor-42",
  "emitted_at": "2026-03-31T14:22:11Z"
}
```

Sentinel-L7 needs to receive these Axioms and route them into its existing pipeline.

---

## Decision

### 1. Ingestion mechanism — new stream `synapse:axioms`

**Chosen: Option A** — new Redis stream key `synapse:axioms` with its own consumer group.

Axioms are typed, validated, immutable events produced by a separate system. They are not raw financial transactions and must not share the `transactions` stream, which is consumed by AML pattern detectors that expect transaction-shaped data. A dedicated stream key gives Synapse-L4 a clean write target and lets Sentinel-L7 consume it with a separate worker process (`sentinel:watch-axioms`) without any risk of conflation or cross-contamination.

HTTP POST (Option C) was ruled out: it is synchronous, blocks the Synapse-L4 emitter, and loses at-least-once delivery guarantees that Redis Streams provide.

### 2. Routing `anomaly_score` to audit narrative generation

High-score Axioms (`anomaly_score > AXIOM_AUDIT_THRESHOLD`, default `0.8`) are routed directly to `ComplianceDriver::analyze()` to generate an AI-justified audit narrative via Gemini Flash with policy RAG context.

A behavioral drift detector (buffering + pattern detection across multiple Axioms) was considered but deferred — it requires a subsystem that does not yet exist. Direct routing to `ComplianceManager` delivers value immediately and can be layered under a drift detector later without breaking the interface.

`AXIOM_AUDIT_THRESHOLD` is a new env var (not reusing any existing threshold) because Axiom anomaly scores are dimensionally different from transaction amounts or vector similarity scores.

Sub-threshold Axioms are persisted to the database but do not trigger AI analysis.

### 3. `source_id` correlation back to EventHorizon

`source_id` is stored as a column on the `compliance_events` Postgres table alongside the full Axiom payload, routing outcome, and audit narrative. This creates a persistent audit trail that operators can use to correlate Sentinel-L7 findings back to the originating EventHorizon event.

The field is indexed to support efficient lookups by source.

---

## Implementation

| Component | Location |
|-----------|---------|
| `compliance_events` migration | `database/migrations/` |
| `ComplianceEvent` model | `app/Models/ComplianceEvent.php` |
| `ComplianceDriver` contract | `app/Contracts/ComplianceDriver.php` |
| `GeminiDriver` | `app/Services/Compliance/GeminiDriver.php` |
| `OpenRouterDriver` (stub) | `app/Services/Compliance/OpenRouterDriver.php` |
| `ComplianceManager` | `app/Services/ComplianceManager.php` |
| `AxiomStreamService` | `app/Services/AxiomStreamService.php` |
| `AxiomProcessorService` | `app/Services/AxiomProcessorService.php` |
| `WatchAxioms` command | `app/Console/Commands/WatchAxioms.php` |

New env vars:
```
AXIOM_AUDIT_THRESHOLD=0.8
SENTINEL_AI_DRIVER=gemini
```

---

## Consequences

- [x] New stream key `synapse:axioms` defined — separate from `transactions`
- [x] Consumer worker `sentinel:watch-axioms` implemented
- [x] `anomaly_score` threshold documented and added to `.env.example`
- [x] `source_id` stored in `compliance_events` (Postgres) for audit trail
- [ ] Synapse-L4 `src/clients/sentinel.py` — emitter side to be implemented in the Synapse-L4 repo
- [ ] XCLAIM recovery for `synapse:axioms` consumer group (same pattern as `sentinel:reclaim` for transactions)
- [ ] OpenRouterDriver stub to be fully implemented when `SENTINEL_AI_DRIVER=openrouter` is needed
