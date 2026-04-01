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

### `searchNamespace(array $embedding, string $namespace, float $threshold, int $topK = 3): array`

Queries a specific Upstash namespace with an explicit threshold. Returns all results above the threshold (not just the best match), each as `{id, score, metadata}`. Used by `GeminiDriver` and the `SearchPolicies` MCP tool to query `ns:policies`.

```php
$chunks = $vectorCache->searchNamespace($vector, 'policies', 0.70, 3);
// → [['id' => 'aml-bsa-compliance_2', 'score' => 0.83, 'metadata' => ['text' => '...', ...]]]
```

### `upsertNamespace(string $id, array $embedding, array $metadata, string $namespace): bool`

Upserts a vector into a specific namespace. Used by `sentinel:ingest` to populate `ns:policies`.

```php
$vectorCache->upsertNamespace('aml-bsa-compliance_2', $vector, [
    'text'   => $chunkText,
    'source' => 'aml-bsa-compliance',
    'chunk'  => 2,
], 'policies');
```

**Config keys read:**
```
services.upstash_vector.url    → UPSTASH_VECTOR_REST_URL
services.upstash_vector.token  → UPSTASH_VECTOR_REST_TOKEN
services.upstash_vector.similarity_threshold → UPSTASH_VECTOR_THRESHOLD (default: 0.95, used by search() only)
```

---

## ComplianceManager

**File:** `app/Services/ComplianceManager.php`  
**Extends:** `Illuminate\Support\Manager`

Resolves the active `ComplianceDriver` implementation from config. Adding a new AI backend means adding a `createFooDriver()` method and setting `SENTINEL_AI_DRIVER=foo` — no call-site changes required.

```php
// Resolved automatically by AppServiceProvider binding ComplianceDriver → ComplianceManager::driver()
$driver = app(ComplianceDriver::class);
$result = $driver->analyze($axiomData);
```

**Config key read:**
```
sentinel.ai_driver → SENTINEL_AI_DRIVER (default: gemini)
```

**Registered drivers:** `gemini` → `GeminiDriver`, `openrouter` → `OpenRouterDriver`

---

## GeminiDriver

**File:** `app/Services/Compliance/GeminiDriver.php`  
**Implements:** `App\Contracts\ComplianceDriver`

Runs the full RAG + AI analysis pipeline for a single Axiom:

```
buildQueryText(data)                   // translate anomaly fields → compliance question
  → EmbeddingService::embed(query)     // embed the question
  → VectorCacheService::searchNamespace('policies', 0.70, 3)  // retrieve policy chunks
  → buildPrompt(data, chunks)          // inject Axiom + policy context into prompt
  → Gemini Flash (structured JSON)     // generate audit narrative
  → parseResponse(raw)                 // strip markdown fences, decode JSON
```

Returns `{narrative, risk_level, policy_refs, confidence}`.

**Query formulation:** `buildQueryText()` translates `anomaly_score` to a severity phrase so the embedding lands in the same semantic space as policy text about reporting obligations. Score ≥0.90 → `"critical severity requiring immediate escalation and reporting"`, etc.

**Resilience:** If policy RAG fails (embedding or vector search throws), `fetchPolicyContext()` catches the exception, logs a warning, and proceeds with an empty context. The Gemini call still runs — it just has no retrieved policy chunks.

**Config keys read:**
```
services.gemini.api_key   → GEMINI_API_KEY
services.gemini.flash_url → GEMINI_FLASH_URL
```

---

## AxiomStreamService

**File:** `app/Services/AxiomStreamService.php`

Publish/read on the `synapse:axioms` Redis stream. Mirrors `TransactionStreamService` in shape.

### `publish(array $data): bool`

`XADD synapse:axioms MAXLEN ~ 500 * data <json>`

### `read(string $lastId = '$'): array`

`XREAD BLOCK 0 STREAMS synapse:axioms <lastId>`

Returns `{messages: array, cursor: string}`. Pass `$` on the first call to receive only new messages; pass the returned cursor on subsequent calls.

---

## AxiomProcessorService

**File:** `app/Services/AxiomProcessorService.php`

Processes a single Axiom payload from the `synapse:axioms` stream. Two rules:

1. **Always persist** a `ComplianceEvent` row — no Axiom is silently dropped.
2. **Route to AI** only when `anomaly_score > AXIOM_AUDIT_THRESHOLD` (default `0.8`).

```
process(data)
  ├─ score > threshold → ComplianceDriver::analyze(data) → persist (routed_to_ai=true)
  └─ score ≤ threshold → persist (routed_to_ai=false, narrative=null)
```

Returns `{source_id, routed_to_ai, risk_level, narrative, elapsed_ms}`.

**Resilience:** If the driver throws, the exception is caught, a warning is logged, and the `ComplianceEvent` is still persisted with `audit_narrative = null`.

**Config key read:**
```
sentinel.axiom_threshold → AXIOM_AUDIT_THRESHOLD (default: 0.8)
```

---

## SentinelIngest (`sentinel:ingest`)

**File:** `app/Console/Commands/SentinelIngest.php`  
**Run:** `php artisan sentinel:ingest`

Chunks `.md` policy files and upserts each chunk into `ns:policies`.

**Pipeline per file:**
```
glob policies/*.md
  → chunk on paragraph boundaries (~500 words each)
  → EmbeddingService::embed(chunkText)
  → VectorCacheService::upsertNamespace(id, vector, {text, source, chunk}, 'policies')
```

**Options:**
- `--path=policies` — directory of `.md` files (default: `base_path('policies')`)
- `--chunk-size=500` — target word count per chunk

Chunk IDs follow the pattern `{filename}_{index}` (e.g. `aml-bsa-compliance_2`). Upserts are idempotent — re-running updates existing vectors in place.

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

## TransactionProcessorService

**File:** `app/Services/TransactionProcessorService.php`

The core per-transaction compliance pipeline, extracted so it can be called from both the `sentinel:watch` daemon and the `ProcessStreamJob` queue job (triggered by the dashboard "Run Transactions" button).

### `process(array $data): array`

Runs a single transaction through the full pipeline:

```
embed() → VectorCacheService::search()
  ├─ Hit  → reuse cached analysis  → recordMetric('cache_hit')  → recordTransaction()  → return
  └─ Miss → ThreatAnalysisService::analyze()
             → VectorCacheService::upsert()
             → recordMetric('cache_miss')
(any Throwable) → ThreatAnalysisService::analyze() directly
                  → recordMetric('fallback')

→ recordTransaction()   (miss + fallback paths)
→ Cache::increment('sentinel_metrics_threat_count')  (if isThreat)
```

Returns a summary array — useful for CLI output or logging; safe to ignore in queued jobs:

```php
[
    'source'     => 'cache_hit' | 'cache_miss' | 'fallback',
    'is_threat'  => bool,
    'message'    => string,
    'elapsed_ms' => float,
]
```

**Side effects (always):**
- Writes metric counters to Laravel cache (Upstash Redis)
- Pushes a JSON entry to `sentinel:recent_transactions` (Redis list, capped at 50)

**Error handling:** A `\Throwable` in the embed/vector path triggers the fallback. The fallback itself is not caught — if `ThreatAnalysisService` also fails, the exception propagates to the caller (queue worker or daemon loop).

**Note on two exit points:** Cache hits return early after `recordTransaction`. Miss/fallback paths fall through to a shared `recordTransaction` + threat increment at the bottom. Keep this in mind when modifying — a side effect added in one path must be considered for the other.

---

## ProcessStreamJob

**File:** `app/Jobs/ProcessStreamJob.php`

A finite, dispatchable version of the compliance pipeline. Receives transaction data directly (not from the Redis stream) and calls `TransactionProcessorService::process()`.

Dispatched by `StreamTransactionsJob` after each `publish()` call — one job per transaction. Because both jobs share the same queue, they are processed FIFO.

```php
// Dispatched internally by StreamTransactionsJob
ProcessStreamJob::dispatch($transactionArray);
```

This is the job that makes the dashboard "Run Transactions" button self-contained — no need for `sentinel:watch` to be running.

---

## StreamTransactionsJob

**File:** `app/Jobs/StreamTransactionsJob.php`

Generates N fake transactions, publishes each to the Redis stream via `TransactionStreamService::publish()`, and processes each inline via `TransactionProcessorService::process()` — all within a single queue job execution.

```php
StreamTransactionsJob::dispatch(10);  // publish + process 10 txns in one job
```

Dispatched from `DashboardController::stream()` via `POST /dashboard/stream`.

> **Note:** Processing inline (rather than dispatching N separate `ProcessStreamJob` instances) avoids queue round-trip overhead per transaction. `ProcessStreamJob` still exists for cases where a single transaction needs to be dispatched independently.

---

## WatchTransactions (`sentinel:watch`)

**File:** `app/Console/Commands/WatchTransactions.php`
**Run:** `php artisan sentinel:watch`

The always-on stream consumer daemon. Reads transactions from the Redis stream in a blocking loop and delegates each one to `TransactionProcessorService::process()` — it owns no pipeline logic of its own.

**Flow:**

```
while (true) {
  XREAD from stream
    → TransactionProcessorService::process($data)
    → format result for CLI output (match on source)
}
```

`WatchTransactions` is responsible only for reading from the stream and formatting terminal output. All pipeline logic (embed → vector search → analyze → record metrics → record feed) lives in `TransactionProcessorService`. See that section above for the full flow.

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
