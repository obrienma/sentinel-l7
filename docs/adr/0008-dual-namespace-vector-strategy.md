# ADR-0008: Dual-Namespace Vector Strategy (Cache vs. RAG)

**Date:** 2026-02-05
**Status:** Accepted

## Context

Upstash Vector is used for two distinct purposes with different retrieval semantics:

1. **Semantic cache** (`default` namespace) — "Is this transaction essentially the same as one we've already analysed?" Needs high precision: a false positive means reusing the wrong compliance verdict.
2. **Policy RAG** (`policies` namespace) — "Which compliance policies are topically relevant to this transaction?" Needs high recall: missing a relevant policy means the AI reasons without it.

Using a single namespace and threshold for both would either over-fetch policy documents (if set low for cache) or miss relevant policies (if set high for cache).

## Decision

Use separate Upstash Vector namespaces with different similarity thresholds:

| Namespace | Purpose | Threshold |
|-----------|---------|-----------|
| `default` | Transaction semantic cache | ≥ 0.95 (near-exact match) |
| `policies` | Policy RAG retrieval | ≥ 0.70 (topical relevance) |

Policy documents are indexed via `php artisan sentinel:ingest`, which reads `.md` policy files, embeds them, and upserts into the `policies` namespace.

## Consequences

**Positive:**
- Each namespace can be tuned independently without affecting the other.
- Policy documents and transaction vectors never compete for the same index space.
- The `policies` namespace can be cleared and re-indexed (`sentinel:ingest`) without affecting the transaction cache.

**Negative:**
- The 0.95 cache threshold was set as an initial conservative estimate. Observed cache hit rates (29% in early testing) suggest the threshold may be too strict for real transaction streams — see ADR-0001 and ADR-0002 for ongoing work on fingerprint design and threshold tuning.
- Dual-namespace support in `VectorCacheService` is planned but not yet fully implemented at time of writing — the service currently operates on the default namespace only.
