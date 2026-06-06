# ADR-0024: Trace context is a transport-layer concern, not a domain concern

**Date:** 2026-06-06
**Status:** Accepted

## Context

As part of Phase 2 of the OTel observability migration, Sentinel-L7 needs to receive a `traceparent` header from Synapse-L4 via Redis Streams and use it to continue the distributed trace as a child span.

The `traceparent` field is injected by Synapse-L4 onto each `synapse:axioms` stream entry alongside the Axiom business payload. Sentinel-L7 must extract it and start a child span, creating a single trace that spans both services.

## Decision

The `traceparent` is surfaced as a **separate return value** from `AxiomStreamService::parseFields()`, never mixed into the Axiom field map. It is passed to `AxiomProcessorService::process()` as an optional second argument and used only to construct the OTel span parent context. It does **not** flow into:

- The `ComplianceEvent` Eloquent model
- The `ComplianceEvent` database table
- Any application logs
- The return value of `process()`

## Consequences

- `traceparent` does not pollute domain data — the Axiom payload and ComplianceEvent remain clean.
- If `traceparent` is absent (Synapse-L4 ran without an active span, or an older version), `process()` starts a new root span. No failure, no special handling needed.
- Tests that construct `AxiomProcessorService` directly with only a `ComplianceDriver` continue to work — the new optional parameter defaults to noop tracing via `Globals::tracerProvider()`.
- The same principle applies in reverse: Sentinel-L7's outbound publishes (if any) should inject `traceparent` on the stream entry, not inside the JSON payload body.

## Alternatives considered

**Include `traceparent` in `ComplianceEvent`:** Rejected. Trace IDs are ephemeral debugging identifiers. Persisting them in the audit table conflates observability infrastructure with compliance records. Trace IDs rotate per run; the compliance record is permanent.

**Include `traceparent` inside the Axiom Pydantic model (in Synapse-L4):** Rejected (per ADR author — see Phase 1 notes). Same reasoning: business identity vs transport metadata.
