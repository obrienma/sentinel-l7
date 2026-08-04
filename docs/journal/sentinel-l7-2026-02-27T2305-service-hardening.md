---
id: sentinel-l7-2026-02-27T2305-service-hardening
repo: sentinel-l7
title: "Service Hardening — Retries, Timeouts, Observability"
date: 2026-02-27
phase: 4
tags: [retry-with-backoff, observability, head-of-line-blocking, timeouts]
files: [app/Services/EmbeddingService.php, app/Services/VectorCacheService.php, config/services.php, docs/sprintPlan.md, tests/Feature/WatchTransactionsTest.php, tests/Unit/EmbeddingServiceTest.php, tests/Unit/VectorCacheServiceTest.php]
---

# Service Hardening — Retries, Timeouts, Observability

Commit: `45301d8`

Added retry-with-backoff (3× @ 200ms for embedding, 2× @ 150ms for vector),
explicit HTTP timeouts (10s / 5s), `Log::warning` on all failure paths, and
`VectorCacheService::delete()` for cache eviction. Test suite doubled
(34 → 76 tests).

### Pattern: Retry with Fixed Delay Before Tier-3 Fallback
`EmbeddingService::embed()` retries 3× with a fixed 200ms delay;
`VectorCacheService` retries 2× at 150ms. This is fixed-delay retry, not
exponential backoff — the delays don't grow between attempts, which is
appropriate here because the consumer loop is synchronous and a long backoff
would itself become a latency problem. On exhaustion the exception propagates
to the pipeline's try/catch and triggers the tier-3 fallback.

### Pattern: `Log::warning` as the Observability Hook for Every Failure Path
Every catch block on a transient failure path logs a warning with structured
context (service name, error message) rather than letting the exception
propagate silently into the fallback. This produces a searchable trail in
Railway's log drain — logs are the primary signal here; there is no dedicated
APM.

### Anti-Pattern Avoided: Head-of-Line Blocking from Unbounded HTTP Calls
The initial `EmbeddingService` and `VectorCacheService` called `Http::post()`
with no timeout. In the single-threaded `while(true)` consumer loop, a hung
Gemini or Upstash connection is head-of-line blocking: one stuck request
blocks every transaction queued behind it, indefinitely. Fixed with
`->timeout(10)` (embedding) and `->timeout(5)` (vector).

### Challenge: Testing Retries Without Real Sleep
Retry tests need the service to fail N-1 times and succeed on attempt N.
Using real `sleep()` would make the suite slow. Fix: `Http::sequence()`
returns a series of fake responses — failures first, then success — so the
service retries against the sequence with no real network or sleep involved.

### Decision: Speculative `VectorCacheService::delete()`
No current caller needs `delete()`, but it's required for cache invalidation
when a compliance policy changes — every cached verdict under the old policy
becomes stale and must be evictable. Added during hardening rather than
deferred until the invalidation use case arrives, since the method is small
and the absence would block that future work entirely.
