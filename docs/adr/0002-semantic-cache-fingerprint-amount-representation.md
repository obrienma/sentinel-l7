# ADR-0002: Semantic Cache Fingerprint — Amount Representation

**Date:** 2026-03-27
**Status:** Accepted (2026-03-28)

## Context

The transaction fingerprint includes the exact dollar amount (e.g. `Amount: 45.50 USD`). Like the timestamp issue resolved in ADR-0001, exact amounts are high-cardinality — a $45.50 and a $47.20 transaction at the same merchant produce different fingerprint strings, potentially scoring below the similarity threshold even though the compliance verdict would be identical.

Three approaches were considered:

1. **Leave exact amount** — preserves full fidelity but kills cache hit rate since real transaction amounts vary continuously.
2. **Remove amount entirely** — maximises cache hits but makes the fingerprint semantically meaningless for compliance. A $50 and a $5,000 transaction at the same merchant are not equivalent from a compliance standpoint.
3. **Bucket amount into tiers** — groups amounts by compliance-relevant magnitude (micro/small/medium/large/very large). Semantically accurate: compliance verdicts are driven by order-of-magnitude, not exact cents. The downside is this is domain-specific logic that would need to be redefined for non-financial domains.

A fourth option — relying on the embedding model's inherent numeric understanding to treat $45 ≈ $47 without explicit bucketing — was also discussed. Gemini's text embedding model has some numeric reasoning capability, but it is not reliable enough at a 0.95 threshold to be counted on.

## Decision

Option 3 was adopted: amounts are bucketed into five magnitude tiers (`micro`/`small`/`medium`/`large`/`very_large`) via `match(true)` in `EmbeddingService`, shipped alongside the threshold change from ADR-0015 (commit `48b83bd`).

## Consequences

- Cache hit rate improves for transactions in the same compliance tier.
- Bucket boundaries (`<$10`, `<$100`, `<$500`, `<$2000`, `>=$2000`) encode a domain assumption — they must be revisited if compliance rules change (e.g. a new rule targeting amounts > $300 would require a bucket boundary there).
- Domain generality is reduced: other domains (IoT, content moderation) would need to define their own bucketing schemes, which is appropriate but must be documented.

## Follow-up (outstanding)

No hit-rate measurement has been recorded for bucketed amounts combined with the 0.90 threshold from ADR-0015. The same benchmark run should cover both — see ADR-0015's follow-up section.
