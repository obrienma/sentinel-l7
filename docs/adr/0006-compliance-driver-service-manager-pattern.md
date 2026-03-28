# ADR-0006: ComplianceDriver Interface + Service Manager Pattern for AI Backends

**Date:** 2026-02-05
**Status:** Accepted

## Context

The compliance analysis step calls an external AI API (currently Gemini Flash). AI providers differ in pricing, availability, rate limits, and output quality. Hardcoding Gemini throughout the pipeline would make it difficult to experiment with alternatives (OpenRouter, Anthropic, OpenAI) or fall back to a different provider when quotas are exhausted.

## Decision

Define a `ComplianceDriver` interface with a single method:

```php
interface ComplianceDriver {
    public function analyze(array $data): array;
}
```

`ComplianceManager` extends Laravel's `Manager` class and resolves the active driver from `config('sentinel.ai_driver')` (set via `SENTINEL_AI_DRIVER` env var). Two drivers are implemented: `GeminiDriver` and `OpenRouterDriver`. Switching providers requires only an env var change — no code changes.

## Consequences

**Positive:**
- Provider switching is an ops concern, not a code change.
- New drivers can be added without modifying existing code — implement the interface and register in `ComplianceManager`.
- The interface boundary makes drivers independently testable with mocks.
- Follows Laravel's native Service Manager pattern, which is already used by Mail, Cache, and Queue — familiar to Laravel developers.

**Negative:**
- The abstraction only covers the analysis step. The embedding step (`EmbeddingService`) is still Gemini-specific; a different embedding provider would require a separate refactor.
- The interface is minimal by design (`analyze(array): array`). If drivers need to expose additional capabilities (streaming, token counting), the interface would need to evolve.
