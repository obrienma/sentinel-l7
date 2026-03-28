# ADR-0007: Three-Tier Compliance Pipeline with Graceful Degradation

**Date:** 2026-02-05
**Status:** Accepted

## Context

The compliance pipeline depends on two external services (Gemini API, Upstash Vector) that can be unavailable or slow. A hard failure on any step would mean unprocessed transactions — unacceptable for a compliance monitoring system. At the same time, always calling the AI for every transaction is expensive.

## Decision

Structure the pipeline in three tiers, tried in order:

| Tier | Component | Condition | Latency | Cost |
|------|-----------|-----------|---------|------|
| 1 | Upstash Vector cache hit | Score ≥ threshold | ~50ms | $0 |
| 2 | Gemini Flash + policy RAG | Cache miss | 500ms–2s | API tokens |
| 3 | Rule-based (`ThreatAnalysisService`) | Tier 1/2 infrastructure failure | ~0ms | $0 |

Tier 3 activates only on a `\Throwable` from the embed/vector path. `XACK` is always called regardless of tier — a fallback analysis is a completed analysis.

## Consequences

**Positive:**
- Zero transaction loss: even if both Gemini and Upstash are down, the rule-based engine produces a verdict.
- Cost is proportional to cache miss rate — high cache hit rates approach $0 per transaction.
- The fallback is transparent to callers; `TransactionProcessorService::process()` always returns the same shape.
- The `source` field in the return value (`cache_hit` | `cache_miss` | `fallback`) makes the active tier observable in metrics and logs.

**Negative:**
- Tier 3 verdicts are less accurate than Tier 2 (threshold-based vs. AI reasoning with policy context). A sustained Gemini outage degrades compliance quality silently unless monitored.
- The fallback threshold (`sentinel.thresholds.high_risk`, default $400) is a hardcoded heuristic — it encodes a compliance assumption that should be revisited as policy requirements evolve.
- Three tiers add cognitive overhead when modifying `TransactionProcessorService` — changes must be considered across all three paths. The two exit points (early return on cache hit vs. fall-through on miss/fallback) are a known maintenance hazard, documented in SERVICES.md.
