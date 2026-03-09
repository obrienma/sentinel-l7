# Architecture

## System Overview

Sentinel-L7 is a multi-process Laravel application. Three processes run concurrently:

| Process | Command | Role |
|---------|---------|------|
| Web | `php artisan serve` | Inertia/React dashboard, API endpoints |
| Worker | `php artisan sentinel:consume` | Redis Stream consumer — reads, analyzes, acks |
| Reclaimer | `php artisan sentinel:reclaim` | XCLAIM recovery for zombie messages |

```
┌──────────────────────────────────────────────────────────┐
│  Browser (React 19 + shadcn/ui)                          │
│  Inertia.js — server-driven SPA, no separate API layer   │
└────────────────────┬─────────────────────────────────────┘
                     │ HTTP (Inertia protocol)
┌────────────────────▼─────────────────────────────────────┐
│  Laravel 12 Web Process                                   │
│  ┌────────────────┐  ┌──────────────────────────────────┐ │
│  │ Controllers    │  │ HandleInertiaRequests middleware  │ │
│  │ Home           │  │ Shared props: flash.success/error │ │
│  │ Auth           │  └──────────────────────────────────┘ │
│  │ Dashboard      │                                       │
│  └────────────────┘                                       │
└──────────────────────────────────────────────────────────┘

┌─────────────────────────────┐  ┌────────────────────────┐
│  Sentinel Worker            │  │  Reclaimer             │
│  XREADGROUP sentinel-group  │  │  XCLAIM zombie msgs    │
│  → EmbeddingService         │  │  (idle > 60s)          │
│  → VectorCacheService       │  │                        │
│  → ComplianceEngine (AI)    │  │                        │
│  → XACK                     │  │                        │
└─────────────┬───────────────┘  └─────────┬──────────────┘
              │                            │
              ▼                            ▼
┌──────────────────────────────────────────────────────────┐
│  Upstash Redis Streams                                    │
│  Stream: sentinel:transactions                            │
│  Pending Entry List (PEL) — fault tolerance              │
└──────────────────────────────────────────────────────────┘
              │
    ┌─────────┴──────────┐
    ▼                    ▼
┌──────────────┐  ┌──────────────────────────┐
│  Upstash     │  │  Gemini Flash AI         │
│  Vector      │  │  Structured JSON output  │
│  ns:default  │  │  (compliance analysis)   │
│  ns:policies │  └──────────────────────────┘
└──────────────┘
```

## Core Pipeline (Worker)

1. **XREADGROUP** — Block-read from `sentinel:transactions` via consumer group
2. **Idempotency** — Guard against duplicate processing (Redis SETNX)
3. **Fingerprint** — Build semantic key: `Amount:X|Type:Y|Category:Z|Time:HH|Merchant:M`
4. **Embed** — POST to Gemini embedding API → high-dim vector
5. **Vector Search** — Upstash Vector cosine similarity, namespace `default`
6. **Cache Hit** (score ≥ 0.95) — Reuse cached compliance result
7. **Cache Miss** — Policy RAG retrieval (namespace `policies`) → Gemini analysis → upsert
8. **XACK** — Acknowledge on all paths including fallback
9. **XCLAIM Recovery** — Reclaimer claims messages idle > 60s from the PEL

## Domain Logic Isolation

`App\Services\Sentinel\Logic` is the protected core. Enforced by Pest architecture tests:

- **Allowed:** `ComplianceDriver` interface (injected via Laravel Service Container)
- **Forbidden:** `Http` facade, `Redis` facade — no direct infrastructure coupling
- **Entry points:** Controllers and Artisan Commands call into Logic; Logic never calls them

## Service Layer

| Service | Responsibility | External Dependency |
|---------|---------------|---------------------|
| `ComplianceEngine` | Orchestrates analysis pipeline | `ComplianceDriver` |
| `ComplianceManager` | Resolves AI driver (Service Manager pattern) | Config |
| `GeminiDriver` | Gemini Flash analysis + policy RAG | Gemini API |
| `OpenRouterDriver` | Alternative AI backend | OpenRouter API |
| `EmbeddingService` | Semantic fingerprints + embeddings | Gemini Embedding API |
| `VectorCacheService` | Similarity search + cache storage | Upstash Vector |

## AI Driver Pattern (Service Manager)

Drivers implement the `ComplianceDriver` interface:

```php
interface ComplianceDriver {
    public function analyze(array $data): array;
}
```

`ComplianceManager` extends Laravel's `Manager` class. The active driver is resolved from `config('sentinel.ai_driver')`. Switching from Gemini to OpenRouter requires only an env var change — no code changes.

## Dual-Namespace Vector Strategy

| Namespace | Purpose | Similarity Threshold |
|-----------|---------|---------------------|
| `default` | Transaction semantic cache | ≥ 0.95 (near-exact match) |
| `policies` | Policy RAG retrieval | ≥ 0.70 (topical relevance) |

The different thresholds are intentional. Transaction caching needs precision; policy retrieval needs recall.

## Frontend Architecture

- **Inertia.js** — no separate API. Controllers return `Inertia::render('PageName', $props)`. Props become React component arguments directly.
- **React 19** — pages in `resources/js/Pages/`. Components in `resources/js/components/`.
- **shadcn/ui** — components are owned in `resources/js/components/ui/`. Copy-in model, not a black-box package.
- **`@` alias** — maps to `resources/js/` for clean imports.
- **Tailwind v4** — config in CSS (`@theme`, `@theme inline`), no `tailwind.config.js`.

See [diagrams/SYSTEM_ARCHITECTURE.md](diagrams/SYSTEM_ARCHITECTURE.md) for Mermaid diagrams.
