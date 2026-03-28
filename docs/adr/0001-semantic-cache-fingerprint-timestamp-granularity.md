# ADR-0001: Semantic Cache Fingerprint — Timestamp Granularity

**Date:** 2026-03-27
**Status:** Accepted

## Context

The semantic cache compares transaction fingerprints via vector similarity (cosine) using Upstash Vector. The original fingerprint included the exact transaction timestamp formatted as `HH:MM` (e.g. `14:23`). This made every transaction fingerprint unique to the minute, since no two transactions in a real stream share the same timestamp. As a result, cache hits were structurally impossible for the time field — two otherwise identical transactions (same merchant, category, amount) would never match because their timestamps differed.

The cache hit rate observed was 29%, with 0 hits on a cold cache run of 57 transactions.

## Decision

Replace the exact `HH:MM` timestamp with a time-of-day bucket: `night`, `morning`, `afternoon`, or `evening`. Boundaries: night (00:00–05:59), morning (06:00–11:59), afternoon (12:00–16:59), evening (17:00–20:59).

## Consequences

**Positive:**
- Two transactions at the same merchant/category/amount within the same time-of-day window now produce near-identical fingerprints, enabling cache hits.
- Compliance verdicts are not time-of-minute sensitive — a $50 restaurant purchase at 2:23pm and 2:45pm should produce the same verdict. The bucket accurately represents the semantically meaningful distinction (lunch vs. evening).

**Negative:**
- Loss of sub-hour precision. If a compliance rule were ever time-of-minute sensitive (e.g. high-frequency trading detection), this fingerprint would be insufficient. No such rule currently exists.
- Transactions near bucket boundaries (e.g. 11:58 vs 12:02) fall into different buckets despite being two minutes apart. Accepted as an edge case.
