# Engineering Journal — Sentinel-L7

Per-phase engineering journal. Typed, vocabulary-enforced sections (see
`~/.claude/skills/journal-anki.md`). Paired Anki probes live in
`docs/probes/phase-N-<name>.md`. Migrated from `LEARNING_LOG.md`.

---

## Phase 1 — Stream Simulator (`sentinel:stream`) — 2026-02-09 – 2026-02-19
Commits: `9697431`, `d0d8375`
Files: app/Console/Commands/StreamTransactions.php, app/Services/TransactionStreamService.php, config/sentinel.php, tests/Feature/StreamTransactionsTest.php, tests/Unit/TransactionStreamServiceTest.php

Built `sentinel:stream`, an Artisan command that seeds Redis Streams with
synthetic transactions for the downstream compliance pipeline to consume.

### Pattern: Graceful Shutdown via Signal-Flag Polling
`StreamTransactions` registers `pcntl_signal` handlers for `SIGINT`/`SIGTERM`
that flip a `$running` boolean rather than calling `exit()` directly. The
`while ($running)` loop tests the flag once per iteration, so an in-flight
`XADD` completes before the process exits. This is cooperative cancellation:
the signal marks intent, the loop chooses a safe point to honour it, which is
what prevents a mid-write tear on CTRL-C.

### Pattern: Idempotency Guard via `SETNX` Before Stream Writes
Before `XADD`, `TransactionStreamService::publish()` issues
`SETNX sentinel:seen:{id}` with a 24h TTL. An already-present key means the
transaction was published before, so the write is skipped and `false` returned.
The guard lives in a dedicated key namespace, keeping the dedup check O(1) and
independent of stream length — the producer-side complement to an idempotent
receiver downstream.

### Anti-Pattern Avoided: Scattered Magic Stream Keys
The tempting shortcut is to inline the `sentinel:transactions` string wherever
a command needs it. Instead the key is a single class constant on
`TransactionStreamService`, read only there; commands obtain it through the
service. Single source of truth — a later rename is one edit, not a grep across
every command file.

### Challenge: `pcntl` Extension Silently Absent
Symptom: CTRL-C killed the process abruptly with no clean loop exit. Root
cause: `pcntl_signal` requires the `pcntl` extension enabled; when it is not,
handler registration is a silent no-op — no error, the signal just falls
through to default SIGINT termination. Fix: confirmed the extension present in
the Render build (it was) and locally. The failure mode is insidious precisely
because nothing throws.

### Decision: `--limit` / `--speed` as Tunable Options
`--limit=10` (transactions per run) and `--speed=1000` (ms between writes) are
command options with conservative defaults that won't spam a dev Redis. Pushing
`--limit=100 --speed=100` enables load testing with no code change — keeping
the knobs out of code and in the invocation.

---

## Phase 2 — Real-time Watcher + Threat Analysis (`sentinel:watch`) — 2026-02-18
Commits: `36ed585`
Files: app/Console/Commands/WatchTransactions.php, app/Services/ThreatAnalysisService.php, tests/Unit/ThreatAnalysisServiceTest.php

Added `WatchTransactions` (an infinite `XREAD` loop) and
`ThreatAnalysisService` (a rule-based L7 compliance checker) — the first
end-to-end pipeline: stream producer → Redis → consumer → threat verdict → CLI
output.

### Pattern: Tier-3 Fallback as a Pure Rule-Based Service
`ThreatAnalysisService` is the tier-3 fallback: amount-threshold and
category-flag rules implemented in pure PHP with no network calls. Because it
performs no I/O, it cannot throw on a downstream outage and always returns a
verdict — the property that lets `TransactionProcessorService` fall back to it
unconditionally when embedding or vector search fails.

### Pattern: Cursor-Based Stream Consumption
`WatchTransactions` tracks a `$lastId` cursor passed to every `XREAD` call.
The first call uses `$` (only new messages from this point forward); every
subsequent call passes the previously-received message ID. This guarantees the
consumer advances monotonically through the stream and never reprocesses a
message within the session.

### Anti-Pattern Avoided: Unbounded Replay from Stream Start
An early version polled `XREAD` from `0` every iteration, replaying the entire
stream history on each loop. The failure mode is silent duplication — no error,
just every historical transaction reprocessed repeatedly, surfacing as
duplicate CLI output. Cursor-based reads (above) eliminate it.

### Decision: `analyze()` Returns a Value Object, Not an Array
`ThreatAnalysisService::analyze()` returns a plain PHP object with public
`$isThreat` and `$message` properties rather than an associative array. This
gives call sites named-property access (`$result->isThreat`) without the
overhead of a formal class hierarchy — the minimal step up from an array that
still buys typo-safety at call sites.

---

## Phase 3 — Semantic Cache — Vectorize — 2026-02-20
Commits: `391b8c3`, `1511fbc`
Files: app/Console/Commands/StreamTransactions.php, app/Console/Commands/WatchTransactions.php, app/Services/EmbeddingService.php, app/Services/VectorCacheService.php, config/services.php, tests/Feature/WatchTransactionsTest.php, tests/Unit/EmbeddingServiceTest.php, tests/Unit/VectorCacheServiceTest.php

Added `EmbeddingService` (Gemini `embedding-001`, 1536-dim) and
`VectorCacheService` (Upstash Vector REST API), wired into
`WatchTransactions`: embed fingerprint → vector search → cache hit returns
early; miss → `ThreatAnalysisService` → upsert result. First significant Pest
suite (615 lines across 3 files).

### Pattern: Semantic Fingerprint as Cache Key
The cache key is not the transaction ID but a text fingerprint of semantic
fields (`Amount | Type | Category | Time | Merchant`), embedded to a vector.
Two transactions with different IDs but the same risk profile land within the
similarity threshold and share a cache entry — this is the defining property
of a semantic cache, as distinct from an exact-match key/value cache.

### Pattern: Tiered Fallback Pipeline (Graceful Degradation)
The pipeline (later extracted into `TransactionProcessorService`) has three
tiers: (1) vector cache hit → return stored verdict, (2) cache miss → Gemini
Flash analysis → upsert, (3) any exception in tier 1 or 2 → rule-based
`ThreatAnalysisService`. This is graceful degradation — each tier is a fallback
for the one above it, and every transaction receives a verdict; none is
silently dropped. See the pipeline diagram in
`docs/probes/phase-3-semantic-cache-vectorize.md`.

### Pattern: `Cache::increment` for Zero-Schema Metrics
Hit count, miss count, fallback count, and cumulative latency are tracked via
`Cache::increment('sentinel_metrics_*')` — plain Redis key/value pairs, no
metrics table or time-series DB. Fast, schema-free, and resettable with
`php artisan sentinel:reset-metrics`.

### Anti-Pattern Avoided: Unwrapping the Upstash Response Envelope Incorrectly
The initial `VectorCacheService` read `$response->json('results')` (plural).
Upstash Vector wraps query results under `result` (singular) —
`{"result": [{"id": ..., "score": ...}]}`. Reading the wrong key doesn't error;
it silently returns null, which looks identical to "no match found" and masked
the bug.

### Anti-Pattern Avoided: Exact Timestamps in the Fingerprint
The original fingerprint embedded `date('H:i', ...)` — an exact `HH:MM`. Two
semantically identical transactions a minute apart produced different vectors
and never shared a cache entry. Replaced (later phase) with time-of-day
buckets (night/morning/afternoon/evening), preserving compliance-relevant time
context without destroying the hit rate. See ADR-0001.

### Challenge: Gemini Embedding Dimension Mismatch
Symptom: Upstash returned a 400 on upsert. Root cause: Upstash Vector
namespaces have a fixed dimension set at creation time; an early namespace was
created at 768 dims (Gemini `embedding-001`'s default), but the service was
later configured for `"output_dimensionality": 1536`. Inserting 1536-dim
vectors into a 768-dim namespace is rejected outright. Fix: delete and recreate
the namespace at 1536 dims.

### Challenge: `Http::fake()` Ordering Relative to Instantiation
Symptom: fakes weren't intercepting requests in early `EmbeddingServiceTest`
runs. Root cause: `Http::fake([...])` was called *after* `new
EmbeddingService()`. `Http::fake()` swaps the client at the facade level — a
service constructed first may already hold a reference to the real client.
Fix: call `Http::fake()` before any `new` calls in the test.

### Decision: Similarity Threshold of 0.95 for the Transaction Cache
Set conservatively high to avoid false positives: a transaction that's merely
*similar* to a cached one could still differ in compliance-relevant ways, and
returning a stale verdict for a real threat is a worse outcome than a cache
miss (just a redundant AI call). ADR-0015 documents this and tracks an
empirical evaluation of lowering it to 0.90.

---

## Phase 4 — Service Hardening — Retries, Timeouts, Observability — 2026-02-27
Commit: `45301d8`
Files: app/Services/EmbeddingService.php, app/Services/VectorCacheService.php, config/services.php, docs/sprintPlan.md, tests/Feature/WatchTransactionsTest.php, tests/Unit/EmbeddingServiceTest.php, tests/Unit/VectorCacheServiceTest.php

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
