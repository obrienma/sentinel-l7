# ADR-0003: Vector Similarity Threshold for Semantic Cache

**Date:** 2026-03-27
**Status:** Proposed

## Context

The semantic cache returns a hit when the cosine similarity between a new transaction's embedding and a cached entry meets or exceeds `UPSTASH_VECTOR_THRESHOLD` (default: 0.95). This threshold was set conservatively as an initial estimate.

In practice, 29% cache hit rate was observed on a 2,293-transaction run. Investigation revealed two contributing factors: the fingerprint design (see ADR-0001 and ADR-0002) and the threshold itself. A 0.95 threshold is extremely strict — two semantically similar transactions must produce embeddings that are 95% identical in cosine space. Gemini's `gemini-embedding-001` model does not reliably produce scores this high even for near-duplicate inputs.

Most semantic cache implementations use thresholds in the 0.88–0.92 range.

## Decision

Pending empirical testing. Lower `UPSTASH_VECTOR_THRESHOLD` to `0.90` in `.env`, run `php artisan sentinel:reset-metrics` and `php artisan sentinel:stream --limit=200`, and observe the resulting hit rate and the similarity scores logged for near-identical transactions. If the scores for genuinely similar transactions cluster above 0.90, adopt that threshold. Adjust further if needed.

The threshold is an env var and can be changed without code deployment.

## Consequences

**If threshold is lowered to 0.90:**
- More transactions qualify as cache hits, reducing AI API calls and cost.
- Risk of false positives: a transaction that is similar but not identical in compliance terms could reuse an incorrect cached verdict. The fingerprint design (merchant + category + amount tier + time-of-day) is the first line of defence against this — if the fingerprint captures compliance-relevant dimensions correctly, a 0.90 score reflects genuine semantic equivalence.

**If threshold remains at 0.95:**
- Higher precision at the cost of hit rate. More transactions reach the AI, increasing cost and latency.
