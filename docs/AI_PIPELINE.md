# AI Pipeline

## Overview

The compliance pipeline has three tiers with graceful degradation:

| Tier | Component | Latency | Cost |
|------|-----------|---------|------|
| 1 — Cache Hit | Upstash Vector (ns:default) | ~50ms | $0 |
| 2 — AI + RAG | Gemini Flash + policy context | ~500ms–2s | API tokens |
| 3 — Fallback | Rule-based (local) | ~0ms | $0 |

Tier 3 activates only when Upstash or Gemini is unreachable. XACK is always called — even on fallback — because a fallback analysis is a completed analysis.

## Semantic Cache (Namespace: `default`)

**Threshold:** ≥ 0.95 cosine similarity

Fingerprint format:
```
Amount:500.00|Type:PURCHASE|Category:RETAIL|Time:14|Merchant:COSTCO
```

This string is embedded into a vector. A high cosine similarity (≥ 0.95) means "this transaction is essentially the same pattern we've seen before" — safe to reuse the cached risk report.

An 80%+ cache hit rate is expected in high-volume production traffic (repeat transaction patterns dominate).

## Policy RAG (Namespace: `policies`)

**Threshold:** ≥ 0.70 cosine similarity

On a cache miss, the worker retrieves the most semantically relevant compliance policies from the `policies` namespace. These are injected into the Gemini prompt as context, so the AI reasons against actual regulatory rules rather than its training data alone.

Policies are indexed via `php artisan sentinel:ingest`. The indexer reads `.md` policy documents, embeds them, and upserts into Upstash Vector `policies` namespace.

## Prompt Versioning

Prompts are stored as versioned files (convention from the Kotlin variant, applied here):

```
resources/prompts/
  transaction-analysis-v1.txt    ← rule-based context
  transaction-analysis-v2.txt    ← policy RAG context (current)
```

When updating a prompt, create a new version file. The latest version is used automatically. Old versions remain for rollback or A/B testing.

## AI Driver Swapping

The active driver is set via env var:

```env
SENTINEL_AI_DRIVER=gemini      # or: openrouter
```

Both drivers implement `ComplianceDriver::analyze(array $data): array`. The `ComplianceManager` (Service Manager pattern) resolves the correct driver at runtime. No code change required to switch backends.

## Gemini Response Format

The analysis prompt requests structured JSON output:

```json
{
  "risk_level": "high",
  "flags": ["smurfing_pattern", "velocity_anomaly"],
  "confidence": 0.87,
  "justification": "Transaction amount of $499 is just below the $500 CTR threshold...",
  "matched_policies": ["AML-001", "BSA-THRESHOLD"]
}
```

`responseMimeType: "application/json"` is set in the request. Gemini occasionally still wraps output in markdown fences — strip defensively before parsing.

## Token Cost Control

Two levers:
1. **Semantic cache** — 80%+ hit rate means 80%+ of transactions never reach the LLM
2. **Model selection** — Gemini 2.0 Flash is cost-optimized; swap via `GEMINI_MODEL` env var

Track token usage in Redis counters (add metrics as pipeline matures).
