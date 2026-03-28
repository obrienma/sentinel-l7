# ADR-0010: Neon PostgreSQL — Non-Pooled Host for Queue Worker

**Date:** 2026-03-09
**Status:** Accepted

## Context

Neon provides two PostgreSQL connection endpoints: a direct (non-pooled) host and a PgBouncer pooler endpoint. The pooler operates in **transaction mode**, which strips support for session-level features including `SELECT ... FOR UPDATE SKIP LOCKED` — the mechanism Laravel's database queue driver uses to atomically claim jobs.

When the queue worker was pointed at the pooler endpoint, every job reservation produced `SQLSTATE[25P02]: In failed sql transaction` and the worker retried endlessly without successfully processing any job.

## Decision

`DB_HOST` is set to the non-pooled Neon endpoint. All database connections — web process, queue worker, and migrations — use the direct host.

## Consequences

**Positive:**
- Queue worker functions correctly. `SELECT ... FOR UPDATE SKIP LOCKED` works as expected.
- Simpler configuration: one host, no per-process overrides.

**Negative:**
- Neon free tier allows ~5 direct connections. For local development (1 web + 1 queue worker = 2 connections) this is not a concern. Under sustained load with multiple workers, direct connection limits would be hit first.
- In production on Railway, `DB_HOST` is set via the Railway dashboard env — the `.env` file value is irrelevant there. Both environments must be configured correctly and independently.
- The pooler remains the correct choice for the web process under high concurrency. If this becomes a concern, the override would need to be applied per-process rather than globally.
