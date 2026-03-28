# ADR-0003: Redis Streams for Async Transaction Pipeline

**Date:** 2026-02-05
**Status:** Accepted

## Context

Compliance analysis of transactions involves external API calls (Gemini embedding, Gemini Flash inference, Upstash Vector) with combined latencies of 500ms–2s per transaction. Processing transactions synchronously in the web request would block the dashboard, fail under burst load, and lose messages if the web process crashed mid-analysis.

A queue-based architecture was needed. Options considered:

- **Laravel database queue** — simple, no new infrastructure, but polling-based and uses the same Postgres connection pool as the web process.
- **Laravel Redis queue** — fast, uses Redis pub/sub under the hood, but no consumer group semantics or message replay.
- **Redis Streams (XADD/XREADGROUP/XACK)** — designed for exactly this: persistent ordered log, consumer groups, pending entry list (PEL) for at-least-once delivery, and message replay on failure.

## Decision

Use Redis Streams (`sentinel:transactions`) as the transport layer for the transaction pipeline. Transactions are published via `XADD` and consumed by a dedicated worker process using `XREADGROUP`. A separate reclaimer process uses `XCLAIM` to recover messages that have been in the PEL longer than 60 seconds (zombie messages from crashed workers).

## Consequences

**Positive:**
- At-least-once delivery guaranteed by the PEL — a message is not lost if the worker crashes before `XACK`.
- Consumer groups allow horizontal scaling: multiple worker processes can consume from the same stream without duplicate processing.
- The stream is an ordered, persistent log — useful for auditing and replay.
- Upstash Redis (already required for the vector cache) provides the managed Redis instance, so no additional infrastructure.

**Negative:**
- More operational complexity than a database queue: three processes (web, worker, reclaimer) instead of one.
- `XREADGROUP`/`XACK` semantics must be understood by anyone modifying the worker. A missed `XACK` causes a message to re-enter the PEL and be reprocessed.
- Local development requires all three processes running concurrently (`composer dev-full`).
