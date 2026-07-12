# ADR-0015: Vector Similarity Threshold for Semantic Cache

**Date:** 2026-03-27
**Status:** Accepted (2026-03-28)

## Context

The semantic cache returns a hit when the cosine similarity between a new transaction's embedding and a cached entry meets or exceeds `UPSTASH_VECTOR_THRESHOLD` (default: 0.95). This threshold was set conservatively as an initial estimate.

In practice, 29% cache hit rate was observed on a 2,293-transaction run. Investigation revealed two contributing factors: the fingerprint design (see ADR-0001 and ADR-0002) and the threshold itself. A 0.95 threshold is extremely strict — two semantically similar transactions must produce embeddings that are 95% identical in cosine space. Gemini's `gemini-embedding-001` model does not reliably produce scores this high even for near-duplicate inputs.

Most semantic cache implementations use thresholds in the 0.88–0.92 range.

## Decision

Adopt `0.90` as the default `UPSTASH_VECTOR_THRESHOLD` (`config/services.php`), shipped alongside ADR-0002's amount bucketing in commit `48b83bd`.

The threshold is an env var and can be changed without code deployment.

## Follow-up (outstanding)

- No hit-rate benchmark has been recorded for the 0.90 threshold combined with ADR-0002's bucketed amounts — the same benchmark run should cover both (see ADR-0002's follow-up).
- This tuning was done against Gemini `gemini-embedding-001` embeddings. ADR-0025's switch to `nomic-embed-text` (768-dim) changes the score distribution, so 0.90 must be re-validated from scratch against nomic — there's no reason to assume the value transfers.

## Consequences

**At 0.90 (adopted):**
- More transactions qualify as cache hits, reducing AI API calls and cost.
- Risk of false positives: a transaction that is similar but not identical in compliance terms could reuse an incorrect cached verdict. The fingerprint design (merchant + category + amount tier + time-of-day) is the first line of defence against this — if the fingerprint captures compliance-relevant dimensions correctly, a 0.90 score reflects genuine semantic equivalence.

**At 0.95 (rejected):**
- Higher precision at the cost of hit rate. More transactions reach the AI, increasing cost and latency.
