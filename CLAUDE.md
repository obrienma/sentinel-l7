# CLAUDE.md — Sentinel-L7

Project-specific guidance for Claude Code when working in this repository.

## Stack

- **Backend:** PHP 8.4, Laravel 12
- **Frontend:** React 19, Inertia.js, shadcn/ui (New York style, slate base), Tailwind CSS v4
- **AI:** Gemini Flash (compliance analysis), Gemini `embedding-001` (1536-dim vectors)
- **Infrastructure:** Upstash Redis Streams + Upstash Vector, Neon PostgreSQL
- **Deployment:** Railway

## Running the Project

```bash
composer dev-full          # web + worker + reclaimer (all three processes)
composer dev               # web + queue + logs + vite (dashboard dev)
composer test              # Pest test suite

php artisan sentinel:stream --limit=100   # simulate transaction stream
php artisan sentinel:ingest               # index policy docs into vector KB
php artisan sentinel:reset-metrics        # reset dashboard counters
./vendor/bin/pest --filter=TestName       # single test
./vendor/bin/pint                         # linter
```

## Architecture

Three processes run concurrently in production:

| Process | Command | Role |
|---------|---------|------|
| Web | `php artisan serve` | Inertia/React dashboard |
| Worker | `php artisan sentinel:watch` | Redis Stream consumer |
| Reclaimer | `php artisan sentinel:reclaim` | XCLAIM recovery for zombie messages |

**Per-transaction pipeline (worker):**
1. Embed transaction fingerprint → Gemini embedding API → 1536-dim vector
2. Vector search (Upstash, ns:`default`, threshold ≥ 0.95) → cache hit returns early
3. Cache miss → Gemini Flash analysis with policy RAG (ns:`policies`, threshold ≥ 0.70)
4. Upsert result into vector cache → XACK

Tier 3 fallback: if embedding or vector search throws, `ThreatAnalysisService` runs locally (amount threshold, no AI). XACK always called.

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/EmbeddingService.php` | Fingerprint creation + Gemini embedding calls |
| `app/Services/VectorCacheService.php` | Upstash Vector search/upsert/delete |
| `app/Services/TransactionProcessorService.php` | Core pipeline — cache hit/miss/fallback logic |
| `app/Services/ThreatAnalysisService.php` | Tier 3 rule-based fallback |
| `app/Console/Commands/` | Artisan commands (stream, watch, ingest, reset-metrics) |
| `app/Mcp/` | MCP server and tools (added 2026-03-23) |
| `resources/js/Pages/` | Inertia page components (.jsx) |
| `resources/js/components/ui/` | shadcn/ui components (owned in-repo) |
| `config/features.php` | Feature flags (off in prod, on elsewhere) |
| `docs/adr/` | Architecture decision records |

## Domain Logic Isolation

`App\Services\Sentinel\Logic` must not use `Http` or `Redis` facades directly. Enforced by Pest arch tests. All external I/O must go through injected interfaces. If you add to this namespace, run `./vendor/bin/pest --group=architecture` before assuming it's clean.

## Frontend Conventions

- Pages live in `resources/js/Pages/*.jsx`
- `@` alias maps to `resources/js/`
- `app.js` (the Vite entry point) uses `React.createElement` — not JSX — to avoid needing a `.jsx` extension on the file referenced by `@vite()` in the blade template. All other files use JSX normally.
- shadcn components are added with `npx shadcn@latest add <component>` and owned in `resources/js/components/ui/`
- Tailwind v4: config lives in CSS (`@theme inline` in `app.css`), no `tailwind.config.js`
- Dark palette is set on `:root` by default — not behind a `.dark` class

## Semantic Cache — Known Issues (2026-03-27)

- The fingerprint previously used exact `HH:MM` timestamps — now replaced with time-of-day buckets (night/morning/afternoon/evening). See ADR-0001.
- Amount representation in the fingerprint is still under evaluation — exact amounts may suppress cache hits. See ADR-0002.
- The 0.95 similarity threshold is likely too strict. Empirical testing with 0.90 is pending. See ADR-0015.
- Gemini embedding API has a daily quota that can be exhausted during burst load testing. Alternative embedding providers (OpenAI, Ollama) are documented in ADR-0005.

## Database

- Neon PostgreSQL via non-pooled host (`DB_HOST`). The PgBouncer pooler endpoint breaks `SELECT ... FOR UPDATE SKIP LOCKED`, which Laravel's queue driver requires. See ADR-0010.
- Never point `DB_HOST` at the `-pooler.` Neon endpoint.

## AI Driver

Switch AI backends without code changes:

```env
SENTINEL_AI_DRIVER=gemini      # default
SENTINEL_AI_DRIVER=openrouter  # alternative
```

Both implement `ComplianceDriver::analyze(array $data): array`.

## Metrics

Dashboard stats are stored as Redis cache keys (`sentinel_metrics_*`). Reset with:

```bash
php artisan sentinel:reset-metrics
```

These are separate from Redis Streams — plain key/value `SET`/`GET`, not stream data structures.

## Testing
- Never hit real external APIs in tests — mock at the service interface boundary.
- Architecture tests in `tests/Architecture/` are the most critical; run them after any change to `App\Services\Sentinel\Logic`.
- No frontend tests yet; Vitest + React Testing Library is the intended approach when added.

## ADR files
Create decision logs according to https://martinfowler.com/bliki/ArchitectureDecisionRecord.html

## Pending ADRs
- **ADR-0016** (TODO): Synapse-L4 Axiom ingestion — how Sentinel-L7 receives validated Axioms from the Synapse-L4 sidecar. Decisions needed: new Redis stream key (`synapse:axioms`) vs. existing transaction stream; how `anomaly_score` routes to audit narrative generation; `source_id` correlation back to EventHorizon events. Stub at `docs/adr/0016-synapse-l4-axiom-ingestion.md`.

