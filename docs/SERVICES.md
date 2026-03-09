# Services

Detailed reference for the service classes that make up the Sentinel-L7 pipeline.

---

## EmbeddingService

**File:** `app/Services/EmbeddingService.php`
**External dependency:** Gemini Embedding API (`gemini-embedding-001`)

Two responsibilities:

### 1. `createTransactionFingerprint(array $transaction): string`

Converts a raw transaction array into a human-readable pipe-delimited string. This is the text that gets embedded — not the JSON blob itself.

```
Amount: 499.00 USD | Type: PURCHASE | Category: RETAIL | Time: 14:32 | Merchant: COSTCO
```

The fingerprint is deterministic for identical inputs, which is what makes semantic caching work — similar transactions produce similar fingerprints, which produce similar vectors, which hit the cache.

### 2. `embed(string $text): array`

POSTs the fingerprint to the Gemini embedding API and returns a 1536-dimensional float vector.

- Timeout: 10s
- Retries: 3 attempts, 200ms delay
- Throws `RuntimeException` on non-2xx after retries
- Logs a warning on failure

**Config keys read:**
```
services.gemini.api_key       → GEMINI_API_KEY
services.gemini.embedding_url → GEMINI_EMBEDDING_URL (optional, has default)
```

**Usage:**
```php
$embedding = app(EmbeddingService::class);

$fingerprint = $embedding->createTransactionFingerprint($transaction);
// → "Amount: 499.00 USD | Type: PURCHASE | ..."

$vector = $embedding->embed($fingerprint);
// → [0.023, -0.441, 0.119, ...] (1536 floats)
```

---

## VectorCacheService

**File:** `app/Services/VectorCacheService.php`
**External dependency:** Upstash Vector REST API

Wraps three Upstash Vector endpoints. All methods retry on transient failure and log warnings on error rather than throwing.

### `search(array $embedding, int $topK = 3): ?array`

Queries the vector index for similar vectors. Returns the best match if it meets the similarity threshold, or `null` on a miss or failure.

```php
$result = app(VectorCacheService::class)->search($vector);

if ($result) {
    // Cache hit
    $cached = $result['metadata']['analysis'];
    $score  = $result['score'];   // cosine similarity, e.g. 0.97
    $id     = $result['id'];      // e.g. "txn_abc123"
}
```

The threshold is read from `services.upstash_vector.similarity_threshold` (default `0.95`, set via `UPSTASH_VECTOR_THRESHOLD`). To use a different threshold or namespace, subclass or extend.

### `upsert(string $id, array $embedding, array $metadata): bool`

Stores a vector with arbitrary metadata. Returns `true` on success, `false` on failure.

```php
$vectorCache->upsert(
    "txn_{$txnId}",
    $vector,
    [
        'analysis' => [
            'isThreat'     => $result->isThreat,
            'message'      => $result->message,
            'threat_level' => $result->isThreat ? 'high' : 'low',
        ],
        'timestamp'    => now()->toIso8601String(),
        'threat_level' => $result->isThreat ? 'high' : 'low',
    ]
);
```

### `delete(string $id): bool`

Deletes a vector entry by ID. Useful for invalidating stale cached analyses.

**Config keys read:**
```
services.upstash_vector.url    → UPSTASH_VECTOR_REST_URL
services.upstash_vector.token  → UPSTASH_VECTOR_REST_TOKEN
services.upstash_vector.similarity_threshold → UPSTASH_VECTOR_THRESHOLD (default: 0.95)
```

> **Namespace note:** The current `VectorCacheService` uses the Upstash default namespace. The dual-namespace strategy (`ns:default` for cache, `ns:policies` for RAG) is documented in [ARCHITECTURE.md](ARCHITECTURE.md) and [diagrams/RAG_PIPELINE.md](diagrams/RAG_PIPELINE.md) — namespace support is a planned extension.

---

## ThreatAnalysisService + ThreatResult

**File:** `app/Services/ThreatAnalysisService.php`

The **current** rule-based compliance engine. Flags a transaction as a threat if its amount exceeds the configured threshold.

> This is the **Tier 3 fallback** in the planned three-tier pipeline — simple, zero-latency, zero-cost. The full AI pipeline (Gemini Flash + policy RAG) will be Tier 2, sitting above this. See [AI_PIPELINE.md](AI_PIPELINE.md).

### `analyze(array $transaction): ThreatResult`

```php
$analyzer = app(ThreatAnalysisService::class);
$result = $analyzer->analyze($transaction);

$result->isThreat    // bool
$result->message     // string — human-readable verdict
$result->transaction // array — original input, passed through
```

**Config key read:**
```
sentinel.thresholds.high_risk → default 400.00
```

### ThreatResult

A simple value object (two static constructors, readonly properties). Defined in the same file as `ThreatAnalysisService`.

```php
ThreatResult::threat($message, $transaction)  // isThreat = true
ThreatResult::clear($transaction)             // isThreat = false
```

---

## WatchTransactions (`sentinel:watch`)

**File:** `app/Console/Commands/WatchTransactions.php`
**Run:** `php artisan sentinel:watch`

The current stream consumer. Reads from the Redis stream and runs each transaction through the embedding + vector cache pipeline, falling back to `ThreatAnalysisService` if the vector path fails.

**Flow per transaction:**

```
XREAD from stream
  → createTransactionFingerprint()
  → embed() → vector
  → VectorCacheService::search()
      ├─ Hit  → reuse cached analysis + recordMetric('cache_hit')
      └─ Miss → ThreatAnalysisService::analyze()
                 → VectorCacheService::upsert()
                 → recordMetric('cache_miss')
  (any exception) → ThreatAnalysisService::analyze() directly + recordMetric('fallback')
```

**Metrics** are stored in Laravel's cache (backed by Upstash Redis via `CACHE_STORE=redis`):

```
sentinel_metrics_cache_hit_count
sentinel_metrics_cache_miss_count
sentinel_metrics_fallback_count
sentinel_metrics_threat_count
sentinel_metrics_cache_hit_time    (ms, accumulated)
sentinel_metrics_cache_miss_time
sentinel_metrics_fallback_time
```

**Transaction feed** — after every transaction, a JSON summary is pushed to a Redis list:

```
sentinel:recent_transactions   (LPUSH, capped at 50 via LTRIM)
```

Each entry:
```json
{
  "id": "txn_abc123",
  "merchant": "COSTCO",
  "amount": "499.00",
  "currency": "USD",
  "is_threat": true,
  "message": "High value transaction at COSTCO ($499.00)",
  "source": "cache_hit | cache_miss | fallback",
  "at": "2026-03-09T14:32:00+00:00"
}
```

`DashboardController` reads this list via `LRANGE sentinel:recent_transactions 0 19` and passes it to the React dashboard as `recentTxns`.

> **Note:** `sentinel:watch` uses `XREAD` (no consumer group). The planned `sentinel:consume` command will use `XREADGROUP` + `XACK` for fault-tolerant at-least-once delivery with a reclaimer process. See [ARCHITECTURE.md](ARCHITECTURE.md).

---

## Feature Flags (`config/features.php`)

**File:** `config/features.php`
**Docs:** See [FEATURE_FLAGS.md](../FEATURE_FLAGS.md) for the full reference.

Flags default **off in production, on everywhere else**. Override any flag in `.env`.

```php
// In PHP
config('features.dashboard_access')  // bool
config('features.env_badge')         // bool
config('features.app_env')           // not in config — comes from HandleInertiaRequests
```

```jsx
// In React (via Inertia shared props)
import { usePage } from '@inertiajs/react';

const { features } = usePage().props;
features.dashboard_access  // bool
features.env_badge         // bool
features.app_env           // 'local' | 'staging' | 'production'
```

**Adding a new flag:**

1. Add to `config/features.php`:
   ```php
   'my_flag' => (bool) env('FEATURE_MY_FLAG', $nonProduction),
   ```

2. Share it in `HandleInertiaRequests::share()`:
   ```php
   'features' => [
       ...
       'my_flag' => config('features.my_flag'),
   ],
   ```

3. Use it in React or PHP as shown above.

**Current flags:**

| Flag | `.env` key | Default (non-prod) | Description |
|------|-----------|-------------------|-------------|
| `env_badge` | `FEATURE_ENV_BADGE` | `true` | Floating environment pill (DEV/STAGING) |
| `dashboard_access` | `FEATURE_DASHBOARD_ACCESS` | `true` | Dashboard CTA on home page |
