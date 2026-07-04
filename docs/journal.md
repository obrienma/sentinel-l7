# Engineering Journal — Sentinel-L7

Per-phase engineering journal. Typed, vocabulary-enforced sections (see
`~/.claude/skills/journal-anki.md`). Paired Anki probes live in
`docs/probes/phase-N-<name>.md`. Migrated from `LEARNING_LOG.md`.

---

## Phase 1 — Stream Simulator (`sentinel:stream`) — 2026-02-09 – 2026-02-19
Commits: `9697431`, `d0d8375`
Files: app/Console/Commands/StreamTransactions.php, app/Services/TransactionStreamService.php, config/sentinel.php, tests/Feature/StreamTransactionsTest.php, tests/Unit/TransactionStreamServiceTest.php

Built `sentinel:stream`, an Artisan command that seeds Redis Streams with
synthetic transactions for the downstream compliance pipeline to consume.

### Pattern: Graceful Shutdown via Signal-Flag Polling
`StreamTransactions` registers `pcntl_signal` handlers for `SIGINT`/`SIGTERM`
that flip a `$running` boolean rather than calling `exit()` directly. The
`while ($running)` loop tests the flag once per iteration, so an in-flight
`XADD` completes before the process exits. This is cooperative cancellation:
the signal marks intent, the loop chooses a safe point to honour it, which is
what prevents a mid-write tear on CTRL-C.

### Pattern: Idempotency Guard via `SETNX` Before Stream Writes
Before `XADD`, `TransactionStreamService::publish()` issues
`SETNX sentinel:seen:{id}` with a 24h TTL. An already-present key means the
transaction was published before, so the write is skipped and `false` returned.
The guard lives in a dedicated key namespace, keeping the dedup check O(1) and
independent of stream length — the producer-side complement to an idempotent
receiver downstream.

### Anti-Pattern Avoided: Scattered Magic Stream Keys
The tempting shortcut is to inline the `sentinel:transactions` string wherever
a command needs it. Instead the key is a single class constant on
`TransactionStreamService`, read only there; commands obtain it through the
service. Single source of truth — a later rename is one edit, not a grep across
every command file.

### Challenge: `pcntl` Extension Silently Absent
Symptom: CTRL-C killed the process abruptly with no clean loop exit. Root
cause: `pcntl_signal` requires the `pcntl` extension enabled; when it is not,
handler registration is a silent no-op — no error, the signal just falls
through to default SIGINT termination. Fix: confirmed the extension present in
the Render build (it was) and locally. The failure mode is insidious precisely
because nothing throws.

### Decision: `--limit` / `--speed` as Tunable Options
`--limit=10` (transactions per run) and `--speed=1000` (ms between writes) are
command options with conservative defaults that won't spam a dev Redis. Pushing
`--limit=100 --speed=100` enables load testing with no code change — keeping
the knobs out of code and in the invocation.

---

## Phase 2 — Real-time Watcher + Threat Analysis (`sentinel:watch`) — 2026-02-18
Commits: `36ed585`
Files: app/Console/Commands/WatchTransactions.php, app/Services/ThreatAnalysisService.php, tests/Unit/ThreatAnalysisServiceTest.php

Added `WatchTransactions` (an infinite `XREAD` loop) and
`ThreatAnalysisService` (a rule-based L7 compliance checker) — the first
end-to-end pipeline: stream producer → Redis → consumer → threat verdict → CLI
output.

### Pattern: Tier-3 Fallback as a Pure Rule-Based Service
`ThreatAnalysisService` is the tier-3 fallback: amount-threshold and
category-flag rules implemented in pure PHP with no network calls. Because it
performs no I/O, it cannot throw on a downstream outage and always returns a
verdict — the property that lets `TransactionProcessorService` fall back to it
unconditionally when embedding or vector search fails.

### Pattern: Cursor-Based Stream Consumption
`WatchTransactions` tracks a `$lastId` cursor passed to every `XREAD` call.
The first call uses `$` (only new messages from this point forward); every
subsequent call passes the previously-received message ID. This guarantees the
consumer advances monotonically through the stream and never reprocesses a
message within the session.

### Anti-Pattern Avoided: Unbounded Replay from Stream Start
An early version polled `XREAD` from `0` every iteration, replaying the entire
stream history on each loop. The failure mode is silent duplication — no error,
just every historical transaction reprocessed repeatedly, surfacing as
duplicate CLI output. Cursor-based reads (above) eliminate it.

### Decision: `analyze()` Returns a Value Object, Not an Array
`ThreatAnalysisService::analyze()` returns a plain PHP object with public
`$isThreat` and `$message` properties rather than an associative array. This
gives call sites named-property access (`$result->isThreat`) without the
overhead of a formal class hierarchy — the minimal step up from an array that
still buys typo-safety at call sites.

---

## Phase 3 — Semantic Cache — Vectorize — 2026-02-20
Commits: `391b8c3`, `1511fbc`
Files: app/Console/Commands/StreamTransactions.php, app/Console/Commands/WatchTransactions.php, app/Services/EmbeddingService.php, app/Services/VectorCacheService.php, config/services.php, tests/Feature/WatchTransactionsTest.php, tests/Unit/EmbeddingServiceTest.php, tests/Unit/VectorCacheServiceTest.php

Added `EmbeddingService` (Gemini `embedding-001`, 1536-dim) and
`VectorCacheService` (Upstash Vector REST API), wired into
`WatchTransactions`: embed fingerprint → vector search → cache hit returns
early; miss → `ThreatAnalysisService` → upsert result. First significant Pest
suite (615 lines across 3 files).

### Pattern: Semantic Fingerprint as Cache Key
The cache key is not the transaction ID but a text fingerprint of semantic
fields (`Amount | Type | Category | Time | Merchant`), embedded to a vector.
Two transactions with different IDs but the same risk profile land within the
similarity threshold and share a cache entry — this is the defining property
of a semantic cache, as distinct from an exact-match key/value cache.

### Pattern: Tiered Fallback Pipeline (Graceful Degradation)
The pipeline (later extracted into `TransactionProcessorService`) has three
tiers: (1) vector cache hit → return stored verdict, (2) cache miss → Gemini
Flash analysis → upsert, (3) any exception in tier 1 or 2 → rule-based
`ThreatAnalysisService`. This is graceful degradation — each tier is a fallback
for the one above it, and every transaction receives a verdict; none is
silently dropped. See the pipeline diagram in
`docs/probes/phase-3-semantic-cache-vectorize.md`.

### Pattern: `Cache::increment` for Zero-Schema Metrics
Hit count, miss count, fallback count, and cumulative latency are tracked via
`Cache::increment('sentinel_metrics_*')` — plain Redis key/value pairs, no
metrics table or time-series DB. Fast, schema-free, and resettable with
`php artisan sentinel:reset-metrics`.

### Anti-Pattern Avoided: Unwrapping the Upstash Response Envelope Incorrectly
The initial `VectorCacheService` read `$response->json('results')` (plural).
Upstash Vector wraps query results under `result` (singular) —
`{"result": [{"id": ..., "score": ...}]}`. Reading the wrong key doesn't error;
it silently returns null, which looks identical to "no match found" and masked
the bug.

### Anti-Pattern Avoided: Exact Timestamps in the Fingerprint
The original fingerprint embedded `date('H:i', ...)` — an exact `HH:MM`. Two
semantically identical transactions a minute apart produced different vectors
and never shared a cache entry. Replaced (later phase) with time-of-day
buckets (night/morning/afternoon/evening), preserving compliance-relevant time
context without destroying the hit rate. See ADR-0001.

### Challenge: Gemini Embedding Dimension Mismatch
Symptom: Upstash returned a 400 on upsert. Root cause: Upstash Vector
namespaces have a fixed dimension set at creation time; an early namespace was
created at 768 dims (Gemini `embedding-001`'s default), but the service was
later configured for `"output_dimensionality": 1536`. Inserting 1536-dim
vectors into a 768-dim namespace is rejected outright. Fix: delete and recreate
the namespace at 1536 dims.

### Challenge: `Http::fake()` Ordering Relative to Instantiation
Symptom: fakes weren't intercepting requests in early `EmbeddingServiceTest`
runs. Root cause: `Http::fake([...])` was called *after* `new
EmbeddingService()`. `Http::fake()` swaps the client at the facade level — a
service constructed first may already hold a reference to the real client.
Fix: call `Http::fake()` before any `new` calls in the test.

### Decision: Similarity Threshold of 0.95 for the Transaction Cache
Set conservatively high to avoid false positives: a transaction that's merely
*similar* to a cached one could still differ in compliance-relevant ways, and
returning a stale verdict for a real threat is a worse outcome than a cache
miss (just a redundant AI call). ADR-0015 documents this and tracks an
empirical evaluation of lowering it to 0.90.

---

## Phase 4 — Service Hardening — Retries, Timeouts, Observability — 2026-02-27
Commit: `45301d8`
Files: app/Services/EmbeddingService.php, app/Services/VectorCacheService.php, config/services.php, docs/sprintPlan.md, tests/Feature/WatchTransactionsTest.php, tests/Unit/EmbeddingServiceTest.php, tests/Unit/VectorCacheServiceTest.php

Added retry-with-backoff (3× @ 200ms for embedding, 2× @ 150ms for vector),
explicit HTTP timeouts (10s / 5s), `Log::warning` on all failure paths, and
`VectorCacheService::delete()` for cache eviction. Test suite doubled
(34 → 76 tests).

### Pattern: Retry with Fixed Delay Before Tier-3 Fallback
`EmbeddingService::embed()` retries 3× with a fixed 200ms delay;
`VectorCacheService` retries 2× at 150ms. This is fixed-delay retry, not
exponential backoff — the delays don't grow between attempts, which is
appropriate here because the consumer loop is synchronous and a long backoff
would itself become a latency problem. On exhaustion the exception propagates
to the pipeline's try/catch and triggers the tier-3 fallback.

### Pattern: `Log::warning` as the Observability Hook for Every Failure Path
Every catch block on a transient failure path logs a warning with structured
context (service name, error message) rather than letting the exception
propagate silently into the fallback. This produces a searchable trail in
Railway's log drain — logs are the primary signal here; there is no dedicated
APM.

### Anti-Pattern Avoided: Head-of-Line Blocking from Unbounded HTTP Calls
The initial `EmbeddingService` and `VectorCacheService` called `Http::post()`
with no timeout. In the single-threaded `while(true)` consumer loop, a hung
Gemini or Upstash connection is head-of-line blocking: one stuck request
blocks every transaction queued behind it, indefinitely. Fixed with
`->timeout(10)` (embedding) and `->timeout(5)` (vector).

### Challenge: Testing Retries Without Real Sleep
Retry tests need the service to fail N-1 times and succeed on attempt N.
Using real `sleep()` would make the suite slow. Fix: `Http::sequence()`
returns a series of fake responses — failures first, then success — so the
service retries against the sequence with no real network or sleep involved.

### Decision: Speculative `VectorCacheService::delete()`
No current caller needs `delete()`, but it's required for cache invalidation
when a compliance policy changes — every cached verdict under the old policy
becomes stale and must be evictable. Added during hardening rather than
deferred until the invalidation use case arrives, since the method is small
and the absence would block that future work entirely.

---

## Phase 5 — Vue 3 → React 19 + shadcn/ui Migration — 2026-03-09
Commit: `4b3a1cd`
Files: .claude/settings.json, README.md, components.json, jsconfig.json, package-lock.json, package.json, resources/css/app.css, resources/js/Pages/Home.jsx, resources/js/Pages/Home.vue, resources/js/app.js, resources/js/components/ui/badge.jsx, resources/js/components/ui/button.jsx, resources/js/components/ui/card.jsx, resources/js/components/ui/table.jsx, resources/js/lib/utils.js, resources/views/app.blade.php, vite.config.js

Replaced Vue 3 + `@inertiajs/vue3` with React 19 + `@inertiajs/react` +
shadcn/ui (New York style, slate base). Migrated `Home.vue` → `Home.jsx`.
Configured Vite, `jsconfig.json`, Tailwind v4, and dark-palette defaults.

### Pattern: `React.createElement` in `app.js` to Avoid the `.jsx` Extension Requirement
The Inertia entry point uses `React.createElement(App, props)` instead of JSX.
Blade's `@vite()` directive references `app.js` by its literal filename, and
`.js` files aren't processed as JSX by Vite without extra config. Using
`createElement` in the entry point avoids renaming it to `app.jsx` and
reconfiguring the Blade template. All page components use JSX normally.

### Pattern: `@viteReactRefresh` Before `@vite()` in Blade
React Fast Refresh (HMR) requires its runtime injected before the application
bundle. If `@vite(...)` appears first in `app.blade.php`, Fast Refresh stops
working — the refresh runtime must load before the app bundle, not after.

### Pattern: `jsconfig.json` for shadcn CLI Path Resolution
The shadcn CLI reads `jsconfig.json` (or `tsconfig.json`) to resolve the `@`
path alias when scaffolding component files. Without it, `npx shadcn@latest
add button` can't determine where `@/components/ui/` maps to on disk and
fails silently or scaffolds to the wrong path — required even though this is a
non-TypeScript project.

### Pattern: Tailwind v4 — Config-in-CSS, No `tailwind.config.js`
Tailwind v4 reads theme configuration from an `@theme inline { ... }` block in
`app.css`; there is no `tailwind.config.js`. The dark palette is set directly
on `:root` (not behind a `.dark` class), so dark mode is always active with no
JS toggle.

### Anti-Pattern Avoided: Duplicate `createInertiaApp` Call in `app.js`
A second `createInertiaApp` block was left in `app.js` as a copy-paste
remnant from the Vue version. The symptom: the Inertia app mounts twice on the
same DOM node, producing React reconciliation errors and double-rendering in
development. Removed before merging.

### Challenge: shadcn Components Are Vendored, Not `node_modules` Dependencies
`npx shadcn@latest add <component>` copies source files into
`resources/js/components/ui/` as first-party files committed to the repo —
not into `node_modules`. This is vendoring: a `git status` after adding a
component shows new tracked source files, not just a `package.json` bump,
which is surprising the first time. The trade-off is full in-repo ownership
(customize freely) against upstream shadcn updates becoming manual, opt-in
copies rather than a version bump.

### Decision: New York Style, Slate Base, Dark-Always Palette
New York style uses tighter padding and a border-radius closer to a product
aesthetic than shadcn's default style. Slate was chosen over zinc/gray for its
cooler tone. Dark-always (palette on `:root`, not gated behind `.dark`) avoids
building a theme-toggle mechanism that wasn't planned.

## Phase 6 — Grafana Dashboard for Sentinel (TraceQL Metrics) — 2026-06-16
cross-ref: observability
Files: rhizome-observability/grafana/provisioning/dashboards/sentinel-l7-service.json, rhizome-observability/tempo.yaml, rhizome-observability/docker-compose.yml

Built the "Sentinel-L7 Service" Grafana dashboard (in the `rhizome-observability`
repo), modelled on the existing EventHorizon dashboard. The deliverable is the
Phase-5 dashboard slice for Sentinel from the OTel migration plan: 9 panels over
the wide `axiom.process` / `axiom.ai_analysis` spans shipped in OTel Phase 2.

### Pattern: Wide-Events Querying via TraceQL Metrics
Every timeseries panel is a **TraceQL metrics** query against Tempo's
`local-blocks` metrics-generator — `rate() by (.risk_level | .domain | .routed_to_ai)`
and `quantile_over_time(duration, …)` — read directly through the Tempo datasource,
no Prometheus `remote_write`. This is the wide-events model on a pillar backend: the
high-cardinality attributes already on the spans (`source_id`, `anomaly_score`,
`domain`, `risk_level`) are the query dimensions, so no metric dimension is
pre-committed at write time. EventHorizon's dashboard is mostly PromQL only because
Node auto-instrumentation handed it RED metrics for free; Sentinel has no such free
metrics, so TraceQL metrics off the spans is the equivalent.

### Anti-Pattern Avoided: Pre-Aggregating Business Attributes into Prometheus Counters
The tempting shortcut was to emit `axioms_by_domain_total{domain=…}` /
`axiom_confidence` counters from a `MeterProvider` and chart them with PromQL (exactly
how EventHorizon's RED panels look). That bakes the cardinality decision into the
write path and, per the migration plan's anti-goal, degrades the wide-attribute story
over time — once the counter exists people query it instead of the spans. Kept all
business dimensions as span attributes queried via TraceQL; reserved Prometheus for
nothing here.

### Challenge: INTERNAL Spans Dropped by `filter_server_spans`
After bumping Tempo to 2.7.2, every metrics query returned zero series while trace
*search* still found the spans, and `tempo_metrics_generator_spans_received_total`
was absent. Root cause: Tempo 2.7's `local_blocks` processor defaults
`filter_server_spans: true`, which keeps only `SpanKind=SERVER` spans for metrics.
Sentinel's `axiom.process` / `axiom.ai_analysis` spans are `INTERNAL` (plain
`spanBuilder`, no kind set), so they were silently excluded. Fix: set
`filter_server_spans: false` in `tempo.yaml`. On Tempo 2.6.1 the same config worked
because the default was `false` — a version-drift gotcha.

### Challenge: TraceQL Quantiles Don't Apply to Span Attributes
`quantile_over_time(.anomaly_score, .95)` returns empty on both 2.6.1 and 2.7.2, while
`quantile_over_time(duration, .95)` works — Tempo's quantile/histogram functions only
operate on the `duration` intrinsic, not arbitrary numeric attributes. Latency keeps
true percentiles (off span `duration`); the anomaly-score and AI-confidence panels
fall back to `avg`/`max`/`min_over_time` (which themselves 500'd on 2.6.1 and only
work from 2.7.2 — the actual payoff of the version bump). Span-duration metric values
come back in **seconds**, so the latency panel unit is `s`.

### Challenge: AI Failures Aren't `status=error`
The "AI Errors" panel first filtered on `{ … status=error }` and matched nothing.
`AxiomProcessorService::routeToAi()` calls `recordException()` on a driver failure,
which adds an `exception` span *event* but never flips span status (`span.error` is
unset). Re-pointed the panel at `{ … event:name = "exception" }`, which matched the
failures. The AI driver throws in dev because the API key is a placeholder — which is
also why the AI-by-driver and AI-confidence panels are empty (`ai.driver` /
`ai.confidence` are success-path-only attributes); documented the enablement steps.

### Decision: Bump Tempo 2.6.1 → 2.7.2 (shared infra)
Accepted a shared-stack version bump rather than downgrading the attribute panels to
tables. 2.7.2 unlocks `avg/min/max_over_time` over attributes (a 500 on 2.6.1); the
bump is low-risk because EventHorizon's panels are PromQL (Tempo-version-independent),
so nothing existing regressed. Rejected adding a Sentinel `MeterProvider` (anti-goal).
Deferred: real attribute percentiles (need a later Tempo or a histogram metric) and
the Loki logs panel (Sentinel still logs via Monolog, not OTLP→Loki — Phase 5).

---

## Phase 7 — Weighted Transaction Simulation + Benchmark Seeder — 2026-07-01
Files: database/seeders/TransactionSeeder.php, config/sentinel.php, app/Services/TransactionStreamService.php, app/Services/EmbeddingService.php

Replaced the flat, uniform-probability merchant list driving `sentinel:stream`
with a set of weighted merchant profiles, and added `TransactionSeeder` — a
seeder that runs 500 transactions through the real pipeline
(`TransactionStreamService::generate()` → `TransactionProcessorService::process()`)
and prints a benchmark table of cache-hit rate, fallbacks, and threat rate.

### Pattern: Weighted Random Selection via Index-Repetition Pool
`config('sentinel.simulation.merchants')` is now a list of profiles, each
carrying a `weight`. `TransactionStreamService::generate()` builds a pool
array once per generator lifetime by repeating each profile's index `weight`
times, then draws from it with `array_rand()`. This is weighted sampling
without pulling in a dedicated weighted-random-choice algorithm (e.g.
alias method) — proportional representation falls out of how many times an
index appears in the pool, and the per-draw cost stays a single `array_rand()`
call. Each profile also carries its own `amount_min`/`amount_max` and
`currencies`, so amount ranges are now realistic per merchant category
instead of one global range applied to every merchant.

### Anti-Pattern Avoided: Uniform-Probability Merchant Selection
The old `array_rand($merchants)` over a flat list gave every merchant equal
selection probability regardless of real-world transaction volume — a
low-frequency forex profile would appear in the simulated stream exactly as
often as a high-frequency grocery profile. That flattens the traffic
distribution the benchmark is supposed to be measuring cache behavior
against. The weighted pool fixes this directly at the sampling step rather
than by post-hoc filtering.

### Decision: Fold Free-Text `message` into the Semantic-Cache Fingerprint
`EmbeddingService::createTransactionFingerprint()` now appends
`Message: <template text>` to the fingerprint string, and each merchant
category has 4–5 message templates it draws from at random. This raises
fingerprint entropy — two transactions identical in amount tier, category,
merchant, and time bucket can now still land on different fingerprints
depending on which template was picked, which cuts against the cache-hit
rate the new seeder is trying to benchmark. Went ahead with it anyway,
judged as a reasonable design choice for now; it intersects the
already-open ADR-0002 evaluation of which fingerprint fields help vs. hurt
cache-hit rate, and the ADR-0015 question of whether the 0.95 similarity
threshold is too strict. No ADR update accompanies this change — worth
revisiting together with ADR-0002/ADR-0015 rather than in isolation.

---

## Phase 8 — Ollama Embedding Provider Decision (ADR-0025) — 2026-07-01
Files: docs/adr/0025-ollama-local-embedding-provider.md

Decision-only step, no code yet. With an Ollama server now available, and
Gemini's embedding quota continuing to be the first thing to fail under
burst load (per ADR-0005, ~57 transactions before exhaustion — confirmed
again by the Phase 7 benchmark seeder), wrote ADR-0025 to record the switch
to a local `nomic-embed-text` v1.5 (768-dim) embedding provider ahead of
implementation.

### Decision: `EmbeddingDriver` Interface Mirrors `ComplianceDriver`
Rather than growing `EmbeddingService` with an if/else on provider, the plan
is to give embeddings the same Service Manager treatment `ComplianceDriver`
already has (ADR-0006): an `EmbeddingDriver` contract, `GeminiEmbeddingDriver`
/ `OllamaEmbeddingDriver` implementations, and an `EmbeddingManager` resolving
from a new `SENTINEL_EMBEDDING_DRIVER` env var. Checked `ComplianceDriver` /
`ComplianceManager` / `GeminiDriver` structure before drafting this so the
new interface matches established shape rather than inventing a parallel
pattern.

### Decision: Task-Prefix Parameter Lives on the Interface, Not the Driver
Nomic v1.5 expects `search_document:` / `search_query:` prefixes on indexed
vs. query text — skipping this doesn't error, it just quietly degrades
retrieval quality, which is exactly the silent-partial-failure shape already
flagged as a standing concern in this project. Put a `$task` parameter with
a `TASK_DOCUMENT` default on `EmbeddingDriver::embed()` itself (not bolted
onto `OllamaEmbeddingDriver` alone) so call sites declare intent once and it
degrades to a no-op for whichever driver doesn't need it (Gemini).

### Challenges
Two things complicated what looked like a simple provider swap:

1. **Fixed vector index dimension.** Upstash Vector's index dimension
   (1536, matching `gemini-embedding-001`) can't be changed in place — a
   provider swap means recreating the index and re-ingesting the policy KB
   (ns:`policies`), sequenced carefully so `sentinel:ingest` re-runs
   immediately after recreation, not before (a gap there means RAG silently
   returns zero chunks).
2. **The transaction fingerprint has no clean query/document split.** Policy
   RAG has an obvious asymmetric split (ingest = document, search = query),
   but the semantic-cache fingerprint embed (`TransactionProcessorService`)
   is used both to search the cache and, on a miss, to become the new cache
   entry — there's no "question vs. passage" shape to it. Resolved by
   treating it as `TASK_DOCUMENT` on both sides (dedup/clustering framing —
   consistency between the two comparison sides matters more than which
   specific prefix is nominally correct), documented as the one genuine
   judgment call in ADR-0025 rather than an obvious default.

Implementation (the driver classes, config wiring, and the actual index
migration) is a follow-up step — not done in this entry.

---

## Phase 9 — EmbeddingDriver Interface + Ollama/Gemini Drivers (ADR-0025 wiring) — 2026-07-01
Files: app/Contracts/EmbeddingDriver.php, app/Services/Embedding/GeminiEmbeddingDriver.php, app/Services/Embedding/OllamaEmbeddingDriver.php, app/Services/EmbeddingManager.php, app/Services/EmbeddingService.php, app/Providers/AppServiceProvider.php, config/sentinel.php, config/services.php, .env.example, app/Mcp/Tools/SearchPolicies.php, app/Services/Compliance/GeminiDriver.php, app/Services/Compliance/OpenRouterDriver.php, tests/Unit/EmbeddingServiceTest.php, tests/Unit/GeminiEmbeddingDriverTest.php, tests/Unit/OllamaEmbeddingDriverTest.php

Implemented the structure ADR-0025 designed on paper: an `EmbeddingDriver`
contract, `GeminiEmbeddingDriver` / `OllamaEmbeddingDriver` implementations,
and an `EmbeddingManager` (`Illuminate\Support\Manager`) resolving from
`SENTINEL_EMBEDDING_DRIVER` — the same shape as the existing
`ComplianceDriver`/`ComplianceManager` pair. `EmbeddingService` no longer
makes the Gemini HTTP call itself; it now takes an `EmbeddingDriver` in its
constructor and delegates `embed()` to it, keeping only
`createTransactionFingerprint()` (provider-agnostic) as its own logic.

### Pattern: Split a Concrete Service Into "Business Logic" + "Delegated I/O"
Rather than making `EmbeddingService` itself implement `EmbeddingDriver` (or
replacing it wholesale), it stayed the single class every call site already
injects, but its constructor now takes an `EmbeddingDriver` and `embed()`
becomes a one-line delegation. Fingerprint construction — which has nothing
to do with which embedding provider is active — stays put. This kept every
call site (`TransactionProcessorService`, `SentinelIngest`, `SearchPolicies`,
`GeminiDriver`, `OpenRouterDriver`) unchanged except for the two that needed
to pass `EmbeddingDriver::TASK_QUERY` explicitly.

### Decision: Task-Prefix Constant Threaded Through Every RAG Query Call Site
Both `GeminiDriver::fetchPolicyContext()` and `OpenRouterDriver`'s equivalent
policy-context lookup, plus the MCP `SearchPolicies` tool, embed a *query*
string against the already-indexed `policies` namespace — all three now pass
`EmbeddingDriver::TASK_QUERY` explicitly instead of relying on the
`TASK_DOCUMENT` default. `GeminiEmbeddingDriver` ignores the parameter
entirely (Gemini has no prefix convention), so this costs nothing today but
means flipping `SENTINEL_EMBEDDING_DRIVER` to `ollama` doesn't silently
regress RAG retrieval quality — the correct prefix is already wired at every
call site, not something to retrofit later.

### Challenges
Swapping `EmbeddingService`'s constructor signature broke `new
EmbeddingService()` everywhere it was called directly — 22 tests in
`EmbeddingServiceTest.php` that exercised the old inline Gemini HTTP call.
Rather than patch each call site with a dummy argument, split the file: the
`createTransactionFingerprint()` tests stayed in `EmbeddingServiceTest.php`
(now instantiating with a `Mockery::mock(EmbeddingDriver::class)`, since
fingerprint tests never touch `embed()`), and the HTTP-behavior tests moved
wholesale to a new `GeminiEmbeddingDriverTest.php` targeting the class that
now actually owns that logic. Confirmed via `git stash` that the one
fingerprint test still failing (`pipe-delimits the fingerprint fields`,
asserting 4 pipe-delimited sections when the Phase 7 `message` field pushed
it to 5) and the `ArchTest` `TraceContextExtractor` gap both pre-date this
phase — same 3 failures on `master` with or without this change, confirming
no regression was introduced by the refactor.

Tests that mock `EmbeddingService` directly (`GeminiDriverTest`,
`OpenRouterDriverTest`, `SentinelIngestTest`, `TransactionProcessorServiceTest`,
`WatchTransactionsTest`) needed no changes — none of them constrain `embed()`
call arguments with `->with(...)`, so adding the `$task` parameter to real
call sites didn't invalidate their mocks.

Upstash Vector index recreation, `sentinel:ingest` re-run at 768 dimensions,
and re-validating the similarity threshold against nomic's score
distribution are still open — this phase is code-complete but the actual
provider cutover (flipping `SENTINEL_EMBEDDING_DRIVER=ollama` against a real
index) has not happened yet.

---

## Phase 10 — Ollama Embedding Cutover + Upstash Namespace Endpoint Fix — 2026-07-02
Files: .env (local, not committed), .env.example, config/services.php, app/Services/VectorCacheService.php, app/Console/Commands/SentinelIngest.php, phpunit.xml, tests/Unit/VectorCacheServiceTest.php, tests/Unit/SentinelIngestTest.php, tests/Unit/Mcp/SearchPoliciesToolTest.php

User recreated the Upstash Vector index at 768 dimensions and pointed
`.env` at a real Ollama server, then asked to re-run `sentinel:ingest`.
Running it surfaced two pre-existing bugs that had nothing to do with the
Phase 9 wiring — the embedding driver swap just happened to be the first
thing to exercise this code path for real.

### Challenge: `.env` Had Two Malformed Values Blocking Ollama Entirely
`OLLAMA_URL = <ip>:11434` — space before `=` (harmless; phpdotenv trims it)
but no `http://` scheme, which breaks Guzzle's URL parsing outright. Also
`OLLAMA_EMBEDDING_MODEL` was commented out, defaulting to bare
`nomic-embed-text`, but the server only had `nomic-embed-text:v1.5` pulled —
Ollama has no implicit `:latest` alias unless the model was pulled without
a tag. Both confirmed by hand: `curl .../api/tags` listed the exact tag
required; a direct `/api/embeddings` call with the bare name 404'd
(`"model not found, try pulling it first"`) while the tagged name worked.

### Anti-Pattern Avoided: Trusting a Command's Own Success Output
`sentinel:ingest` printed "Done. 4 chunks indexed, 0 failed." on the first
run — and it was lying. Ran a real vector search afterward (`results: 0`)
before believing the ingest actually worked, which is what surfaced both
bugs below. A command's own reported exit status is not verification;
checking the state it claims to have changed is.

### Challenge: `VectorCacheService`'s Namespace Endpoints Used the Wrong URL Shape
`searchNamespace()`/`upsertNamespace()` posted to
`{baseUrl}/namespaces/{ns}/query` and `/namespaces/{ns}/upsert` — not a
real Upstash Vector REST endpoint. The correct shape (confirmed via direct
`curl` against the real Upstash instance) is `{baseUrl}/query/{ns}` and
`{baseUrl}/upsert/{ns}` — namespace as a trailing path segment, not nested
under `/namespaces/`. Every namespace-scoped call had been 404ing since
this code was written; the non-namespaced `search()`/`upsert()` methods
(ns:`default`, semantic cache) were unaffected because they never had a
namespace segment to get wrong. This means policy RAG (ns:`policies`,
ADR-0008's dual-namespace strategy) had likely never actually worked in
any environment that exercised it against real Upstash — masked because
`SentinelIngestTest` and the compliance-driver tests all mock
`VectorCacheService` wholesale rather than faking HTTP at the real URL
shape, so nothing ever asserted the literal path being hit.

### Anti-Pattern Avoided: Mocking Away the Thing That Was Actually Broken
The existing `VectorCacheServiceTest` coverage for `searchNamespace` faked
`Http::fake(['*/namespaces/policies/query' => ...])` — asserting the *bug*
as if it were the contract, since the fake pattern matched whatever the
code happened to send. Fixed both the implementation and the test fakes
together, and added new tests that assert the exact resulting URL
(`{baseUrl}/query/{namespace}`, `{baseUrl}/upsert/{namespace}`) rather than
just asserting payload shape — a test that fakes the same wrong path the
code uses can never catch that the path itself is wrong.

### Challenge: `SentinelIngest` Never Checked `upsertNamespace()`'s Return Value
`upsertNamespace()` catches its own HTTP failures and returns `false` — it
doesn't throw. `SentinelIngest::handle()` only counted a chunk as failed
inside a `catch` block, so a `false` return was silently treated as
success. Fixed by throwing when `upsertNamespace()` returns `false`, which
routes it through the existing catch/count/warn path. Root-caused before
the endpoint-path bug was found — the ingest command's own reporting could
not be trusted to reveal the underlying problem.

### Decision: Pin `SENTINEL_EMBEDDING_DRIVER` in `phpunit.xml`
Fixing the endpoint bug and re-running the real ingest set `.env`'s
`SENTINEL_EMBEDDING_DRIVER=ollama` for the first time — which then broke
`SearchPoliciesToolTest` with a real `ConnectionException`, because that
test resolves `EmbeddingService` through the container rather than mocking
it, and its `Http::fake(['*embedContent*' => ...])` only matches Gemini's
URL shape. `SENTINEL_AI_DRIVER` has the same latent coupling (no pin in
`phpunit.xml`) but happened to never break because `.env`'s default already
matched what tests assumed. Added `<env name="SENTINEL_EMBEDDING_DRIVER"
value="gemini"/>` to `phpunit.xml` so test behavior no longer depends on
whatever a developer's local `.env` happens to be pointed at — matching how
`APP_ENV`, `CACHE_STORE`, etc. are already pinned there rather than left to
inherit from `.env`.

Verified end-to-end after all fixes: a real query embed through Ollama
(`search_query:` prefix, 768-dim) against the recreated Upstash index
returned 3 relevant policy chunks (top score 0.83, correctly ranked the
AML policy highest for an AML query) — not just a clean test run, but the
actual retrieval path working against real infrastructure.

---

## Phase 11 — Fix Permanently-Tripped XLEN Backpressure Gate — 2026-07-02
Files: app/Console/Commands/StreamTransactions.php, config/sentinel.php, tests/Feature/StreamTransactionsTest.php

With the Ollama cutover live, tried to generate fresh demo data by running
all services and `sentinel:stream --limit=100`. Nothing moved: the
dashboard total sat at 307, `sentinel:watch` was visibly processing
transactions with an implausible ~99% cache-hit rate, and Upstash's
`default` namespace held exactly one vector. Three symptoms, one root
cause.

### Anti-Pattern Avoided: Debugging Symptoms Instead of Finding the One Cause
The instinct was to chase each symptom separately — investigate the high
hit rate as an embedding-quality problem, investigate "stuck at 307" as a
dashboard refresh problem. Both were red herrings. Stepping back with a
single side-by-side check (`XLEN` vs `XPENDING` summary on the same
stream) resolved all three symptoms at once: `XLEN` read 801 while the
consumer group's pending count was `0` — a fully-drained backlog reported
as a deep one.

### Challenge: `XADD ... MAXLEN ~ 1000` Makes Raw Stream Size a Bad Backpressure Signal
`sentinel:stream`'s original backpressure gate (ADR-0022 "step 1") paused
the producer whenever `TransactionStreamService::depth()` (raw `XLEN`)
exceeded 800. Approximate `MAXLEN` trimming only removes entries as new
ones are added — it does nothing in response to consumption. Once the
stream has ever grown to ~1000 entries in its lifetime, `XLEN` stays
pinned near that ceiling indefinitely, regardless of how completely the
consumer group has caught up. The gate had nothing left to measure once
that point was reached; it just permanently blocked the producer. Every
transaction `sentinel:watch` was processing (odd, unfamiliar merchant
names like "Blendz" and "Sandwich.net" that don't exist in the current
`config('sentinel.simulation.merchants')`) turned out to be old backlog
sitting in that near-1000-entry buffer from long before the current
merchant profiles existed — not anything from this session.

### Decision: Delete the XLEN Gate Rather Than Patch It
ADR-0023's graduated consumer-lag backpressure (`XPENDING`-based
`lag_warn`/`lag_pause`) already measures the thing that actually matters —
real unacknowledged backlog — and sits two checks below the broken gate in
the same loop. Removed the `XLEN` gate entirely instead of trying to
recalibrate its threshold, since no threshold fixes a signal that doesn't
correlate with backlog once `MAXLEN` trimming is in play. Also removed the
now-dead `publish_pause_threshold`/`publish_pause_ms` config and the test
that exercised the deleted gate. `TransactionStreamService::depth()`
itself was left in place — it is not wrong, just not useful for this
purpose — since it's still directly tested and cost nothing to keep.

### Challenge: `TransactionProcessorService` Also Never Resets Once Started
A smaller, related lesson: `sentinel_metrics_*` counters are plain Redis
keys with no session boundary — `sentinel:reset-metrics` was never run
this session, so the dashboard's "307" mixed weeks-old accumulated counts
with the ~99 events actually processed by this session's worker. Not a
bug, but a reminder that a stat looking static doesn't mean nothing is
happening — cross-checking a command's own live log against the
cumulative counter it feeds was what separated "no new activity" from
"old data dominates the total."

Verified after the fix: `sentinel:stream --limit=100` published all 100
transactions immediately (zero pause messages, vs. zero *successful*
publishes across ~16 minutes and 1973 pause messages before the fix).
Upstash `default` namespace grew from 1 vector to 3+ during the drain;
Postgres `transactions` table (cleared to 0 beforehand for a clean read)
picked up 91 new rows within seconds of the run finishing.

---

## Phase 12 — Named Vector Namespaces, Retire Implicit Default (ADR-0026) — 2026-07-02
Files: docs/adr/0026-named-vector-namespaces-retire-default.md, app/Services/VectorCacheService.php, app/Services/TransactionProcessorService.php, tests/Unit/VectorCacheServiceTest.php, tests/Unit/TransactionProcessorServiceTest.php, tests/Feature/WatchTransactionsTest.php, tests/Unit/Mcp/AnalyzeTransactionToolTest.php, README.md, CLAUDE.md

While confirming the Ollama cutover's data in Upstash, only the `default`
namespace was being checked, which is a symptom of a real design smell:
the transaction semantic cache has lived in Upstash's implicit,
unnamed default namespace since ADR-0008, while `policies` is explicitly
named. With multi-tenancy and telemetry-namespace support already on the
roadmap, an implicit exception to an otherwise-named-namespace convention
only gets more confusing as more namespaces are added. Wrote ADR-0026 and
retired the implicit-default code path entirely.

### Decision: Delete the Bare Methods, Don't Just Add a Namespace Argument
`VectorCacheService::search()`/`upsert()`/`delete()` (bare `/query`,
`/upsert`, `/delete` — Upstash's implicit default namespace) are gone, not
deprecated-in-place. Every caller now goes through `searchNamespace()`/
`upsertNamespace()`/`deleteNamespace()` with an explicit namespace string.
`TransactionProcessorService` gained `const NAMESPACE = 'transactions'`,
matching `SentinelIngest`'s existing `const NAMESPACE = 'policies'`
convention. Leaving the bare methods in place "just in case" would have
preserved exactly the inconsistency this ADR exists to remove — a
namespace with no name only stops being confusing once nothing can
address it anymore.

### Challenge: Return-Shape Mismatch Between the Old and New Methods
`search()` returned a single best-match array or `null`. `searchNamespace()`
returns a list of all matches at or above threshold. `TransactionProcessorService`
now calls `searchNamespace($vector, self::NAMESPACE, $threshold, 1)` and
takes `$results[0] ?? null` to reconstruct the old single-match contract —
the threshold itself moved from a constructor-injected property on
`VectorCacheService` (read once from config) to an explicit per-call
argument, since the service is now purely a generic namespaced Upstash
client with no cache-specific defaults baked in.

### Challenge: The Mock/Fake Blast Radius Was Larger Than Expected
Renaming two methods on a widely-mocked service touched four test files:
`VectorCacheServiceTest` (own coverage, rewritten around the new
namespaced methods and `transactions` namespace), `TransactionProcessorServiceTest`
and `WatchTransactionsTest` (Mockery mocks of `VectorCacheService`, ~35
occurrences across both — `shouldReceive('search')` → `shouldReceive('searchNamespace')`
plus wrapping single-match returns in a list, `null` → `[]`), and
`AnalyzeTransactionToolTest` (real `Http::fake()` against `*/query`/`*/upsert`,
which stopped matching once the real HTTP calls moved to `/query/transactions`/
`/upsert/transactions`). Caught the `WatchTransactionsTest` and
`AnalyzeTransactionToolTest` regressions by running the full suite and
diffing against a `git stash`-based baseline rather than assuming the
directly-touched test files were the only blast radius — the same
verification discipline established in Phase 10.

Verified: full suite back to the pre-existing 2-4 flaky/unrelated
failures (Phase 7 fingerprint entropy and merchant-config tests, plus an
order-dependent `ArchTest` gap) with zero failures attributable to this
change.

---

## Phase 13 — Close ADR-0007 Tier 2 Implementation Drift — 2026-07-03
Files: app/Contracts/ComplianceDriver.php, app/Services/Compliance/GeminiDriver.php, app/Services/Compliance/OpenRouterDriver.php, app/Services/TransactionProcessorService.php, prompts/transaction-compliance-analysis.md, prompts/transaction-compliance-analysis.txt, tests/Feature/WatchTransactionsTest.php, tests/Unit/GeminiDriverTest.php, tests/Unit/OpenRouterDriverTest.php, tests/Unit/TransactionProcessorServiceTest.php, README.md

ADR-0007 specifies Tier 2 as "Gemini Flash + policy RAG" on a cache miss,
with `ThreatAnalysisService` reserved for Tier 3 (infra failure only). The
implementation had drifted: `TransactionProcessorService` called
`ThreatAnalysisService::analyze()` unconditionally on cache miss, so no
LLM was ever in the transaction pipeline's normal path — only the Axiom
pipeline used AI. Added `ComplianceDriver::analyzeTransaction()` (new
interface method, implemented on both `GeminiDriver` and `OpenRouterDriver`)
and wired it into `TransactionProcessorService`'s cache-miss branch;
`ThreatAnalysisService::analyze()` now runs only inside the outer
`catch (\Throwable)`, matching the ADR.

### Decision: New Prompt File and Query-Text Builder, Not Reuse of the Axiom Prompt
`analyzeTransaction()` gets its own prompt (`transaction-compliance-analysis`)
and its own RAG query-text builder (`buildTransactionQueryText()`) on both
drivers, rather than adapting the existing Axiom-shaped
`compliance-audit-narrative` prompt. The two inputs are shaped differently
(a `{merchant, amount, currency}` transaction vs. an Axiom's anomaly
payload) and forcing one template to serve both would mean conditional
logic inside the prompt text itself — the same anti-pattern the Prompts
Convention exists to prevent. Both prompts converge on the same output
schema so `parseResponse()`/`logResponseQuality()` are shared unchanged.

### Challenge: A Stale Test Mock Only Surfaced Once the Real Bug Was Fixed
After wiring in the driver call, one `WatchTransactionsTest` case —
"writes the pending count to the lag key" — failed with `Typed property
App\Services\ThreatResult::$isThreat must not be accessed before
initialization`, thrown from inside the Tier 3 fallback branch. The test's
`ThreatAnalysisService` mock was `shouldNotReceive('analyze')` (correct,
now that Tier 2 shouldn't touch it), yet `analyze()` was still being
called. Root cause: the test's `VectorCacheService::upsertNamespace` mock
still stubbed `andReturnNull()`, a leftover from the pre-fix version of
this test file — every other test in the same file had already been
updated to `andReturn(true)` to match the method's `bool` return type.
`upsertNamespace()` returning `null` against a `bool` return type is a
`TypeError`, which the outer `catch (\Throwable)` swallows and treats as
an infra failure, forcing the Tier 3 path — which is exactly what masked
the problem before this fix: on master, cache miss unconditionally called
`ThreatAnalysisService`, so the broken mock's forced fallback was
indistinguishable from the normal path and the test passed for the wrong
reason. Fixed by changing the one leftover `andReturnNull()` to
`andReturn(true)`. Lesson: a mock that silently coerces a real method
into throwing can hide behind a bug that already routes through the same
catch block — fixing the bug is what exposed the mock defect, not a
regression introduced by the fix.

Verified: `TransactionProcessorServiceTest`, `GeminiDriverTest`,
`OpenRouterDriverTest`, and `WatchTransactionsTest` all green (85/85).
Full suite has no new failures relative to master — remaining failures
(`EmbeddingServiceTest`, `TransactionStreamServiceTest` ×2, one
order-dependent `ArchTest` case) are pre-existing and identical on both
branches; this worktree additionally shows 10 Inertia/Auth/Example
failures from a missing `public/build/manifest.json`, which is a
worktree Vite-build artifact gap, not a code regression.
