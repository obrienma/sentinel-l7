# LEARNING_LOG — Sentinel-L7

A running record of patterns used, anti-patterns avoided, challenges encountered, and design decisions made across each build phase.

Format: each phase entry has **Patterns**, **Anti-Patterns**, **Challenges**, and **Decisions** sections with **Q:**/**A:** flashcard blocks.

---

## Phase: Stream Simulator — sentinel:stream
*Commits: `9697431`, `d0d8375` | Date: 2026-02-09 – 2026-02-19*

### Summary
Built `sentinel:stream`, an Artisan command that seeds Redis Streams with synthetic transactions. Added an idempotency guard (`SETNX` + 24h TTL) so re-running the command doesn't publish duplicates.

---

### Patterns

**Graceful shutdown via `pcntl_signal`**
`StreamTransactions` registers `SIGINT`/`SIGTERM` handlers that flip a `$running` flag. The loop checks the flag each iteration instead of calling `exit()` directly. This lets the current iteration finish cleanly before the process stops.

**Q:** How does `sentinel:stream` handle CTRL-C without mid-write data corruption?
**A:** `pcntl_signal(SIGINT, ...)` sets a `$running = false` flag. The `while ($running)` loop finishes the current iteration naturally rather than being killed mid-XADD.

---

**`SETNX` for idempotency before stream writes**
Before calling `XADD`, `TransactionStreamService::publish()` does a `SETNX sentinel:seen:{id}` with a 24-hour TTL. If the key already existed the publish is skipped and `false` is returned. The command logs the skip. This prevents replaying a transaction through the compliance pipeline twice.

**Q:** Why use `SETNX` rather than checking the stream for the existing message ID?
**A:** Scanning a stream for a specific message ID requires `XRANGE` and is O(N). `SETNX` is O(1) and lives in a dedicated key namespace, keeping the check independent of stream length.

---

### Anti-Patterns

**Hardcoded stream key strings scattered across commands**
Resolved by keeping the stream key (`sentinel:transactions`) as a class constant on `TransactionStreamService` and reading it only there. Commands that needed the key got it by calling the service, not by repeating the string.

**Q:** Why should stream key strings be constants on a service class rather than repeated in commands?
**A:** A single source of truth. Renaming the key later means one change, not a grep through every command file.

---

### Challenges

**`pcntl` extension not enabled by default in some PHP Docker images**
The signal handlers required `pcntl_signal`, which is compiled into PHP but needs the extension enabled. On Render's build the extension was present; locally it needed confirming. The symptom of a missing `pcntl` is a silent no-op — the signal handler is never registered, so CTRL-C kills the process abruptly.

**Q:** What happens if `pcntl` is not available when `sentinel:stream` runs?
**A:** The signal handlers are silently not registered. CTRL-C sends SIGINT directly to the process, which terminates immediately without a clean loop exit.

---

### Decisions

**`--limit` and `--speed` options with sensible defaults**
`--limit=10` (transactions per run) and `--speed=1000` (ms between each) are configurable via the command signature. The defaults are conservative enough to not spam a dev Redis instance, but operators can push `--limit=100 --speed=100` for load testing without code changes.

---

## Phase: Real-time Watcher + Threat Analysis — sentinel:watch
*Commits: `36ed585` | Date: 2026-02-18*

### Summary
Added `WatchTransactions` (an infinite `XREAD` loop) and `ThreatAnalysisService` (a rule-based L7 compliance checker). This was the first end-to-end pipeline: stream producer → Redis → consumer → threat verdict → CLI output.

---

### Patterns

**Rule-based tier-3 fallback**
`ThreatAnalysisService` uses pure PHP rules (amount thresholds, category flags) — no network calls. This makes it safe to run as the final fallback if embedding or vector search fails. It always returns a result.

**Q:** Why is `ThreatAnalysisService` kept as a rule-based service with no I/O?
**A:** It's the tier-3 fallback — it must return a result even if every upstream service is down. No network calls, no exceptions.

---

**Consumer loop with cursor advancement**
`WatchTransactions` tracks a `$lastId` cursor and passes it to each `XREAD` call. This ensures the consumer never re-reads a message it already processed, even after a restart (as long as the cursor is preserved for the session).

**Q:** What value should you pass to `XREAD` on the very first call, and why does it change on subsequent calls?
**A:** Pass `$` on the first call to receive only *new* messages from that point forward. On subsequent calls, pass the ID of the last received message so you don't re-process anything already seen.

---

### Anti-Patterns

**`XREAD` without a cursor = infinite replay**
An early version polled from `0` (the start of the stream) on every loop iteration, reprocessing all historical messages each time. Switching to cursor-based reads eliminated this.

**Q:** What happens if you pass `0` as the ID to every `XREAD` call in a watch loop?
**A:** You re-read every message from the beginning of the stream on every iteration. All historical transactions get reprocessed repeatedly.

---

### Challenges

**No challenge was encountered that wasn't resolved by the cursor fix above.** The initial implementation was straightforward; the cursor anti-pattern was caught immediately during the first run when duplicate CLI output appeared.

---

### Decisions

**`ThreatAnalysisService` returns a value object, not an array**
The result of `analyze()` is a plain PHP object with `$isThreat` and `$message` public properties. This gave call sites named access (`$result->isThreat`) without the overhead of a formal class hierarchy — a balance between readability and simplicity.

---

## Phase: Semantic Cache — Vectorize
*Commits: `391b8c3`, `1511fbc` | Date: 2026-02-20*

### Summary
Added `EmbeddingService` (Gemini `embedding-001`, 1536-dim) and `VectorCacheService` (Upstash Vector REST API). Wired them into `WatchTransactions`: embed fingerprint → vector search → cache hit returns early; miss → `ThreatAnalysisService` → upsert result. Added the first significant Pest test suite (615 lines across 3 files).

---

### Patterns

**Semantic fingerprint as cache key**
Instead of caching on a transaction ID (exact match), the fingerprint is a text string of semantic fields (`Amount | Type | Category | Time | Merchant`) that is embedded to a vector. Two transactions with the same risk profile but different IDs can share a cache entry if their vectors are within the similarity threshold.

**Q:** Why does the semantic cache use a text fingerprint rather than the transaction ID as its key?
**A:** To capture *similar* transactions, not just identical ones. Two different transactions with the same merchant, category, and amount tier should reuse the same compliance verdict — that's the whole point of semantic caching.

---

**Three-tier pipeline: cache hit → full analysis → rule fallback**
The pipeline in `WatchTransactions` (later extracted to `TransactionProcessorService`) has three tiers:
1. Vector cache hit → return stored verdict
2. Cache miss → Gemini Flash analysis → upsert result
3. Any exception in tier 1 or 2 → `ThreatAnalysisService` rule check

Every transaction gets a verdict. No transaction is silently dropped.

**Q:** What is the tier-3 fallback in the compliance pipeline, and when does it trigger?
**A:** `ThreatAnalysisService` (rule-based, no I/O). It triggers when either the embedding call or the vector search throws an exception — ensuring a verdict is always returned.

---

**`Cache::increment` for lightweight metrics**
Hit count, miss count, fallback count, and cumulative latency are tracked with `Cache::increment('sentinel_metrics_*')`. No dedicated metrics table, no time-series DB. Fast, zero-schema, easily resettable.

**Q:** Where are sentinel pipeline metrics stored, and how are they reset?
**A:** In Redis as plain key/value pairs via `Cache::increment`. Reset with `php artisan sentinel:reset-metrics`.

---

### Anti-Patterns

**Unwrapping the Upstash response without checking the envelope**
The initial `VectorCacheService` accessed `$response->json('results')` directly. Upstash wraps vector results in a `{"result": [...]}` envelope — the correct key is `result`, not `results`. This produced silent null returns (no match found) rather than an error, which masked the bug for a while.

**Q:** What is the correct key to unwrap from an Upstash Vector query response?
**A:** `result` (singular). The response shape is `{"result": [{"id": ..., "score": ...}]}`. Using `results` (plural) returns null silently.

---

**Using exact timestamps in the fingerprint**
The original fingerprint included `date('H:i', ...)` — a precise `HH:MM` string. Two identical transactions one minute apart would embed to different vectors and never share a cache entry. Replaced in a later phase with time-of-day buckets.

**Q:** Why was the exact `HH:MM` timestamp removed from the transaction fingerprint?
**A:** Two semantically identical transactions one minute apart would produce different fingerprints and never hit the cache. Time-of-day buckets (night/morning/afternoon/evening) preserve compliance-relevant time context without killing hit rate.

---

### Challenges

**Gemini embedding API dimension mismatch**
Gemini `embedding-001` defaults to 768 dimensions. Upstash Vector namespaces are created with a fixed dimension at setup time. An early namespace was created at 768 dims; the service was later configured for 1536 with `"output_dimensionality": 1536`. Inserting 1536-dim vectors into a 768-dim namespace throws a 400 from Upstash. Resolution: delete the namespace and recreate at 1536 dims.

**Q:** What happens if you try to upsert a 1536-dimension vector into an Upstash namespace created for 768 dimensions?
**A:** Upstash returns a 400 error. Namespaces have a fixed dimension set at creation time. You must delete and recreate the namespace, or create a new one.

---

**`Http::fake()` must be called before the service is instantiated**
In early tests, `Http::fake([...])` was called after `new EmbeddingService()`. The fake was registered too late — the service had already captured the real HTTP client. Moving `Http::fake()` to the top of the test (before any `new` calls) fixed it.

**Q:** When must `Http::fake()` be called relative to service instantiation in Laravel tests?
**A:** Before instantiation. `Http::fake()` swaps the underlying client at the facade level — if you construct the service first, it may capture a reference to the real client before the fake is registered.

---

### Decisions

**Similarity threshold of 0.95 for the transaction cache**
Set conservatively high to avoid false positives — returning a cached verdict for a transaction that is *similar-but-not-same* could mean a real threat gets a pass. The trade-off is a lower hit rate. ADR-0015 documents this and notes that 0.90 is under evaluation.

**Q:** Why is the semantic cache similarity threshold set to 0.95 rather than something lower like 0.80?
**A:** Conservative choice to avoid false positives. A transaction that's 80% similar could have compliance-relevant differences. The cost of a false positive (caching a safe verdict for a threat) is higher than the cost of a cache miss (redundant AI call). ADR-0015 tracks this.

---

## Phase: Service Hardening — Retries, Timeouts, Observability
*Commit: `45301d8` | Date: 2026-02-27*

### Summary
Added retry-with-backoff (3× @ 200ms for embedding, 2× @ 150ms for vector), explicit HTTP timeouts (10s / 5s), `Log::warning` on all failure paths, and `VectorCacheService::delete()` for cache eviction. Test suite doubled (34 → 76 tests).

---

### Patterns

**Retry with exponential/fixed backoff before throwing**
Both `EmbeddingService::embed()` and `VectorCacheService` retry on transient failures before propagating the exception. The retry count and delay are small — this isn't a job queue; the command loop is synchronous and latency matters.

**Q:** How many times does `EmbeddingService::embed()` retry on failure, and what is the delay?
**A:** 3 attempts, 200ms between each. On exhaustion the exception propagates to the pipeline's try/catch, triggering the tier-3 fallback.

---

**`Log::warning` as the observability hook for every failure path**
Rather than letting exceptions propagate silently into the fallback, every catch block logs a warning with the service name and error message. This produces a searchable trail in Railway's log drain without requiring a dedicated error tracking service.

**Q:** What observability mechanism does Sentinel use for transient service failures (embedding, vector search)?
**A:** `Log::warning` with structured context (service name, error message). No dedicated APM — logs are the primary signal.

---

### Anti-Patterns

**No timeout on external HTTP calls**
The initial `EmbeddingService` and `VectorCacheService` used `Http::post()` with no timeout. A hung Gemini or Upstash request would block the worker process indefinitely. Fixed by adding `->timeout(10)` and `->timeout(5)` respectively.

**Q:** What is the risk of not setting a timeout on `Http::post()` calls to external APIs?
**A:** The worker process blocks indefinitely on a hung connection. In a `while(true)` daemon loop, this silently stalls all subsequent transactions.

---

### Challenges

**Testing retries without sleeping**
Retry tests need the service to fail N-1 times and succeed on attempt N. Using real `sleep()` in tests would make the suite slow. The fix was to mock `Http::fake()` with a sequence: N-1 failure responses followed by a success response. Laravel's `Http::sequence()` handles this cleanly.

**Q:** How do you test retry logic in Laravel without slowing down the test suite with real sleep delays?
**A:** Use `Http::sequence()` to return a series of fake responses — failures first, then a success. The service retries against the sequence without any real network or sleep.

---

### Decisions

**`VectorCacheService::delete()` added speculatively but justified**
Delete wasn't needed by any current caller, but it's required for cache invalidation when a compliance policy changes (all cached verdicts under the old policy become stale). Added as part of hardening rather than waiting for the use case to arrive.

---

## Phase: Vue 3 → React 19 + shadcn/ui Migration
*Commit: `4b3a1cd` | Date: 2026-03-09*

### Summary
Replaced Vue 3 + `@inertiajs/vue3` with React 19 + `@inertiajs/react` + shadcn/ui (New York style, slate base). Migrated `Home.vue` → `Home.jsx`. Configured Vite, `jsconfig.json`, Tailwind v4, and dark palette defaults.

---

### Patterns

**`React.createElement` in `app.js` to avoid `.jsx` extension requirement**
The Inertia entry point (`app.js`) uses `React.createElement(App, props)` instead of JSX. This lets `@vite('resources/js/app.js')` work in the Blade template without needing a `.jsx` extension on the entry file. All page components use JSX normally.

**Q:** Why does `app.js` use `React.createElement` instead of JSX?
**A:** The Blade `@vite()` directive references the file by its literal filename. `.js` files aren't processed as JSX by Vite unless explicitly configured. Using `React.createElement` in the entry point avoids needing to rename it to `app.jsx` and reconfigure the blade template.

---

**`@viteReactRefresh` before `@vite()` in Blade**
React Fast Refresh requires its runtime to be injected before the application bundle. If `@vite(...)` appears first, HMR won't work.

**Q:** What breaks if `@vite(...)` appears before `@viteReactRefresh` in `app.blade.php`?
**A:** React Fast Refresh (HMR) stops working. The refresh runtime must be injected before the app bundle loads.

---

**`jsconfig.json` for shadcn CLI path resolution**
The shadcn CLI reads `jsconfig.json` (or `tsconfig.json`) to resolve path aliases when adding components. Without it, `npx shadcn@latest add button` can't find the `@/components/ui/` target and fails silently or scaffolds to the wrong path.

**Q:** Why is `jsconfig.json` required in a non-TypeScript project using shadcn?
**A:** The shadcn CLI uses it to resolve the `@` path alias when scaffolding component files. Without it, the CLI can't determine where `@/components/ui/` maps to on disk.

---

**Tailwind v4: config-in-CSS, no `tailwind.config.js`**
Tailwind v4 reads theme configuration from `@theme inline { ... }` blocks in `app.css`. There is no `tailwind.config.js`. The dark palette is set directly on `:root` (not behind a `.dark` class) so the dark theme is always active without a JS toggle.

**Q:** In Tailwind v4, where does the theme configuration live?
**A:** In CSS, inside an `@theme inline { ... }` block in `app.css`. There is no `tailwind.config.js` in a v4 project.

---

### Anti-Patterns

**Duplicate `createInertiaApp` call in `app.js`**
The migration commit shows a second `createInertiaApp` block was left in by accident (visible in the diff). It was a copy-paste remnant from the Vue version that wasn't cleaned up. It caused a double-mount in development. Removed before merging.

**Q:** What is the symptom of calling `createInertiaApp` twice in `app.js`?
**A:** The Inertia app mounts twice on the same DOM node, causing React reconciliation errors and double-rendering in development.

---

### Challenges

**shadcn components are *owned* — not node_modules**
`npx shadcn@latest add button` copies source files into `resources/js/components/ui/`. They are not in `node_modules` and are committed to git. This surprised the first time — a `git status` after adding a component showed new tracked files, not just package.json changes. The benefit is full ownership (customize freely); the cost is that upstream shadcn updates are opt-in manual copies.

**Q:** Where do shadcn components live after `npx shadcn@latest add <component>`?
**A:** In `resources/js/components/ui/` as first-party source files committed to the repo — not in `node_modules`. They are owned and customized in-repo.

---

### Decisions

**New York style, slate base, dark-always palette**
New York style uses tighter padding and a border-radius closer to a product aesthetic than the default style. Slate was chosen over zinc/gray for its slightly cooler tone. Dark-always (on `:root`, not `.dark`) avoids needing a theme-toggle mechanism that wasn't planned.

---

## Phase: Auth + Shared Props + Dashboard + Arch Tests
*Commits: `983842b`, `cde1c8e`, `a7269e5`, `f33560f`, `8cf7b06` | Date: 2026-03-09*

### Summary
Added Laravel Breeze auth, wired Inertia shared props via `HandleInertiaRequests` middleware, built the live-polling dashboard (flags raised, metrics), and introduced the first arch tests. The compliance pipeline became visible on the dashboard with real-time polling.

---

### Patterns

**Inertia shared props via middleware**
`HandleInertiaRequests::share()` injects data available to every page component (auth user, flash messages, feature flags). Page components receive it via `usePage().props` without explicit prop-drilling from the controller.

**Q:** How do you make data available to every Inertia page without passing it from every controller action?
**A:** Add it to the `share()` method in `HandleInertiaRequests` middleware. It's merged into every Inertia response automatically.

---

**Pest arch tests as living architecture contracts**
`tests/ArchTest.php` uses `arch()->expect('App\Http\Controllers')->not->toUse(...)` assertions. These run in CI and fail if a new class violates the rule — e.g., a controller using the Http facade directly. The arch tests document *intent*, not just *current state*.

**Q:** What does an arch test assertion like `->not->toUse('Illuminate\Support\Facades\Http')` actually check?
**A:** At test-run time, Pest's architecture plugin inspects the compiled PHP AST to verify no class in the target namespace imports or calls the specified facade. It's a static analysis check, not a runtime assertion.

---

**Feature flags in `config/features.php`**
A dedicated config file holds boolean flags keyed by feature name. The `DashboardController` reads them to decide whether to show certain UI sections. Flags default to `false` in production and `true` in development via env vars. This lets partially-built features ship without being visible.

**Q:** Where do feature flags live in Sentinel-L7, and how are they toggled between environments?
**A:** `config/features.php`. Each flag reads from an env var (e.g., `FEATURE_SHOW_MCP_PANEL`). Defaults are `false` in production, `true` in development.

---

### Anti-Patterns

**Polling from the frontend instead of websockets**
The dashboard uses `setInterval` to re-fetch metrics every few seconds. This is simpler than setting up Laravel Echo + Reverb but adds unnecessary HTTP round-trips when metrics haven't changed. Documented as a known trade-off; acceptable for the current load.

**Q:** What is the downside of using `setInterval` polling for the live dashboard instead of a websocket?
**A:** Unnecessary HTTP requests when nothing has changed. At scale this adds server load and increases perceived latency vs. a push-based approach. Acceptable for a low-traffic dashboard; revisit with Laravel Echo + Reverb if needed.

---

### Challenges

**Neon PostgreSQL pooler breaks `SELECT ... FOR UPDATE SKIP LOCKED`**
Laravel's database queue driver uses `SELECT ... FOR UPDATE SKIP LOCKED` to claim jobs without locking contention. Neon's PgBouncer pooler endpoint (`-pooler.` hostname) doesn't support advisory locks, causing queue jobs to fail silently. Resolution: switch `DB_HOST` to the non-pooler Neon endpoint. Documented in ADR-0010.

**Q:** Why must `DB_HOST` point to the non-pooler Neon endpoint?
**A:** Laravel's queue driver uses `SELECT ... FOR UPDATE SKIP LOCKED`. Neon's PgBouncer pooler doesn't support this advisory lock syntax and silently drops the jobs. ADR-0010 documents this.

---

### Decisions

**Dashboard metrics stored as Redis cache keys, not a DB table**
`sentinel_metrics_*` keys are plain `Cache::increment` values. No migration, no schema. Resettable in one command (`sentinel:reset-metrics`). The trade-off is no history — but dashboard counters are meant to show *current session* activity, not a historical record.

---

## Phase: Extract TransactionProcessorService
*Commit: `a499d91` | Date: 2026-03-09*

### Summary
Pulled the per-transaction compliance pipeline out of `WatchTransactions` and into `TransactionProcessorService`. Added `ProcessStreamJob` to wrap the service for queued dispatch. `WatchTransactions` became a thin display loop.

---

### Patterns

**Command → Service → Job extraction**
Before this refactor, all pipeline logic lived in `WatchTransactions::handle()`. After: the command is a thin loop that calls `$processor->process($data)` and formats CLI output. `ProcessStreamJob` calls the same service for queue-based dispatch. One service, two entry points, zero duplication.

**Q:** What is the "Command → Service → Job" pattern, and why does it matter here?
**A:** The command handles I/O (stream reads, CLI output). The service holds business logic. The job wraps the service for async dispatch. All three exist as separate classes. Adding a third entry point (e.g., an HTTP endpoint) requires no changes to the service.

---

**`$observe` flag to suppress metrics from non-pipeline callers**
`TransactionProcessorService::process()` accepts `bool $observe = true`. MCP tool calls pass `observe: false` to skip `Cache::increment` and Redis feed writes. Vector cache upserts still run — they benefit all callers regardless of observation mode.

**Q:** What does `$observe = false` do in `TransactionProcessorService::process()`?
**A:** Suppresses `Cache::increment` metric updates and Redis feed writes. The compliance analysis and vector cache upsert still run. Used by MCP tools so agent calls don't inflate dashboard metrics.

---

### Anti-Patterns

**Business logic in Artisan command `handle()` methods**
The pre-refactor `WatchTransactions::handle()` contained 100+ lines of pipeline logic — embedding calls, cache checks, analysis, upserts, metric recording. This was untestable without spinning up the full command. Extracting to a service made the logic unit-testable in isolation.

**Q:** Why is it an anti-pattern to put compliance pipeline logic directly in an Artisan command's `handle()` method?
**A:** Commands are entry points, not services. Logic in `handle()` can only be tested by invoking the full command via `$this->artisan(...)`. Extracting to a service means the logic can be tested directly with a `new TransactionProcessorService(...)` call.

---

### Challenges

**No challenge was encountered during this refactor** beyond ensuring the extracted service's return shape matched what `WatchTransactions` expected for its CLI formatting. The existing test suite caught one field name discrepancy (`threat_level` vs `is_threat`) immediately.

---

### Decisions

**`ProcessStreamJob` is a thin wrapper with no logic of its own**
The job's `handle()` method calls `$processor->process($this->data)` and nothing else. No retry logic, no error handling — that belongs in the service. The job exists only to enable queued dispatch without duplicating service logic.

---

## Phase: MCP Server
*Commits: `063b6f9`, `442a59f` | Date: 2026-03-23*

### Summary
Exposed the compliance pipeline as MCP tools via `laravel/mcp`. Three tools: `AnalyzeTransaction`, `SearchPolicies`, `GetRecentTransactions`. Fixed three architectural issues found immediately after the initial implementation: duplicate Upstash client, unthrottled public endpoint, and metrics pollution from agent calls.

---

### Patterns

**MCP tools as thin wrappers over existing services**
Each tool validates input, calls one service method, and returns a JSON response. No business logic lives in the tool class. `AnalyzeTransaction` calls `$processor->process($data, observe: false)`. `SearchPolicies` calls `$vectorCache->searchNamespace(...)`. The pipeline is unchanged — the tool is just another entry point.

**Q:** What is the responsibility of an MCP tool class in Sentinel-L7?
**A:** Input validation, one service call, JSON response. No business logic. The tool is an entry point — the service owns the logic.

---

**Multi-hop agent retrieval via MCP**
An agent calling the MCP server can first call `search_policies` to retrieve the applicable regulatory policy chunks, then call `analyze_transaction` with that context. This is richer than a single-shot analysis because the agent constructs the context rather than receiving a pre-built prompt.

**Q:** What is the advantage of the MCP multi-hop pattern over sending a pre-built prompt to an AI?
**A:** The agent controls what context it includes. It can retrieve policy chunks relevant to the *specific* transaction before analyzing it, rather than receiving a generic prompt that may include irrelevant policy text.

---

**Rate limiting an unauthenticated endpoint**
`routes/ai.php` applies `throttle:60,1` to the `/mcp` route. This is the minimum viable protection for a public endpoint. A comment marks the `auth:sanctum` upgrade path for when token auth is needed.

**Q:** What middleware protects the `/mcp` endpoint, and what does `throttle:60,1` mean?
**A:** `throttle:60,1` — 60 requests per 1 minute per IP. It's the minimum viable rate limit for a public unauthenticated endpoint.

---

### Anti-Patterns

**Duplicating Upstash client logic in a tool class**
The initial `SearchPolicies` contained a raw `Http::withHeaders(...)->post(...)` call to Upstash, duplicating the logic already in `VectorCacheService`. This was caught in the follow-up refactor commit. The fix was to add `VectorCacheService::searchNamespace()` and route the tool through it.

**Q:** What was the architectural issue with the original `SearchPolicies` tool?
**A:** It contained a raw HTTP call to Upstash, duplicating the client logic already in `VectorCacheService`. The fix was to add `searchNamespace()` to the service and delete the duplicate from the tool.

---

**Agent tool calls polluting dashboard metrics**
The initial `AnalyzeTransaction` used the default `process()` call, which increments `Cache::increment` metric counters and pushes to the recent transactions feed. Every agent call inflated the "transactions processed" count on the dashboard. Fixed by adding the `$observe` flag.

**Q:** Why would an MCP tool call inflate the Sentinel dashboard transaction count?
**A:** `TransactionProcessorService::process()` calls `Cache::increment(...)` and pushes to the Redis feed on every run. MCP tool calls from agents are not real user-originated transactions — passing `observe: false` suppresses these side effects.

---

### Challenges

**`Http::fake()` vs `Redis::shouldReceive()` in the same test**
MCP tool tests need both HTTP fakes (for Gemini embedding calls) and Redis mocks (for feed writes). Laravel's `Http::fake()` and Mockery `Redis::shouldReceive()` can coexist, but order matters: Http::fake() must be set before any code that calls Http, and Redis expectations must be set before any code that resolves Redis. Setting both at the top of each test before instantiating any service avoids ordering bugs.

**Q:** What ordering rule applies when using both `Http::fake()` and `Redis::shouldReceive()` in the same test?
**A:** Both must be registered before any service that uses them is instantiated or called. Set all fakes/mocks at the very top of the test, before any `new` calls or `$this->app->make()` resolution.

---

### Decisions

**`routes/ai.php` as a dedicated route file for AI/agent endpoints**
Rather than adding the MCP route to `api.php` or `web.php`, a new `routes/ai.php` was created and registered in `bootstrap/app.php`. This keeps AI-facing endpoints in one place for auth and rate-limit policy review.

---

## Phase: Fingerprint Bucketing
*Commit: `48b83bd` | Date: 2026-03-28*

### Summary
Replaced exact dollar amounts with five magnitude tiers (`micro/small/medium/large/very_large`) and exact `HH:MM` timestamps with four time-of-day buckets (`night/morning/afternoon/evening`) in the transaction fingerprint. Added `sentinel:reset-metrics` command. This directly improves vector cache hit rate.

---

### Patterns

**`match(true)` for range bucketing**
PHP's `match(true)` expression cleanly maps a numeric range to a label without nested if/else. The expression is evaluated top-to-bottom; the first arm whose condition is truthy wins.

```php
match(true) {
    $amount < 10    => 'micro',
    $amount < 100   => 'small',
    $amount < 500   => 'medium',
    $amount < 2000  => 'large',
    default         => 'very_large',
};
```

**Q:** Why use `match(true)` for bucketing instead of `if/elseif`?
**A:** `match(true)` is an expression (can be assigned), has no fall-through, and is exhaustive (requires a `default` arm or throws `UnhandledMatchError`). It's more concise and harder to accidentally omit a branch.

---

**Fingerprint logging with `Log::debug`**
`EmbeddingService::createTransactionFingerprint()` logs the generated fingerprint string at `debug` level. In development this lets you confirm the bucketing is working (e.g., `$9.50` → `micro`) without needing a test. In production the debug log level is typically suppressed.

**Q:** How can you verify that amount bucketing is working correctly in a running development environment without writing a test?
**A:** Check the debug log. `EmbeddingService` logs the final fingerprint string via `Log::debug('[Sentinel] Fingerprint: ...')` each time one is generated.

---

### Anti-Patterns

**Exact amounts in semantic fingerprints**
A fingerprint like `Amount: 9.99 USD` embeds to a different vector than `Amount: 10.01 USD`, even though both are compliance-equivalent. The semantic cache never fires for near-identical transactions that differ only in cents. Bucketing resolves this.

**Q:** Why do exact dollar amounts in a fingerprint harm vector cache hit rate?
**A:** Two amounts that are semantically equivalent for compliance purposes (e.g., $9.99 and $10.01) produce different text strings, embed to different vectors, and never match above the similarity threshold. Bucketing collapses them to the same tier string.

---

### Challenges

**No challenge was encountered.** The bucketing logic was straightforward, and the fingerprint logging made it easy to verify the output before running tests. The pre-existing `EmbeddingServiceTest` suite caught a boundary condition (exactly `$10.00` landing in `small`, not `micro`) on the first test run.

---

### Decisions

**Five amount tiers chosen for compliance relevance**
The tier boundaries (`<10`, `<100`, `<500`, `<2000`, `≥2000`) loosely map to AML reporting thresholds and card-network micro-transaction categories. They're not arbitrary round numbers — each tier represents a meaningfully different compliance risk band.

**Q:** Why were the five amount tiers chosen at those specific boundaries?
**A:** They map to compliance-relevant thresholds: sub-$10 micro-transactions (e.g., contactless tap), sub-$100 everyday spend, sub-$500 mid-range, sub-$2000 large purchase, $2000+ potentially CTR-relevant territory.

---

## Phase: MCP Prompt Versioning
*Changes: `prompts/mcp-*.md` | Date: 2026-04-01*

### Summary
Added `prompts/` files for all three MCP tool descriptions (`AnalyzeTransaction`, `SearchPolicies`, `GetRecentTransactions`). The `description` property on each tool class is what an AI agent reads to decide which tool to call and how — it's a prompt, not just a docstring, and belongs under version control like any other prompt asset.

---

### Patterns

**MCP tool `description` as a first-class prompt**
The `protected string $description` on a Laravel MCP `Tool` class is serialised into the tool list sent to the calling agent. It determines whether the agent calls the tool at all, how it phrases its arguments, and how it interprets the result. Treating it as documentation (set once, never reviewed) is a mistake — it should be versioned, iterated, and tested like a prompt.

**Q:** Why is a `Tool::$description` string a prompt rather than documentation?
**A:** It's the text the agent reads when deciding which tool to use and how to call it. The wording directly affects tool selection, argument construction, and result interpretation — the same levers as any other prompt. Changes to it change model behaviour.

---

**Separate threshold rationale for RAG vs. semantic cache**
The `mcp-search-policies.md` prompt file documents *why* the 0.70 threshold differs from the 0.95 cache threshold, co-located with the description that exposes it to agents. This prevents future confusion ("why is it lower?") from triggering an uninformed change.

**Q:** Why is the policy retrieval threshold (0.70) lower than the semantic cache threshold (0.95)?
**A:** Different purposes. The cache threshold is for near-duplicate detection — two transactions must be nearly identical to share a cached verdict. The policy threshold is for topical retrieval — a compliance question and the policy paragraph that answers it embed at naturally lower similarity because they're different kinds of text.

---

### Anti-Patterns

**Treating description strings as inert documentation**
A `$description` that says "returns entries from the live feed, newest first" tells the agent something useful about ordering. One that only said "get transactions" would not. Vague descriptions cause agents to skip tools they should use, or to mis-sequence multi-hop calls. The description is the interface contract between the tool and any agent that consumes it.

---

### Challenges

No unexpected challenge. The main decision was recognising that `description` strings are prompt assets — once that framing was clear, the action (add to `prompts/`, document the reasoning) was obvious.

---

### Decisions

**`prompts/` is a documentation layer, not loaded at runtime**
Prompt text lives inline in PHP (heredocs in `GeminiDriver`, `$description` in tool classes). The `prompts/` directory is a versioned reference — diffs are readable in git, the rationale is documented alongside the text, and the live copy is always in the PHP file. Loading from disk at runtime would add file I/O on every request for no behavioural gain.

---

## Phase: Policy RAG — sentinel:ingest + Query Formulation
*Changes: `SentinelIngest`, `VectorCacheService::upsertNamespace`, `GeminiDriver::buildQueryText` | Date: 2026-04-01*

### Summary
Completed the RAG pipeline by adding `sentinel:ingest` (chunks `.md` policy files and upserts into the `policies` Upstash namespace), `upsertNamespace()` on `VectorCacheService`, two seed policy documents, and a fix to `GeminiDriver::buildQueryText()` that reformulates the retrieval query from raw telemetry into compliance-vocabulary natural language.

---

### Patterns

**Paragraph-boundary chunking with word-count accumulation**
`SentinelIngest` splits text on two or more consecutive newlines (`\n{2,}`), accumulates paragraphs into a buffer, and flushes when the buffer exceeds `--chunk-size` words. This keeps semantically related sentences together (a paragraph is a unit of meaning) rather than cutting mid-sentence on a fixed character count.

**Q:** Why chunk on paragraph boundaries rather than a fixed character or token count?
**A:** A paragraph is a semantic unit — splitting mid-paragraph produces chunks where the first half has no topic sentence and the second half has no conclusion. Paragraph-boundary chunking keeps ideas whole, which improves embedding quality and retrieval relevance.

---

**Retrieval query as a compliance-vocabulary question**
The embedding sent to the `policies` namespace is not a description of the event but a question about obligations: *"What compliance obligations, reporting requirements, and regulatory thresholds apply to a critical anomaly event of critical severity requiring immediate escalation and reporting?"*

This works because embedding models are trained on Q&A-style text. A question about compliance obligations embeds close to policy text that *answers* compliance obligation questions. A telemetry description embeds close to other telemetry descriptions.

**Q:** Why does phrasing the RAG query as a natural-language question improve retrieval over embedding the raw event data?
**A:** Embedding models are trained on text where questions and their answers appear near each other. A question about "reporting requirements for critical anomalies" embeds in the same neighbourhood as policy chunks that describe those requirements. Raw telemetry (`status=critical, metric_value=94.0`) embeds near other telemetry strings, not policy text.

---

**Score-aware query formulation**
`buildQueryText()` uses `match(true)` to map `anomaly_score` ranges to severity phrases that appear in the query: `≥0.90` → `"immediate escalation and reporting"`, `≥0.80` → `"compliance review and possible regulatory notification"`, etc. A score-0.95 Axiom retrieves different policy chunks than a score-0.65 Axiom because the query text differs.

**Q:** How does `GeminiDriver::buildQueryText()` make the retrieval score-aware?
**A:** It maps the `anomaly_score` float to a severity phrase via `match(true)` and injects that phrase into the query string. Different score bands produce different natural-language severity descriptions, which embed to different positions in the vector space and retrieve different policy chunks.

---

**`upsertNamespace` mirrors `searchNamespace` endpoint convention**
Upstash Vector's namespace-scoped endpoints follow the pattern `/namespaces/{namespace}/{operation}`. `upsertNamespace` posts to `/namespaces/{namespace}/upsert` exactly as `searchNamespace` queries `/namespaces/{namespace}/query`. Consistent with the existing method rather than inventing a new convention.

**Q:** What Upstash Vector endpoint does `upsertNamespace` post to?
**A:** `/namespaces/{namespace}/upsert` — the same namespace path prefix as `/namespaces/{namespace}/query` used by `searchNamespace`.

---

### Anti-Patterns

**Embedding raw telemetry fields as the RAG query**
The original `buildQueryText()` embedded `"Anomaly detected: status=critical, metric_value=94.0, anomaly_score=0.91, source=sensor-42"`. This string is semantically distant from policy text about CTRs, SARs, and escalation thresholds. The retrieval threshold of 0.70 would rarely be met, so `fetchPolicyContext()` would silently return `[]` on almost every call — and `GeminiDriver` logs no warning for an empty result, only for exceptions. The RAG pipeline appeared to work but delivered no context.

**Q:** What is the silent failure mode when the RAG query embeds to the wrong semantic space?
**A:** `searchNamespace` returns an empty array (all scores below threshold), `fetchPolicyContext` returns `[]` without logging anything, and `buildPrompt` substitutes `"No specific policy context retrieved."`. The pipeline completes successfully with no observable error — it just produces a lower-quality narrative.

---

**Upsert into default namespace instead of `policies`**
The existing `upsert()` method posts to `/upsert` (the default namespace). Calling it for policy ingestion would mix policy vectors with transaction cache vectors in the same namespace, contaminating semantic cache lookups — a cache search for a transaction could return a policy chunk. A dedicated `upsertNamespace()` keeps the two corpora cleanly separated.

**Q:** What would happen if policy chunks were upserted into the default Upstash namespace instead of `policies`?
**A:** Transaction cache searches (`VectorCacheService::search()`) query the default namespace. A policy chunk about CTR thresholds could score above 0.95 against a transaction fingerprint, returning a policy text blob as a "cached compliance verdict" — corrupting the cache.

---

### Challenges

**No observable signal when RAG retrieval misses**
The most significant challenge was realising the RAG was silently ineffective. `GeminiDriver::fetchPolicyContext()` catches all `\Throwable` and logs a warning — but an empty result (below-threshold scores) is not an exception. It returns `[]` quietly. Without adding explicit logging or running an ingest and checking Gemini's output narrative for policy references, there's no way to know whether retrieval fired. The fix to query formulation was motivated by reasoning about semantic distance, not by an error message.

**Q:** How would you detect that RAG retrieval is silently returning no context in production?
**A:** Log the count of retrieved chunks in `fetchPolicyContext()` at `debug` level — `Log::debug('RAG retrieved N chunks', ['n' => count($chunks)])`. A persistent zero count signals a query formulation or corpus problem. Alternatively, check that `policy_refs` in the stored `audit_narrative` is non-empty after real runs.

---

### Decisions

**`--path=policies` default points to repo-root `policies/` directory**
Policy files are versioned alongside the code that uses them. `sentinel:ingest` defaults to `base_path('policies')` so running the command with no arguments Just Works from the repo root. The `--path` option exists for CI or staging environments that store policies elsewhere.

**Q:** Why are policy `.md` files committed to the repo rather than stored externally?
**A:** Versioning policy text in git means prompt changes, policy additions, and code changes are visible in the same diff. A policy update that changes retrieval behaviour is traceable to a specific commit.

**`--chunk-size=500` words as the default**
500 words (~650 tokens) fits comfortably within Gemini's context window even with 3 chunks retrieved (≈2000 tokens of policy context), leaves room for the Axiom details and the JSON schema instruction, and is large enough to keep a complete policy section together. It's configurable so future tuning doesn't require a code change.

---

## Phase: Synapse-L4 Phase 1 — Axiom Ingestion Foundation
*Commits: `7b48c14` → `623eb55` | Date: 2026-03-31 – 2026-04-01*

### Summary
Built the full Axiom ingestion pipeline: a dedicated Redis stream (`synapse:axioms`), a threshold-gated processor that routes high-anomaly events to AI, a `ComplianceDriver` contract with a Service Manager, a `WatchAxioms` console command, and 32 tests covering stream, processor, command, and architecture rules.

---

### Patterns

**Service Manager pattern (Laravel `Illuminate\Support\Manager`)**
`ComplianceManager` extends `Manager` and defines `createGeminiDriver()` / `createOpenrouterDriver()`. The active driver is resolved via `config('sentinel.ai_driver')`. Swapping backends requires no code change — only an env var.

**Q:** How do you add a new AI backend to `ComplianceManager` without touching call sites?
**A:** Add a `createFooDriver()` method on the manager, implement `ComplianceDriver::analyze()` in a new class under `App\Services\Compliance`, set `SENTINEL_AI_DRIVER=foo`. The `Manager` base class routes to the right `create*` method automatically.

---

**Threshold-gated routing with guaranteed persistence**
`AxiomProcessorService::process()` always writes a `ComplianceEvent` row — whether the axiom is routed to AI or not. The `routed_to_ai` boolean flags which path was taken; `audit_narrative` is null for sub-threshold events. This prevents silent drops and keeps the audit trail complete.

**Q:** Why does `AxiomProcessorService` persist a `ComplianceEvent` even when `anomaly_score` is below the threshold?
**A:** No Axiom should be silently dropped. Sub-threshold events are logged with `routed_to_ai = false` and `audit_narrative = null` so the full audit trail is queryable, even for events that didn't need AI analysis.

---

**Resilient AI calls with catch-and-persist**
Inside `routeToAi()`, the `$driver->analyze()` call is wrapped in a `try/catch(\Throwable)`. On failure, `Log::error` is called, and a `ComplianceEvent` is still written with `routed_to_ai = true` and `audit_narrative = null`. The command loop never dies from a transient API failure.

**Q:** What happens to a `ComplianceEvent` if the AI driver throws during `routeToAi()`?
**A:** The event is still persisted with `routed_to_ai = true` and `audit_narrative = null`. The error is logged. No exception propagates to the command loop.

---

**Mirroring existing stream conventions**
`AxiomStreamService` mirrors `TransactionStreamService` exactly — `XADD` with `MAXLEN ~`, `XREAD BLOCK 0`, and returning `{messages, cursor}`. `WatchAxioms` mirrors `WatchTransactions` — outer `while(true)`, cursor advancement, per-message processing via an injected service. Consistent shape reduces cognitive load and makes tests predictable.

**Q:** What return shape does `AxiomStreamService::read()` use, and why does it matter for the command loop?
**A:** `{messages: array, cursor: string}` — the cursor is always the ID of the last received message, so the command loop can pass it back on the next `XREAD` call to receive only new messages. The shape mirrors `TransactionStreamService::read()` for consistency.

---

**Architecture rules for a new namespace**
When `App\Services\Compliance` was added, two arch rules were added to `ArchTest.php`: (1) all classes in that namespace must implement `ComplianceDriver`, and (2) none may depend on controllers. This enforces the contract at the test level, not just convention.

**Q:** What arch rule guards `App\Services\Compliance`?
**A:** Two rules: drivers must `toImplement('App\Contracts\ComplianceDriver')`, and drivers must not use `App\Http\Controllers`. Both live in `tests/ArchTest.php`.

---

**Isolated unit tests via constructor injection**
`AxiomProcessorService` receives `ComplianceDriver` via constructor. Tests instantiate it directly with a `Mockery::mock(ComplianceDriver::class)` — no service container, no `app()->bind()`. This is fast and explicit.

**Q:** Why can `AxiomProcessorServiceTest` construct the service directly rather than resolving it from the container?
**A:** Because `AxiomProcessorService` accepts `ComplianceDriver` as a constructor argument. Tests pass a Mockery mock directly — no container bootstrapping needed, which keeps the tests fast and the dependency explicit.

---

### Anti-Patterns

**Sharing a stream key with the existing transaction pipeline**
ADR-0016 resolved the question: Axioms get their own stream key (`synapse:axioms`) rather than being mixed into the transaction stream. Mixing them would conflate two different data shapes, complicate consumer group logic, and make it impossible to independently scale or replay either pipeline.

**Q:** Why does Sentinel-L7 use a separate `synapse:axioms` stream key rather than pushing Axioms into the existing transaction stream?
**A:** Different data shapes, different consumers, different replay/scaling needs. Mixing them would force every consumer to branch on message type and make independent replay impossible.

---

**Putting AI routing logic in the Artisan command**
`WatchAxioms` only handles display. All routing and persistence decisions live in `AxiomProcessorService`. Commands that contain business logic are hard to test and can't be reused from other entry points (e.g., a queue job, a test harness).

**Q:** Why doesn't `WatchAxioms::handle()` contain the threshold check or AI routing logic?
**A:** Business logic in Artisan commands can't be tested without bootstrapping the full command. `AxiomProcessorService` owns all routing decisions; the command only renders output. This keeps both independently testable.

---

### Challenges

**`BLOCK 0` in `XREAD` hangs test execution**
`AxiomStreamService::read()` uses `XREAD BLOCK 0` (block forever). In tests, calling the real method would hang. The fix was to mock `AxiomStreamService` entirely at the test boundary rather than letting tests call the real Redis client — the stream service is injected, so swapping it for a mock is straightforward. This was the same lesson learned from `TransactionStreamService`, but it's worth stating explicitly: any `BLOCK` command must never reach a real connection in test.

**Q:** Why must tests mock `AxiomStreamService` rather than calling its `read()` method against a real Redis instance?
**A:** `XREAD BLOCK 0` blocks indefinitely until a message arrives. In a test suite that controls no external stream producer, the test would hang forever. Mock the service and return a controlled `{messages, cursor}` payload instead.

---

**`end()` on a reference vs. a value**
`AxiomStreamService::read()` uses `end($messages)` to get the last message's ID for the cursor. `end()` moves the internal pointer of an array passed by reference. In tests, asserting on `$messages` after calling `end()` required care — the array's pointer was moved. Assigning to a local variable before calling `end()` keeps the original array intact.

**Q:** What side effect does `end($array)` have in PHP?
**A:** It moves the internal array pointer to the last element. If you later iterate `$array` with `foreach` it resets the pointer, but intermediate `current()` / `next()` calls after `end()` will behave unexpectedly. Assign the last element to a variable first if you need to keep the pointer at the start.

---

**`ComplianceManager` driver name casing**
Laravel's `Manager::driver($name)` lowercases the name before calling `create{Name}Driver()`. So the env value `openrouter` must map to `createOpenrouterDriver()` — not `createOpenRouterDriver()`. A camelCase mismatch produces "Driver [openrouter] not supported." This caught a typo during the initial wiring of `AppServiceProvider`.

**Q:** What naming convention must `ComplianceManager::create*Driver()` methods follow to match a config value like `openrouter`?
**A:** The method must be `createOpenrouterDriver()` (all-lowercase after `create`). Laravel's `Manager` lowercases the driver name before constructing the method name, so `OpenRouter` → `createOpenrouterDriver`, not `createOpenRouterDriver`.

---

### Decisions

**`anomaly_score > $threshold` (strict greater-than), not `>=`**
At the threshold boundary (e.g., score = 0.8, threshold = 0.8), the event is treated as sub-threshold. This is conservative: borderline events are logged but not sent to AI. The threshold is configurable in `config/sentinel.php` and overridable per-test with `config(['sentinel.axiom_threshold' => ...])`.

**Q:** If `anomaly_score` equals the configured `axiom_threshold` exactly, is the Axiom routed to AI?
**A:** No — routing uses strict `>`. A score exactly at the threshold is treated as sub-threshold, logged with `routed_to_ai = false`. This is a conservative default: borderline events don't consume AI quota.

---

**`prompts/` directory for prompt assets**
Prompt templates live in `prompts/` at the repo root, versioned alongside the code that uses them. This keeps prompt evolution in git history and prevents prompt text from being buried in PHP strings. `compliance-audit-narrative.md` is live; `synapse-l4-extraction.md` and `synapse-l4-judge.md` are stubs for future phases.

**Q:** Where do Sentinel-L7's prompt templates live, and why not inline in the PHP service?
**A:** `prompts/` at the repo root, as Markdown files. Versioning prompts in git makes diffs legible, enables A/B testing via branches, and keeps the PHP service thin. ADR-0017 documents the governance model.

---

**`driver_used` column on `compliance_events`**
Storing which AI driver processed each event (or `null` for sub-threshold) makes post-hoc analysis possible: compare narrative quality across Gemini vs. OpenRouter, or audit which driver was active during an incident. Adding it at the model layer costs nothing and avoids a future migration.

**Q:** What is stored in the `driver_used` column of `compliance_events`, and when is it null?
**A:** The value of `config('sentinel.ai_driver')` at processing time (e.g., `"gemini"`, `"openrouter"`). It is `null` for sub-threshold events that were never sent to an AI driver.
