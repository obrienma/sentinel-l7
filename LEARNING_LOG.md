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

---

## Phase: Compliance Dashboard
*Files: `ComplianceController`, `Compliance.jsx`, `routes/web.php` | Date: 2026-04-01*

### Summary
Added the Compliance Events page — a paginated, auto-refreshing Inertia view over the `compliance_events` table. Default view shows flagged (AI-routed) events only; a toggle switches to all events. Wired the route, controller, and page component.

---

### Patterns

**`router.reload({ only: ['events'] })` for partial refreshes**
The compliance page polls every 5 seconds using Inertia's `router.reload({ only: ['events'] })`. This re-fetches only the `events` prop, not the full page — no layout re-mount, no flash of unstyled content. The user stays on their current page in the paginated list.

**Q:** How does the compliance page stay live without a full page reload?
**A:** `router.reload({ only: ['events'] })` in a `setInterval`. Inertia sends a partial XHR request and merges only the `events` prop into the current component state, leaving everything else untouched.

---

**`->through()` for controller-side response shaping**
`ComplianceEvent::query()->paginate(25)->through(fn ($e) => [...])` transforms each model into a plain array before it leaves the controller. The page component receives a clean, explicit contract — no accidental model attribute leakage, no need for API resources.

**Q:** Why use `->through()` on the paginator rather than a Laravel API Resource?
**A:** For a single internal page, `->through()` is simpler — one anonymous function, explicit field list, no extra class. API Resources add value when the same shape is reused across multiple endpoints.

---

**Filter state in query string**
`?flagged=1` / `?flagged=0` is the entire filter mechanism. `$request->boolean('flagged', true)` parses it; `router.get('/compliance', { flagged: ... })` sets it. No session state, no hidden form fields — the URL is the source of truth.

**Q:** How is the flagged/all filter persisted across page navigations on the compliance page?
**A:** In the query string. The controller reads `?flagged` via `$request->boolean('flagged', true)` and passes the current state back as `flaggedOnly`. The toggle button calls `router.get('/compliance', { flagged: 0|1 })` to update it.

---

### Anti-Patterns

**Leaking Eloquent model attributes through `->toArray()`**
Using `->toArray()` on the paginator would expose every column including internal fields. `->through()` with an explicit array is more defensive — only named fields reach the frontend.

**Q:** Why is `->through()` preferred over `->toArray()` when shaping paginated data for Inertia?
**A:** `->toArray()` passes every model attribute. `->through()` with an explicit shape is an intentional contract — adding a column to the table doesn't silently expose it to the frontend.

---

### Challenges

No unexpected challenge. The Inertia pagination shape (`data`, `links`, `meta`) is well-documented; the `Pagination` component reads `links` directly from the paginator response. The only care needed was ensuring `preserveScroll: true` on the toggle and pagination clicks to avoid jumping back to the top of the page.

---

### Decisions

**Default to flagged-only**
`$request->boolean('flagged', true)` defaults to `true` — first visit shows AI-flagged events. Operators care about threats first; they opt in to the full list rather than having to filter down from noise.

---

## Phase: Dev Pipeline Integration + Cross-System Stream Format Bug
*Files: `composer.json`, `AxiomStreamService.php`, `WatchAxioms.php`, `WatchAxiomsTest.php` | Date: 2026-04-01*

### Summary
Added `sentinel:watch-axioms` as the fifth process in `composer dev`. Diagnosed two separate bugs that caused the process to exit with code 1 and kill all other processes via `--kill-others`: (1) `BLOCK 0` causing a TCP read timeout on Upstash remote connections; (2) `WatchAxioms` expecting a JSON-encoded `data` field but the Python Synapse-L4 sidecar publishing individual stream fields instead. Fixed both; updated tests to reflect the real message format.

---

### Patterns

**`BLOCK N` (finite) instead of `BLOCK 0` (indefinite) for remote Redis streams**
`XREAD BLOCK 0` blocks the connection forever until a message arrives. Against a local Redis this is fine — the OS keeps the socket open. Against Upstash (remote TCP over TLS), the connection has a read timeout. After that timeout, Predis throws and the process exits with code 1. `BLOCK 2000` (2 seconds) returns null on timeout; the `while(true)` loop catches it and calls `XREAD` again. No exception, no crash.

**Q:** Why use `BLOCK 2000` instead of `BLOCK 0` for XREAD against Upstash?
**A:** Upstash is a remote TCP connection with a finite read timeout. `BLOCK 0` holds the connection open indefinitely; when Upstash's timeout fires, Predis throws an exception and the process exits. `BLOCK 2000` returns null after 2 seconds, the loop iterates cleanly, and no exception is thrown.

---

**Flat field-value array parsing for cross-language stream consumers**
Predis `executeRaw(['XREAD', ...])` returns stream message fields as a flat indexed array: `['field1', 'value1', 'field2', 'value2', ...]`. The Python `redis-py` `xadd` call publishes each Axiom field individually (not JSON-wrapped). The PHP consumer must zip the flat array into an associative array before using field names as keys.

```php
$flat = $streamMsg[1]; // ['status', 'critical', 'metric_value', '94.0', ...]
$data = [];
for ($i = 0; $i < count($flat); $i += 2) {
    $data[$flat[$i]] = $flat[$i + 1];
}
```

**Q:** What does Predis return for stream message fields when using `executeRaw`, and how does this interact with a Python publisher?
**A:** A flat indexed array: `['field', 'value', 'field', 'value', ...]`. Python's `redis-py` `xadd({'key': 'value'})` publishes individual fields. The PHP consumer must zip the flat array into a PHP associative array — you can't access fields by name directly from the raw Predis response.

---

### Anti-Patterns

**Assuming message format parity between a PHP publisher and a Python publisher**
`AxiomStreamService::publish()` wraps the payload as a single JSON-encoded `data` field (`XADD ... data '{"status":"critical",...}'`). The Python sidecar publishes each field individually (`XADD ... status critical metric_value 94.0 ...`). Both are valid uses of Redis Streams, but they produce incompatible message shapes for the consumer. The test suite used the PHP publisher's format — so tests passed while production failed silently.

**Q:** What is the risk of writing tests that mock stream messages in the publisher's format when the real producer is a separate system?
**A:** Tests pass against a format the real producer never emits. The bug only surfaces when the live cross-system flow runs. Test helpers should mirror the actual producer's format — in this case the Python sidecar's flat-field layout, not the PHP service's JSON-blob layout.

---

**`--kill-others` in `concurrently` amplifies any process crash**
`--kill-others` is the right default for a dev script where you want all processes to die together on CTRL-C. But it means any process that exits with code 1 during startup — even transiently — kills the entire dev environment. The symptom (all processes dying) obscures the actual error (one process crashed). Always run the failing process in isolation first to read its real output before debugging the concurrently invocation.

**Q:** What is the debugging approach when `--kill-others` in `concurrently` causes all processes to die?
**A:** Run the crashing process directly (`php artisan sentinel:watch-axioms`) to read its actual error output — `concurrently` swallows early output and only shows the exit code. Isolate before diagnosing.

---

### Challenges

**Two separate bugs with the same symptom**
The process exited with code 1 both times, but for completely different reasons: first a TCP timeout from `BLOCK 0`, then a `TypeError` from a format mismatch. The TCP timeout only manifested when Redis had no incoming messages for an extended period — it passed the first quick test. The format bug only manifested when a real Python-published message arrived. Neither bug was detectable by reading the code alone; both required the live cross-system flow to reproduce.

**Q:** What made these two bugs difficult to catch in isolation?
**A:** The TCP timeout only triggers after the read timeout elapses with no traffic — a 5-second `timeout` in testing wouldn't hit it. The format mismatch only triggers when a Python-emitted message is in the stream — tests used a PHP-format mock. Both required real cross-system conditions to surface.

---

**Retroactive test format update**
The test helper `fakeAxiomMessage` had to be updated to match the Python sidecar's flat-field format after the production bug was diagnosed. This is a case where the tests were internally consistent but externally wrong — they validated the wrong contract. Updating `fakeAxiomMessage` to emit `['status', 'critical', 'metric_value', '94.0', ...]` (flat) instead of `['data', '{"status":"critical",...}']` (JSON blob) corrected the contract and kept all 5 tests passing.

**Q:** If `WatchAxiomsTest` was passing before the bug fix, why did the production consumer fail?
**A:** The tests mocked the stream in the PHP publisher's format (single `data` JSON field). The real producer is the Python sidecar, which uses individual fields. The tests validated a format that no real producer ever emits — internal consistency masked an external contract bug.

---

### Decisions

**Cast numeric fields in `WatchAxioms` after parsing**
Python publishes all stream field values as strings (`str(axiom.anomaly_score)` → `'0.91'`). After zipping the flat array into `$data`, `anomaly_score` and `metric_value` are cast to `float` explicitly in `WatchAxioms`. This keeps `AxiomProcessorService` agnostic to the source format — it always receives typed values regardless of whether the producer is PHP or Python.

**Q:** Why cast `anomaly_score` to float in `WatchAxioms` rather than in `AxiomProcessorService`?
**A:** `AxiomProcessorService` already does `(float) ($data['anomaly_score'] ?? 0.0)`, but the processor should receive consistent types regardless of origin. Casting at the command level means the processor isn't responsible for knowing that Python publishes strings. Separation of concerns: command normalises the wire format, processor applies business logic.

---

## Phase: Operational Debugging — Backlog Drain + Quota + Pagination
*Date: 2026-04-01*

### Summary
First live end-to-end run with 657 queued Axioms drained through `sentinel:watch-axioms`. Two operational issues surfaced: Gemini free-tier quota exhausted by the burst; and the compliance dashboard appeared to show "few" events despite the DB having 1,146 rows. Both were diagnosed with a single DB count query.

---

### Patterns

**`php artisan tinker --execute` as a first-line persistence check**
Before investigating controller logic or query bugs, running `ComplianceEvent::count()` in tinker immediately distinguishes "data isn't being written" from "data is written but not displayed". In this case the count was 1,146 — the write path was fine; the display was just paginated.

**Q:** What is the fastest way to determine whether a persistence bug is in the write path or the read path?
**A:** Query the DB directly — `php artisan tinker --execute="echo ModelName::count();"`. If the count matches expectations, the write path is fine and the issue is in the read/display layer. If the count is low, investigate the service or model.

---

### Anti-Patterns

**Assuming a UI showing few rows means few rows were written**
The compliance dashboard paginates to 25 per page. Watching rapid terminal output and then seeing only 25 rows on screen looks like a bug — but `meta.total` in the card header shows the real count. Always check `meta.total` (or query the DB) before suspecting the write path.

**Q:** What UI signal on the compliance dashboard indicates the actual total record count, independent of pagination?
**A:** The `meta.total` value displayed in the `CardHeader` of the events card. It reflects the full unpaginated count from the controller, not just the current page.

---

### Challenges

**Gemini free-tier quota exhausted by backlog drain**
657 messages had accumulated in `synapse:axioms`. Of those, a significant fraction had `anomaly_score > 0.8` and were routed to Gemini Flash. The free tier hit its per-minute and per-day limits almost immediately. The processor caught the 429 and persisted the `ComplianceEvent` with `audit_narrative = null`, so no data was lost — but narratives are missing for events processed during quota exhaustion. Mitigation: switch to `SENTINEL_AI_DRIVER=openrouter` or pace the drain.

**Q:** What happens to a `ComplianceEvent` when Gemini returns a 429 during `routeToAi()`?
**A:** The `try/catch(\Throwable)` in `routeToAi()` catches the 429 error, logs it at `Log::error`, and still calls `ComplianceEvent::create()` with `routed_to_ai = true` and `audit_narrative = null`. The row is persisted; only the narrative is missing. No data loss.

---

### Decisions

**OpenRouter as the immediate mitigation for quota exhaustion**
`SENTINEL_AI_DRIVER=openrouter` in `.env` switches the driver without code changes. The `OpenRouterDriver` stub exists but is not yet implemented — implementing it is the next TODO. Until then, waiting for quota reset or paying for the Gemini API tier are the only options.

---

## Phase: OpenRouterDriver — `SENTINEL_AI_DRIVER=openrouter`
*Date: 2026-04-01*

### Summary
Implemented the `OpenRouterDriver` as a drop-in swap for `GeminiDriver`. Uses the OpenRouter API (`https://openrouter.ai/api/v1/chat/completions`) with OpenAI-compatible request format. Same policy RAG pipeline, same prompt, same response schema — only the HTTP transport differs. Added `openrouter` block to `config/services.php`, `OPENROUTER_API_KEY` and `OPENROUTER_MODEL` to `.env.example`, and 6 unit tests covering happy path, markdown fence stripping, malformed responses, 401 errors, RAG failure fallback, and Authorization header assertion.

---

### Patterns

**OpenAI-compatible API format (messages array)**
OpenRouter accepts the standard OpenAI chat completions format: `{ model, messages: [{role, content}] }`. The response path is `choices.0.message.content` — a string containing the model's reply. This is the de facto standard for LLM API compatibility and works across OpenRouter, OpenAI, and many self-hosted endpoints.

**Q:** What is the structural difference between a Gemini API request and an OpenRouter/OpenAI-compatible request?
**A:** Gemini wraps the prompt in `contents: [{parts: [{text: ...}]}]` and returns via `candidates.0.content.parts.0.text`. OpenRouter (OpenAI format) uses `messages: [{role: "user", content: ...}]` and returns via `choices.0.message.content`. Auth also differs: Gemini uses a `?key=` query param; OpenRouter uses `Authorization: Bearer` header.

---

**Driver interoperability via a shared prompt and return schema**
Both `GeminiDriver` and `OpenRouterDriver` build identical prompts and expect the model to return the same JSON schema `{narrative, risk_level, policy_refs, confidence}`. `parseResponse()` is identical in both. This means the `ComplianceDriver` contract is truly honoured — switching drivers with an env var produces semantically equivalent output regardless of the underlying model.

**Q:** What makes two AI drivers genuinely interchangeable rather than just interface-compatible?
**A:** Identical prompt text, identical expected response schema, and identical response parsing. If the prompt changes between drivers, models may produce different output shapes even if both return the same field names. True interoperability requires the same prompt contract across drivers.

---

### Anti-Patterns

**Relying on `responseMimeType: application/json` for structured output**
Gemini supports a `generationConfig.responseMimeType` parameter that forces the model to emit valid JSON. OpenRouter (and most other providers) do not support this — they rely on the prompt instruction alone. The mitigation is a clear prompt instruction (`"Respond ONLY with valid JSON"`) combined with a `parseResponse()` fallback that handles prose or unexpected shapes gracefully rather than throwing.

**Q:** Why can't `OpenRouterDriver` use structured output mode the way `GeminiDriver` does?
**A:** `responseMimeType: application/json` is a Gemini-specific parameter. OpenRouter routes to many different models; enforced JSON output is model-dependent. The driver falls back to prompt-based instruction + `parseResponse()` fallback for malformed replies.

---

### Challenges

**No pre-existing test pattern for compliance drivers**
There was no `GeminiDriverTest` to reference for structure. The test suite had to be designed from scratch: `Http::fake()` for the API layer, Mockery for `EmbeddingService` and `VectorCacheService`, and a helper `mockOpenRouterDriver()` to reduce setup boilerplate across test cases. The Authorization header assertion (`Http::assertSent()`) was the most useful test — it catches misconfigured auth without requiring a live API call.

**Q:** How do you assert that a specific HTTP header was sent in a Laravel test without hitting a real API?
**A:** Use `Http::fake()` to intercept the request, then `Http::assertSent(fn($req) => $req->hasHeader('Authorization', 'Bearer ...'))` after the call. `Http::fake()` records all outbound requests, so `assertSent` can inspect headers, body, and URL.

---

### Decisions

**Default model: `meta-llama/llama-3.3-8b-instruct:free`**
Chosen because it's on OpenRouter's free tier (no cost, no quota), capable enough to follow a JSON-only instruction, and available without a paid plan. Overridable via `OPENROUTER_MODEL` env var — switching to a paid model (e.g. `google/gemini-2.0-flash-001`) is a config change only.

**30s timeout vs Gemini's 15s**
Free-tier models on OpenRouter can queue behind paid requests and take longer to respond. 30s gives enough headroom for cold-start latency on free models without blocking the worker indefinitely.

---

## Phase: Synapse-L4 XCLAIM Recovery — `sentinel:reclaim-axioms`
*Date: 2026-04-01*

### Summary
Upgraded the Axiom worker from plain `XREAD` to `XREADGROUP`/`XACK` to give the `synapse:axioms` stream at-least-once delivery semantics, then added `sentinel:reclaim-axioms` — a reclaimer command that uses `XAUTOCLAIM` to recover Axioms that have been in the Pending Entry List for over 60 seconds. Added `ensureConsumerGroup()`, `readGroup()`, `ack()`, `claimPending()`, and `parseFields()` to `AxiomStreamService`. Updated `WatchAxiomsTest` to mock the new consumer group interface and added 10 new unit tests for the new service methods.

---

### Patterns

**`XREADGROUP` + `XACK` for at-least-once delivery**
Plain `XREAD` has no memory of delivery — if the worker crashes after reading but before processing, the message is gone from the worker's perspective. `XREADGROUP` places every delivered message into the Pending Entry List (PEL). The message stays in the PEL until `XACK` is called. If the worker dies, the message remains pending and a reclaimer can claim it.

**Q:** What is the difference between `XREAD` and `XREADGROUP` for fault tolerance?
**A:** `XREAD` is stateless — delivery is fire-and-forget. `XREADGROUP` maintains a Pending Entry List: every delivered-but-unacknowledged message is tracked. A crashed worker leaves its messages in the PEL where a reclaimer can find and reprocess them via `XCLAIM`/`XAUTOCLAIM`. `XREAD` provides at-most-once delivery; `XREADGROUP` + `XACK` provides at-least-once.

---

**`XAUTOCLAIM` over manual `XPENDING` + `XCLAIM`**
`XAUTOCLAIM` atomically: scans the PEL for messages idle longer than the threshold, reassigns ownership to the calling consumer, and returns those messages — all in one round trip. The older pattern (`XPENDING` to list, then `XCLAIM` per message) requires two commands per message. `XAUTOCLAIM` was added in Redis 6.2; Upstash supports it.

**Q:** Why prefer `XAUTOCLAIM` over `XPENDING` + `XCLAIM` for reclaimer logic?
**A:** `XAUTOCLAIM` is one round trip: scan + reassign + return in a single command. `XPENDING` + `XCLAIM` requires reading the pending list first, then issuing a `XCLAIM` for each message — O(N) Redis calls. `XAUTOCLAIM` also accepts a `COUNT` cap so the reclaimer processes a bounded batch per iteration.

---

**`BUSYGROUP` swallow on consumer group creation**
`XGROUP CREATE` fails with a `BUSYGROUP` error if the group already exists. On worker restart (common in dev and after deploys) this would cause a crash. Wrapping the call in a try/catch that ignores `BUSYGROUP` but re-throws all other errors makes `ensureConsumerGroup()` safe to call at startup unconditionally.

**Q:** Why must `ensureConsumerGroup()` be called on every worker startup rather than just once during setup?
**A:** The stream key and consumer group may not exist when the worker first starts (cold deploy, wiped Redis). `MKSTREAM` creates the key if absent. Re-calling `XGROUP CREATE` on an existing group returns `BUSYGROUP` which is caught and ignored — it's a no-op in the happy path but a lifesaver on first boot or after a Redis flush.

---

**Extracting `parseFields()` from the command to the service**
The flat `[field, value, field, value, ...]` parsing logic was duplicated in `WatchAxioms` and would need to be repeated in `ReclaimAxioms`. Moving it to `AxiomStreamService::parseFields()` gives both commands a single place to get a normalized associative array, and makes it directly testable in `AxiomStreamServiceTest`.

**Q:** Why is field parsing placed on `AxiomStreamService` rather than kept in each command?
**A:** The flat field-value format is a Redis wire-format detail — it belongs with the class that owns the stream protocol, not scattered across consumers. Centralising it means the float-casting logic (for `anomaly_score`, `metric_value`) is tested once and applied consistently.

---

### Anti-Patterns

**Implementing a reclaimer without consumer group semantics on the worker**
A reclaimer only works if messages enter the PEL — which only happens with `XREADGROUP`. The original `WatchAxioms` used plain `XREAD`, so even if a reclaimer existed it would have nothing to claim. The fix was to upgrade the worker first, then add the reclaimer.

**Q:** What happens if you add a reclaimer but the worker still uses plain `XREAD`?
**A:** Nothing. `XREAD` never enters messages into the PEL. The PEL stays empty; `XAUTOCLAIM` returns no results on every poll. The reclaimer runs but is permanently a no-op.

---

### Challenges

**Test mocks written against `read()` broke when WatchAxioms switched to `readGroup()`**
`WatchAxiomsTest` mocked `AxiomStreamService::read()` with a `__test_stop__` escape hatch. After the command switched to `readGroup()`, `ensureConsumerGroup()`, `parseFields()`, and `ack()`, every test threw `BadMethodCallException` — Mockery rejects calls to unmocked methods. The fix was to update `mockAxiomStreamWithOneMessage()` to mock all four new methods, with `parseFields()` wired to run the actual conversion logic inline so tests still verify the processor receives correct types.

**Q:** What Mockery behaviour catches you when you change a service's public API?
**A:** Any method called on a `Mockery::mock()` instance that wasn't explicitly set up with `shouldReceive()` throws `BadMethodCallException`. Partial mocks (`Mockery::mock(Foo::class)->makePartial()`) allow unmocked methods to call through to the real implementation — useful for pure helpers like `parseFields()` — but full mocks require all called methods to be declared.

---

### Decisions

**`XAUTOCLAIM` starting cursor always `0-0`**
The reclaimer always starts scanning from the beginning of the PEL (`0-0`) rather than tracking a cursor across iterations. This is intentional: if the reclaimer restarts, it re-scans from the start and re-claims anything still idle. Because `claimPending()` caps at 10 messages per call and the reclaimer loops immediately when messages are found, the full PEL drains without needing cursor state.

**`axiom-reclaimer` as a fixed consumer name**
The reclaimer always uses the consumer name `axiom-reclaimer` rather than a dynamic name. There is only one reclaimer process; a fixed name keeps the PEL view clean and avoids accumulating ghost consumer entries in `XINFO CONSUMERS`.

---

## Phase: Transaction History — Postgres Persistence
*Date: 2026-04-28*

### Summary
Fulfilled the "Transaction history" TODO: processed transactions are now persisted to a Postgres `transactions` table on every pipeline path (cache hit, cache miss, fallback). Added `Transaction` Eloquent model with `$fillable` and `$casts`, a migration with an indexed `txn_id` column and nullable `amount`/`currency`, and threaded `rawAmount` (float) through `recordTransaction()` so the DB stores a numeric value rather than a formatted display string. Added 9 unit tests covering persistence correctness, `observe=false` suppression, and edge-case field extraction.

---

### Patterns

**Separate raw numeric from formatted display value early**
`TransactionProcessorService` previously held a single `$amount` string (`number_format(...)`) used for both display and internal passing. Splitting into `$rawAmount` (float|null) and `$amount` (formatted string) at the point of extraction keeps the type correct for the DB column while leaving the display string unchanged everywhere else.

**Q:** Why split `$rawAmount` and `$amount` rather than re-parsing the formatted string before the DB write?
**A:** `number_format()` produces a locale-aware string (`"1,500.00"`). Re-parsing it with `(float)` loses commas only if the locale uses `.` as decimal — fragile. Retaining the original float sidesteps the round-trip entirely and keeps the DB column correctly typed as `decimal(15,2)`.

---

**`observe` flag as a single gate for all side-effects**
The `observe` parameter suppresses Redis LPUSH, metric increments, *and* the DB write in a single branch. This keeps the "dry run" contract complete — callers that pass `observe=false` (e.g. the MCP tool) get no observable state changes at all.

**Q:** Why should the `Transaction::create()` call be inside the `observe` guard rather than always running?
**A:** `observe=false` is the contract for read-only / test-mode calls. Writing to Postgres despite that flag would surprise callers (e.g. the MCP `analyze_transaction` tool) and pollute the transaction history with synthetic data.

---

**`RefreshDatabase` on unit tests that hit Eloquent**
Tests that exercise `Transaction::create()` need a real schema. Using `RefreshDatabase` wraps each test in a transaction that rolls back, giving isolation without manual teardown. The `uses(Tests\TestCase::class, RefreshDatabase::class)` declaration at file level applies it to every test in the file.

**Q:** What is the effect of `RefreshDatabase` in Pest compared to `DatabaseMigrations`?
**A:** `RefreshDatabase` runs migrations once per test run and wraps each test in a DB transaction rolled back on teardown — fast. `DatabaseMigrations` re-runs the full migration up/down for every test — correct but slow. For unit tests that don't need schema changes between tests, `RefreshDatabase` is preferred.

---

### Anti-Patterns

**Storing the formatted display string in a numeric DB column**
The original `$amount` was `number_format((float) $data['amount'], 2)` — a string like `"150.00"`. Passing that to a `decimal(15,2)` column works in MySQL (implicit cast) but is semantically wrong and breaks on locales that use comma as the decimal separator. The fix is to keep the float, not the string.

---

### Challenges

**No challenge was encountered.** The migration, model, and service change were straightforward. The only decision of note was where to thread `rawAmount` — passing it as an optional trailing parameter to `recordTransaction()` kept the call-site diff minimal across all three pipeline branches (cache hit, cache miss, fallback).

---

### Decisions

**`txn_id` indexed, not unique**
The `transactions` table indexes `txn_id` but does not apply a `UNIQUE` constraint. Stream messages can be redelivered (XCLAIM recovery), so the same `txn_id` may legitimately be processed more than once in a failure scenario. A unique constraint would cause an integrity error on the second write; the index preserves lookup speed without rejecting retries.

**`currency` nullable, `amount` nullable**
Both fields are optional in the stream payload. Nullable columns reflect reality rather than coercing absent values to empty strings or `0`, which would make "not provided" indistinguishable from "zero" or "unknown currency".

