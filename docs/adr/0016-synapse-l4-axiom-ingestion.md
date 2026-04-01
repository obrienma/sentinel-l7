# ADR 0016 — Synapse-L4 Axiom Ingestion

**Date:** TBD
**Status:** TODO — stub, pending implementation of Synapse-L4 emitter (Phase 4)

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

Sentinel-L7 needs to receive these Axioms and route them into its existing pipeline. Decisions required before implementation:

---

## Open Decisions

### 1. Ingestion mechanism — new stream vs. existing stream

**Option A:** New Redis stream key `synapse:axioms`
- Clean separation — Axioms are not raw transactions
- Requires a new consumer group and worker branch in Sentinel-L7
- Synapse-L4 writes via `XADD synapse:axioms * status critical metric_value 94.0 ...`

**Option B:** Existing transaction stream with an `axiom` event type
- Reuses existing consumer infrastructure
- Risks conflating telemetry Axioms with financial transaction events
- May confuse the existing AML pattern detectors

**Option C:** HTTP POST to a new Sentinel-L7 endpoint
- Simplest integration — no Redis client needed in Synapse-L4
- Synchronous — Synapse-L4 blocks until Sentinel-L7 acknowledges
- Loses at-least-once delivery guarantees of Redis Streams

**Leaning toward Option A** — document rationale when decided.

---

### 2. Routing `anomaly_score` to audit narrative generation

High `anomaly_score` (threshold TBD — likely > 0.8) should trigger Sentinel-L7's existing `ComplianceDriver::analyze()` flow to generate an AI-justified audit narrative. Questions:

- Does the Axiom route through `ComplianceManager` directly, or does it feed the behavioral drift detector first?
- Should the `anomaly_score` threshold be a new env var (e.g. `AXIOM_AUDIT_THRESHOLD`) or reuse an existing threshold?

---

### 3. `source_id` correlation back to EventHorizon

The Axiom's `source_id` matches the `source_id` field on the originating EventHorizon event. Sentinel-L7 may want to store this for audit trail correlation. Decision: store as a metadata field on the compliance record, or ignore for now?

---

## Consequences (fill in when decided)

- [ ] New stream key or endpoint defined
- [ ] Consumer group / route handler implemented
- [ ] `anomaly_score` threshold documented and added to `.env.example`
- [ ] `source_id` handling decided
- [ ] Synapse-L4 `src/clients/sentinel.py` implementation aligned with this decision
