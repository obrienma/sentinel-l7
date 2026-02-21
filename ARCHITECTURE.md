# Sentinel-L7 — Architecture & Design Decisions

This document captures the reasoning behind architectural choices, tradeoffs made during implementation, and known constraints. It is intended for contributors and as a record of intent.

---

## Services Overview

| Class | Responsibility |
|---|---|
| `TransactionStreamService` | Redis Stream producer (`XADD`) and consumer (`XREAD BLOCK`). Idempotency guard via `SETNX`. |
| `EmbeddingService` | Converts a transaction array into a natural-language fingerprint string and calls the Gemini embedding API. |
| `VectorCacheService` | Wraps Upstash Vector REST API: `query` (similarity search) and `upsert`. |
| `ThreatAnalysisService` | Rule-based threat detection. Current implementation is threshold-based; intended to be replaced/augmented by the full `ComplianceManager` + AI driver chain. |

---

## Decision Log

### 1. Why Upstash Vector vs. Pinecone/Weaviate

| Criteria | Upstash | Pinecone | Weaviate |
|----------|---------|----------|----------|
| Serverless Pricing | ✅ Pay-per-query | ❌ Fixed clusters | ⚠️ Hybrid |
| Laravel Integration | ✅ REST + same Upstash account as Redis | ❌ Custom SDK | ❌ HTTP API only |
| Cold Start Latency | <10ms | ~50ms | ~30ms |
| Operational overhead | None (managed) | Low | Medium |

**Decision:** Upstash's REST API is trivially callable with Laravel's `Http` facade, the same account hosts both the Redis stream and the vector index (one vendor, one billing surface), and serverless pay-per-query pricing aligns directly with the semantic cache thesis — you only pay when the cache *misses* and a new vector is stored.

---

### 2. Gemini embedding model: `gemini-embedding-001` on `v1beta`

**Decision:** Use `v1beta/models/gemini-embedding-001:embedContent` with `output_dimensionality: 1536`.

**Context:** `text-embedding-004` was the originally specified model. It is not available on this API key (returns 404 on both `v1` and `v1beta`). `gemini-embedding-001` was confirmed available via `ListModels`. `v1` also returns 404 for this model — it is only served on `v1beta`.

**Tradeoffs:**
- `v1beta` is a pre-release endpoint. If Google promotes the model to `v1` or deprecates `v1beta`, the URL in `EmbeddingService` will need updating.
- `gemini-embedding-001` natively outputs 3072 dimensions. We pin to 1536 via `output_dimensionality` to match the Upstash Vector index. This halves storage and query cost with minimal loss in semantic resolution for the transaction fingerprint use case.
- If a key with access to `text-embedding-004` is used in the future, change the endpoint to `v1` and adjust `output_dimensionality` to match the index.

**Config:** `config/services.php` → `services.gemini.api_key` ← `GEMINI_API_KEY`.

---

### 3. Transaction fingerprint design

**Decision:** Convert the transaction array to a pipe-delimited natural-language string before embedding, rather than embedding raw JSON.

```
Amount: 12.50 USD | Type: purchase | Category: coffee | Time: 09:14 | Merchant: Starbucks
```

**Rationale:** Embedding models are trained on natural language. Raw JSON structure (key names, brackets, quotes) adds noise that dilutes semantic signal. A sentence-like fingerprint produces more meaningful cosine similarity comparisons — a $12.50 coffee and a $13.00 coffee will cluster together, while a $12.50 wire transfer will not.

**Tradeoff:** The fingerprint loses some precision (e.g., exact timestamp is reduced to HH:MM). This is intentional — two transactions at 09:14 and 09:15 should share an analysis; second-level precision would prevent cache hits on legitimately similar events.

---

### 4. Upstash Vector response envelope

**Decision:** Use `$response->json('result')` rather than `$response->json()` to extract query results.

**Context:** The Upstash Vector REST API wraps query results in a `{"result": [...]}` envelope. Accessing the bare response as an array and trying `$results[0]` causes `Undefined array key 0`. This is not documented prominently and was discovered at runtime.

**Note:** If Upstash changes their response shape in a future API version, `json('result')` will return null on key-not-found and `empty(null)` is true, so the behavior degrades to all cache misses rather than crashing.

---

### 5. Similarity threshold: 0.95

**Decision:** Cache hits require cosine similarity ≥ 0.95.

**Rationale:** Financial and medical analysis requires high confidence that a cached result is applicable. A 0.90 threshold might reuse a "low risk" report for a transaction that differs in a meaningful way. 0.95 is conservative enough to require near-identical semantic patterns while still capturing high-frequency repeats (same merchant, similar amount range) that make caching valuable.

**Config:** `config/services.php` → `services.upstash_vector.similarity_threshold` ← `UPSTASH_VECTOR_THRESHOLD` (defaults to `0.95`).

---

### 6. Fallback architecture in `WatchTransactions`

**Decision:** Wrap the entire vector path (fingerprint → embed → search) in `try/catch (\Throwable $e)`. On failure, fall through to direct `ThreatAnalysisService::analyze()`.

**Rationale:** `sentinel:watch` is a long-running daemon. A transient API error (Gemini rate limit, Upstash downtime, network blip) must not kill the process. The fallback ensures the security function (threat detection) continues even when the performance optimization (semantic cache) is unavailable.

**Observability:** Three separate metric buckets are written to Redis on every transaction:
- `sentinel_metrics_cache_hit_count` / `sentinel_metrics_cache_hit_time`
- `sentinel_metrics_cache_miss_count` / `sentinel_metrics_cache_miss_time`
- `sentinel_metrics_fallback_count` / `sentinel_metrics_fallback_time`

Time values are stored in milliseconds as integers via `Cache::increment`. A spike in `fallback_count` signals that the vector path is degraded.

---

### 7. Redis idempotency guard (`idemp:{uuid}`)

**Decision:** Before each `XADD`, perform `SET idemp:{uuid} processed EX 86400 NX`. If the key exists, skip the publish.

**Rationale:** In production, transaction events may arrive via retried HTTP calls, replayed webhooks, or a crashed producer restarting mid-batch. The idempotency key prevents the same logical event from being analyzed twice.

**Current state:** The simulator yields a fresh `Str::uuid()` on every iteration, so the guard is never triggered during local development. It is correctly placed and will be exercised by real ingestion paths.

**TTL:** 24 hours. Covers the maximum realistic replay window for financial systems. Adjust downward for lower storage cost if the upstream system guarantees no replays beyond a shorter window.

---

### 8. `ThreatResult` serialization into vector metadata

**Decision:** When upserting to the vector cache after a cache miss, `ThreatResult` (a PHP value object) is serialized manually to an array:

```php
'analysis' => [
    'isThreat'     => $result->isThreat,
    'message'      => $result->message,
    'threat_level' => $result->isThreat ? 'high' : 'low',
]
```

**Rationale:** PHP objects are not JSON-serializable. The vector metadata must survive a round-trip through Upstash (JSON over HTTP) and be reconstructable on cache hit without access to the original class. The flat array is sufficient — the watcher only needs `isThreat` and `message` to display output and trigger alerts.

---

### 9. Cross-terminal observability

**Decision:** Both `sentinel:stream` and `sentinel:watch` output the transaction UUID on every line.

Stream:
```
Streamed: [3f2c1a...] Starbucks | USD 12.50
```
Watcher:
```
──── TXN 3f2c1a...
     Starbucks | USD 12.50
✅ Cache hit [8ms] — matched txn_9e4b... (similarity: 97.3%)
```

**Rationale:** Running both commands in split terminals is the standard local development workflow. Without the UUID in both outputs there is no way to trace a specific transaction from publish through analysis. The watcher also shows the matched vector ID on cache hits so you can correlate which historical transaction the current one was considered similar to.

---

## Known Constraints & Future Work

| Area | Constraint | Planned |
|---|---|---|
| `ThreatAnalysisService` | Rule-based (amount threshold only) — not the full AI reasoning chain | Replace with `ComplianceManager` + `GeminiDriver` for policy-grounded analysis |
| `SentinelConsume.php` | Empty placeholder | XREADGROUP-based consumer with XCLAIM recovery worker |
| Embedding model | `gemini-embedding-001` on `v1beta` | Migrate to `text-embedding-004` on `v1` if/when available on this key |
| Vector index dimension | Pinned to 1536 via `output_dimensionality` | If index is recreated, dimension must match; update both Upstash index config and `EmbeddingService` |
| Metrics | Raw Redis counters only | Expose via dashboard endpoint; add percentile tracking |
| Idempotency | Not exercised by simulator | Wire up to real ingestion path; add test that publishes same UUID twice |
| Rate Limiting | Volumetric only (fixed tokens per request) | Semantic Rate Limiting: Adjust token cost based on the "intent" or data-value of the request identified by the vector cache. |
| Feature Gating | Manual `.env` checks | Implement a centralized `FeatureManager` service to handle conditional UI and API route registration. |
