# ADR-0025: Local Ollama Embedding Provider (nomic-embed-text v1.5)

**Date:** 2026-07-01
**Status:** Accepted

## Context

ADR-0005 accepted the risk of a single-provider dependency on Gemini for both embedding and compliance analysis, noting that the free-tier embedding quota can be exhausted after roughly 57 transactions on a burst run. That risk has continued to materialize during load testing (e.g. the Phase 7 benchmark seeder, which runs 500 transactions per invocation) — embedding calls are the first thing to fail, well before the Gemini Flash analysis quota is a concern, because every transaction requires an embed call but only cache misses require an analysis call.

An Ollama server is now available, making local, unmetered embedding generation possible for the first time. This removes the quota ceiling for the embedding step, independent of transaction volume.

Two things stand in the way of a simple provider swap:

1. **`EmbeddingService` is a concrete class, not an interface.** `App\Services\EmbeddingService` is injected directly into `TransactionProcessorService`, `SearchPolicies` (MCP tool), and `SentinelIngest`. There is no equivalent of the `ComplianceDriver` / `ComplianceManager` Service Manager pattern (ADR-0006) for embeddings, so there is no env-toggled way to select a provider today.
2. **The Upstash Vector index dimension is fixed at 1536** (`config/services.php`, `upstash_vector.dimension`), matching `gemini-embedding-001`. A vector index's dimension cannot be changed in place — switching embedding providers means the index must be recreated at the new dimension, and everything in it rebuilt: the semantic cache (ns:`default`, low-cost — it's a cache, going cold just means the next transaction per fingerprint re-runs analysis once) and the policy knowledge base (ns:`policies`, which must be explicitly re-ingested via `php artisan sentinel:ingest` or RAG retrieval silently returns zero chunks).

## Decision

Adopt **`nomic-embed-text` v1.5 via Ollama**, 768 dimensions, as the embedding provider — scoped to embeddings only. Gemini Flash remains the compliance analysis model; ADR-0005 is not superseded for that half of the pipeline.

**1. `EmbeddingDriver` contract, mirroring `ComplianceDriver` (ADR-0006):**

```php
interface EmbeddingDriver
{
    public const TASK_DOCUMENT = 'search_document';
    public const TASK_QUERY = 'search_query';

    public function embed(string $text, string $task = self::TASK_DOCUMENT): array;
}
```

`GeminiEmbeddingDriver` and `OllamaEmbeddingDriver` implement it; an `EmbeddingManager extends Illuminate\Support\Manager` resolves the default driver from `config('sentinel.embedding_driver')`, itself backed by a new `SENTINEL_EMBEDDING_DRIVER` env var — same shape as `ComplianceManager`/`SENTINEL_AI_DRIVER`. `EmbeddingService::createTransactionFingerprint()` is unaffected — fingerprint construction is not provider-specific; only the HTTP call currently inline in `EmbeddingService::embed()` moves behind the new interface.

**2. Task-prefix handling (nomic v1.5-specific).** Nomic's training recommends prefixing indexed text with `search_document:` and query text with `search_query:` — skipping this doesn't error, but retrieval quality degrades because the model was trained expecting that signal. `OllamaEmbeddingDriver::embed()` prepends the prefix corresponding to the `$task` argument before calling Ollama; `GeminiEmbeddingDriver` ignores `$task` entirely (Gemini has no equivalent convention). This is why the parameter lives on the interface rather than being an Ollama-only implementation detail — call sites need to state their intent once, and it's a no-op for whichever driver doesn't need it.

Call-site task assignment, based on the three existing `embed()` call sites:

| Call site | Use | Task |
|---|---|---|
| `SentinelIngest.php:56` | Embedding policy chunks at ingest time | `TASK_DOCUMENT` (default) |
| `SearchPolicies.php:27` | Embedding an incoming query against ns:`policies` | `TASK_QUERY` |
| `TransactionProcessorService.php:42` | Embedding a transaction fingerprint | `TASK_DOCUMENT` (see below) |

The transaction-fingerprint case is the one genuine judgment call: the same vector is used both to *search* the semantic cache and, on a miss, to *become* the new cache entry — there's no clean query/passage asymmetry the way there is for policy RAG. Nomic's `search_document:` prefix is the better fit here because both sides of the comparison are equivalent-shape fingerprint strings being matched for similarity, not a short question being matched against a longer passage — closer to a dedup/clustering use case than an asymmetric retrieval one. Using `search_document:` uniformly for both the search and the upsert keeps the two sides of the comparison consistent, which matters more here than which specific prefix is "more correct."

**3. Upstash Vector index migration.** Recreate the index at 768 dimensions, update `config('services.upstash_vector.dimension')` to `768`, and re-run `php artisan sentinel:ingest` to repopulate ns:`policies` immediately after — not before, or RAG retrieval silently returns zero chunks in the gap. Ns:`default` requires no manual action; it starts empty and refills as transactions flow.

## Consequences

**Positive:**
- Removes the embedding quota ceiling — the pipeline's per-transaction throughput is no longer bounded by Gemini's free-tier embedding rate limit, which was the single biggest source of Tier 3 fallback triggers during burst/benchmark load (ADR-0007).
- Extends the driver-abstraction pattern already proven for `ComplianceDriver` to embeddings, keeping the codebase consistent — both pipeline AI calls are now swappable per-provider via env var with no code change.
- No ongoing embedding API cost or external network dependency for that pipeline stage.
- Task-prefix support is designed in from the start rather than retrofitted — avoids a silent retrieval-quality regression that would otherwise only surface as an unexplained drop in RAG chunk relevance.

**Negative:**
- One-time migration cost: recreating the Upstash Vector index and re-ingesting the policy KB, sequenced carefully to avoid the zero-chunk gap described above.
- 768-dim nomic embeddings are not comparable to 1536-dim Gemini embeddings — the existing similarity-threshold tuning (ADR-0015, currently under empirical evaluation at 0.90) is Gemini-specific and must be re-validated from scratch against nomic's score distribution; there's no reason to assume the same threshold value transfers.
- Introduces a new operational dependency: the Ollama server must be running and reachable for every transaction, including in production, or the pipeline falls straight to Tier 3 (no embedding → no cache lookup → rule-based fallback). This trades one availability risk (Gemini quota) for another (local server uptime) — worth a follow-up decision on whether Ollama is a dev/load-testing-only tool or a production dependency.
- Reverting `SENTINEL_EMBEDDING_DRIVER` back to `gemini` is not a pure env-var flip — the index dimension can only serve one embedding space at a time, so reverting requires another index recreation and re-ingest, same as the forward migration.

## Alternatives considered

**Pad or truncate nomic's 768-dim output to 1536:** Rejected. Padding or truncating an embedding vector does not produce a semantically valid point in the target space; cosine similarity against Gemini-space vectors would be meaningless. This would silently corrupt the semantic cache rather than fail loudly.

**Hybrid: keep Gemini as default, fall back to Ollama only on Gemini 429s:** Rejected. Mixing two embedding spaces in one vector index (same underlying problem as padding, just intermittent instead of permanent) would make cache hit/miss behavior non-deterministic. Not worth pursuing unless a per-request dimension-routing strategy (separate indexes per provider) is built — out of scope here.

**Stay on Gemini and rely on `retry`/backoff tuning:** Rejected as insufficient. `EmbeddingService::embed()` already retries 3 times with backoff; the observed failures are quota exhaustion (daily/per-minute caps), not transient errors, so retrying hits the same wall immediately.

**Skip task-prefixing and treat nomic like any other embedding model:** Rejected. It costs nothing to prefix correctly at implementation time, and the failure mode of skipping it (degraded retrieval quality with no error signal) is exactly the kind of silent partial failure already called out as a standing TODO in `CLAUDE.md`.
