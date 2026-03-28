# ADR-0005: Gemini Flash for Compliance Analysis and Embedding

**Date:** 2026-02-05
**Status:** Accepted

## Context

The pipeline requires two AI capabilities:
1. **Text embedding** — converting transaction fingerprints into high-dimensional vectors for similarity search.
2. **Compliance analysis** — reasoning about whether a transaction violates policy, with structured JSON output including risk level, flags, confidence, and matched policy references.

Options considered included OpenAI (GPT-4o + text-embedding-3), Anthropic Claude, and Gemini. The embedding model and the inference model do not need to be from the same provider, but using one provider reduces credential management and API surface.

## Decision

Use Google Gemini for both:
- **`gemini-embedding-001`** — 1536-dimensional output (matching OpenAI's `text-embedding-ada-002` dimension for potential future migration), with `output_dimensionality` set explicitly.
- **`gemini-2.0-flash`** — cost-optimised inference model for compliance analysis. Configurable via `GEMINI_MODEL` env var without code changes.

The active AI driver is abstracted behind the `ComplianceDriver` interface (see ADR-0006), so Gemini can be swapped for OpenRouter or another provider via an env var.

## Consequences

**Positive:**
- Single API key for both embedding and inference.
- Gemini Flash is significantly cheaper per token than GPT-4o or Claude Sonnet for the same task profile.
- `responseMimeType: "application/json"` enforces structured output natively in the Gemini API.
- 1536-dim embeddings match OpenAI's dimension, preserving the option to swap embedding providers without recreating the Upstash Vector index.

**Negative:**
- Gemini's free tier has quota limits (requests/minute and requests/day) that can be hit during development load testing. Observed in practice: embedding quota exhausted after ~57 transactions on a burst run.
- Gemini occasionally wraps JSON output in markdown fences despite `responseMimeType` being set — defensive stripping is required before parsing.
- Single-provider dependency for two critical pipeline steps. If the Gemini API is degraded, both embedding and analysis fail simultaneously (mitigated by the Tier 3 rule-based fallback, which bypasses both).
