# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

- **Backend:** PHP 8.4, Laravel 12
- **Frontend:** React 19, Inertia.js, shadcn/ui (New York style, slate base), Tailwind CSS v4
- **AI:** Gemini Flash (compliance analysis), Gemini `embedding-001` (1536-dim vectors)
- **Infrastructure:** Upstash Redis Streams + Upstash Vector, Neon PostgreSQL
- **Deployment:** Railway

## Running the Project

```bash
composer dev               # all five processes: serve, queue, logs, vite, sentinel:watch-axioms
composer test              # Pest test suite

php artisan sentinel:stream --limit=100   # simulate transaction stream (writes to sentinel:transactions)
php artisan sentinel:watch                # transaction stream worker (run alongside sentinel:stream for manual testing)
php artisan sentinel:ingest               # index policy docs into vector KB
php artisan sentinel:reset-metrics        # reset dashboard counters
./vendor/bin/pest --filter=TestName       # single test
./vendor/bin/pint                         # linter
```

## Architecture

Three long-running processes form the production system:

| Process | Command | Role |
|---------|---------|------|
| Web | `php artisan serve` | Inertia/React dashboard |
| Worker | `php artisan sentinel:watch` | Transaction stream consumer (XREADGROUP + embedded XAUTOCLAIM) |
| Axioms Worker | `php artisan sentinel:watch-axioms` | Synapse-L4 Axiom consumer (XREADGROUP + embedded XAUTOCLAIM) |

Both workers run an `XAUTOCLAIM` pass at the top of every loop iteration — recovery is distributed across the pool, no dedicated reclaimer daemon (ADR-0022). `composer dev` starts all except `sentinel:watch` (transactions). Run `sentinel:watch` manually when testing the transaction pipeline alongside `sentinel:stream`.

**Per-transaction pipeline (worker):**
1. Embed transaction fingerprint → Gemini embedding API → 1536-dim vector
2. Vector search (Upstash, ns:`default`, threshold ≥ 0.95) → cache hit returns early
3. Cache hit is validated against `sentinel_policy_epoch` — stale epoch triggers re-analysis
4. Cache miss → Gemini Flash analysis with policy RAG (ns:`policies`, threshold ≥ 0.70, filtered by `domain` metadata when present)
5. Upsert result into vector cache → XACK

Tier 3 fallback: if embedding or vector search throws, `ThreatAnalysisService` runs locally (amount threshold, no AI). XACK always called.

**Axiom pipeline (`synapse:axioms` stream):**
- Uses XREADGROUP/XACK with consumer group `axiom-workers` — messages enter the PEL until acknowledged
- `AxiomProcessorService::process()` routes to AI if `anomaly_score > AXIOM_AUDIT_THRESHOLD` (default 0.8)
- Always persists a `ComplianceEvent` — no Axiom is silently dropped
- DB-layer dedup: `source_id` has a unique partial index; `UniqueConstraintViolationException` is caught and logged

**Worker loop structure (both `sentinel:watch` and `sentinel:watch-axioms`, ADR-0022):**
1. `XAUTOCLAIM` with `sentinel.reclaim.idle_ms` (default 30000) — for each claimed message, check `deliveryCount(id)` via XPENDING; if `>= sentinel.reclaim.delivery_count_limit` (default 3) → `Log::error` + XACK without processing
2. `XREADGROUP COUNT 1 BLOCK 5000` for one new message → process → XACK

**Two Redis streams — same read pattern:**
- `transactions` — `XREADGROUP` on group `sentinel-consumers`
- `synapse:axioms` — `XREADGROUP` on group `axiom-workers`

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/EmbeddingService.php` | Fingerprint creation + Gemini embedding calls |
| `app/Services/VectorCacheService.php` | Upstash Vector search/upsert/delete |
| `app/Services/TransactionProcessorService.php` | Core pipeline — cache hit/miss/fallback logic |
| `app/Services/ThreatAnalysisService.php` | Tier 3 rule-based fallback |
| `app/Services/AxiomProcessorService.php` | Axiom pipeline — threshold routing + ComplianceEvent persistence |
| `app/Services/AxiomStreamService.php` | XREADGROUP/XAUTOCLAIM wrapper for `synapse:axioms` |
| `app/Services/ComplianceManager.php` | Laravel Service Manager — resolves `gemini` or `openrouter` driver |
| `app/Contracts/ComplianceDriver.php` | Driver interface: `analyze(array $data): array` |
| `app/Providers/AppServiceProvider.php` | Binds `ComplianceDriver` → `ComplianceManager::driver()` |
| `app/Console/Commands/` | Artisan commands (stream, watch, watch-axioms, ingest, reset-metrics) |
| `app/Http/Controllers/ComplianceController.php` | Compliance events page — paginated, flagged/all toggle |
| `app/Mcp/Servers/SentinelServer.php` | MCP server — exposes AnalyzeTransaction, SearchPolicies, GetRecentTransactions |
| `resources/js/Pages/` | Inertia page components (.jsx) |
| `resources/js/components/ui/` | shadcn/ui components (owned in-repo) |
| `config/sentinel.php` | AI driver, axiom threshold, simulation merchants/currencies |
| `config/features.php` | Feature flags (off in prod, on elsewhere) |
| `routes/ai.php` | MCP server route registration |
| `docs/adr/` | Architecture decision records |

## DI Wiring

`AppServiceProvider` binds `ComplianceDriver::class` to the result of `ComplianceManager::driver()`, which reads `SENTINEL_AI_DRIVER` from env. Both `GeminiDriver` and `OpenRouterDriver` implement `ComplianceDriver`. Switch drivers without code changes:

```env
SENTINEL_AI_DRIVER=gemini      # default
SENTINEL_AI_DRIVER=openrouter  # alternative
```

## Domain Logic Isolation

`App\Services\Sentinel\Logic` must not use `Http` or `Redis` facades directly. Enforced by arch tests in `tests/ArchTest.php`. All external I/O must go through injected interfaces. If you add to this namespace, run `./vendor/bin/pest tests/ArchTest.php` before assuming it's clean.

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
- Policy epoch invalidation: `TransactionProcessorService` checks `sentinel_policy_epoch` (a plain Redis cache key) on every cache hit. If the cached result's epoch doesn't match the current epoch, it is discarded and re-analyzed. Update this key after re-ingesting the policy KB.

## Database

- Neon PostgreSQL via non-pooled host (`DB_HOST`). The PgBouncer pooler endpoint breaks `SELECT ... FOR UPDATE SKIP LOCKED`, which Laravel's queue driver requires. See ADR-0010.
- Never point `DB_HOST` at the `-pooler.` Neon endpoint.

## Metrics

Dashboard stats are stored as Redis cache keys (`sentinel_metrics_*`). Reset with:

```bash
php artisan sentinel:reset-metrics
```

These are separate from Redis Streams — plain key/value `SET`/`GET`, not stream data structures.

## Testing

- Never hit real external APIs in tests — mock at the service interface boundary.
- Architecture tests live in `tests/ArchTest.php`; run them after any change to `App\Services\Sentinel\Logic` with `./vendor/bin/pest tests/ArchTest.php`.
- No frontend tests yet; Vitest + React Testing Library is the intended approach when added.
- Pre-existing known failures: `WatchTransactionsTest` mock (`mockStreamWithOneMessage` must return `{messages, cursor}` shape) and one `EmbeddingServiceTest`. Don't fix unrelated to current task.

## Prompts

All LLM prompt templates must live in `prompts/` as versioned Markdown files (`.md`). The runtime template (`compliance-audit-narrative.txt`) is the rendered form loaded by `GeminiDriver` — both `.md` (source) and `.txt` (runtime) exist for that prompt. When a prompt is created or changed:

- Create or update the file in `prompts/` (e.g. `prompts/my-prompt.md`)
- Increment the `**Version:**` field and add a changelog entry
- List every driver or service that uses the prompt under `**Used by:**`

Never hardcode a prompt only inside a service class without a corresponding `prompts/` file.

## ADR files

Create decision logs according to https://martinfowler.com/bliki/ArchitectureDecisionRecord.html. Current ADRs live in `docs/adr/` (0001–0022).

## TODO

- **Multi-tenancy** — tenant-scoped middleware on `routes/web.php` auth group + tenant-prefixed stream keys; placeholder comment exists in routes file
- **Compliance report export** — CSV/PDF export endpoint for flagged `compliance_events` by date range
- **EventHorizon deep-link** — cross-system lookup from `compliance_events.source_id` back to the originating EventHorizon event
- **Silent partial failure alerting** — connect `GeminiDriver`/`OpenRouterDriver` quality score and retrieval coverage logs to an operational alert (e.g. `quality_score=0` for N consecutive events, or zero-chunk filtered retrieval persists)
- **Retrieval coverage monitoring** — log mean similarity score per domain per query; declining scores signal knowledge base drift
- **Domain activation in Axiom pipeline** — `WatchAxioms` or Synapse-L4 emitter needs to stamp `domain` on each Axiom payload for domain-scoped RAG to activate; see ADR-0018
- **Backpressure dashboard** — surface `sentinel:consumer_lag` on the metrics dashboard (the key is already written by the worker; just needs a UI widget)
- **End-to-end idempotency audit** — (1) audit that EventHorizon event ID survives as `source_id` through Synapse-L4 onto the Axiom; (2) add early-exit `EXISTS` check in `AxiomProcessorService` before AI call so duplicate `source_id`s skip Gemini entirely. DB-layer dedup already exists at line 114 but fires too late.

## Claude Code Workflow Notes

- **Work one step at a time** and pause for confirmation before moving to the next build step.
- **Commit after each logical step** — the user commits manually; don't push. Do provide a commit message for the user.
- **Don't add features beyond what's asked.** No extra error handling, no extra abstractions, no unrequested refactors. Write todos instead. Note these in suggested commit msg.
- **After every completed step: update README.md and LEARNING_LOG.md** — this is mandatory, not optional. README: add a new checked item to the Status section (done-only list), add any new forward work to "What's still ahead", and correct any stale architecture descriptions. LEARNING_LOG: append a new phase entry (see format below). Do both before suggesting a commit message.
- **Maintain `LEARNING_LOG.md`**: After each phase, append new entries for every pattern used, anti-pattern avoided, challenge encountered, or design decision made. Use the established entry format (Pattern / Anti-Pattern / Challenge / Decision sections with **Q:**/**A:** flashcard blocks).
- **`LEARNING_LOG.md` is referred to as `ll`** in conversation — treat "ll" as shorthand for `LEARNING_LOG.md`.
- **Challenges are mandatory in every log entry**: Every phase entry must include a `### Challenges` section. If no challenge was encountered, state that explicitly — do not omit the section.
