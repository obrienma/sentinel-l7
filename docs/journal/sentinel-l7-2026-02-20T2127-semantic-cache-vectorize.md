---
id: sentinel-l7-2026-02-20T2127-semantic-cache-vectorize
repo: sentinel-l7
title: "Semantic Cache — Vectorize"
date: 2026-02-20
phase: 3
tags: [semantic-cache, graceful-degradation, upstash-vector, embeddings, similarity-threshold]
files: [app/Console/Commands/StreamTransactions.php, app/Console/Commands/WatchTransactions.php, app/Services/EmbeddingService.php, app/Services/VectorCacheService.php, config/services.php, tests/Feature/WatchTransactionsTest.php, tests/Unit/EmbeddingServiceTest.php, tests/Unit/VectorCacheServiceTest.php]
---

# Semantic Cache — Vectorize

Commits: `391b8c3`, `1511fbc`

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
`docs/probes/sentinel-l7-2026-02-20T2127-semantic-cache-vectorize.md`.

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
