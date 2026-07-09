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

---

## Phase 14 — Rule-Based Tier 3 Fallback for the Axiom Pipeline — 2026-07-03
Files: app/Services/AxiomThreatAnalysisService.php, app/Services/AxiomProcessorService.php, tests/Unit/AxiomThreatAnalysisServiceTest.php, tests/Unit/AxiomProcessorServiceTest.php, README.md

The transaction pipeline has had a Tier 3 rule-based fallback
(`ThreatAnalysisService`) since ADR-0007, but the Axiom pipeline never
got an equivalent: when `AxiomProcessorService::routeToAi()` caught a
`\Throwable` from the AI driver, it logged the error and moved on,
leaving `$result` at its initial defaults — `risk_level: 'unknown'`,
`narrative: null` — and persisted `driver_used` as the *configured*
driver name even though that driver never actually produced a verdict.
An Axiom that already breached `AXIOM_AUDIT_THRESHOLD` (that's the only
way `routeToAi()` gets called at all) would silently get no verdict on
an outage, with no observable signal distinguishing "AI succeeded" from
"AI failed but we said nothing."

### Decision: A Deterministic Single-Verdict Fallback, Not a Second Threshold Ladder
`AxiomThreatAnalysisService::analyze()` always returns `risk_level: 'high'`
with a narrative citing the anomaly score, the configured
`axiom_threshold`, and the domain. No second "how bad is bad" threshold
was introduced. The transaction pipeline's `ThreatAnalysisService` fallback
rule (amount vs. `high_risk` threshold) is meaningful because it's
independent of why the cache missed — a cache miss carries no signal about
transaction risk. The Axiom fallback is different: by the time
`routeToAi()` runs, the anomaly score has *already* cleared the audit
threshold, so the one fact worth restating is that the breach happened
and by how much — not re-deriving a graduated verdict the routing
decision already made. Adding a second, arbitrary "critical" cutoff would
have been complexity with no signal behind it.

### Pattern: `driver_used: 'fallback'` Mirrors the Transaction Pipeline's `source` Field
`TransactionProcessorService::process()` already makes its active tier
observable via a `source` field (`cache_hit` | `cache_miss` | `fallback`).
`AxiomProcessorService` had `driver_used`, but it was set unconditionally
to `config('sentinel.ai_driver')` regardless of whether the driver call
actually succeeded — a Gemini outage and a healthy Gemini call were
indistinguishable in the persisted `ComplianceEvent`. `driver_used` is now
set to `'fallback'` specifically in the catch branch, giving the same
per-tier observability the transaction pipeline already had.

### Challenges
Two tests in `AxiomProcessorServiceTest.php` encoded the old (buggy)
behavior as the expected outcome — `it persists the event with null
narrative when the driver throws` was asserting the absence of a
verdict as correct. Renamed to `it falls back to a rule-based verdict
when the driver throws` and rewrote its assertions around the new
`risk_level: 'high'` / `driver_used: 'fallback'` / non-null narrative.
No other test files needed changes: `WatchAxiomsTest` mocks
`AxiomProcessorService::process()` wholesale with pre-baked return
arrays (including one still using `risk_level: 'unknown'`), so it
exercises the command's handling of arbitrary result shapes rather than
`AxiomProcessorService`'s internal fallback logic, and stayed valid
unchanged.

Verified: `AxiomProcessorServiceTest` (new + existing), the new
`AxiomThreatAnalysisServiceTest`, and `WatchAxiomsTest` all green.
Full suite: 282 passed vs. 278 before this phase, same 3 pre-existing
failures (`EmbeddingServiceTest`, `TransactionStreamServiceTest` ×2) and
the same order-dependent `ArchTest` case — nothing attributable to this
change.

---

## Phase 15 — Ollama as Default Compliance-Analysis Driver (ADR-0027) — 2026-07-04
Files: app/Services/Compliance/AbstractComplianceDriver.php, app/Services/Compliance/GeminiDriver.php, app/Services/Compliance/OpenRouterDriver.php, app/Services/Compliance/OllamaDriver.php, app/Services/ComplianceManager.php, config/sentinel.php, config/services.php, .env.example, tests/Unit/OllamaDriverTest.php, tests/Unit/ComplianceManagerTest.php, prompts/compliance-audit-narrative.md, prompts/transaction-compliance-analysis.md, docs/adr/0027-ollama-compliance-analysis-driver.md, README.md, CLAUDE.md, docs/SERVICES.md, docs/ARCHITECTURE.md, docs/AI_PIPELINE.md, docs/diagrams/SYSTEM_ARCHITECTURE.md, docs/DEV_GETTING_STARTED.md, docs/DEPLOYMENT.md, docs/USER_STORIES.md

ADR-0025 adopted Ollama for embeddings only, explicitly leaving
compliance analysis on Gemini/OpenRouter. With a Tailscale-reachable
Ollama host now available for chat too, the ask was to make it the
default LLM driver "rather than accumulating additional tech debt" —
meaning not a third copy-pasted driver file. Landed in three
independently-committable phases: (1) extract `AbstractComplianceDriver`
from `GeminiDriver`/`OpenRouterDriver` — pure refactor, zero behavior
change; (2) add `OllamaDriver` as a third subclass, wired in but not yet
default; (3) flip `config('sentinel.ai_driver')`'s default to `'ollama'`,
write ADR-0027, sweep documentation.

### Decision: Abstract the Three Drivers, Despite House Convention Being the Opposite
`GeminiDriver`/`OpenRouterDriver` were ~95% byte-identical — diffing them
showed only the outbound HTTP call and the log-message class-name prefix
differed, everything else (prompt building, RAG retrieval, quality
scoring, response parsing) was copy-pasted verbatim. Yet this codebase's
established pattern is the opposite: ADR-0025 explicitly mirrored ADR-0006
by keeping `GeminiEmbeddingDriver`/`OllamaEmbeddingDriver` fully
independent, "no shared implementation between provider drivers." This
decision is a deliberate, named exception to that convention for the
`ComplianceDriver` trio specifically — justified by degree, not by a
change of philosophy: 95% duplication three times over is a different
risk profile than "some overlap" once. The embedding-driver pair was
deliberately left untouched.

### Challenge: `static::class` Broke the Hoist on First Try
The first version of `AbstractComplianceDriver` used `static::class` to
preserve each subclass's log-message prefix through late static binding.
Running the untouched `GeminiDriverTest`/`OpenRouterDriverTest` against it
immediately failed — `static::class` resolves to the *fully-qualified*
class name (`App\Services\Compliance\OpenRouterDriver`), not the short
name (`OpenRouterDriver`) the existing tests assert on. Fixed with
`class_basename(static::class)`. This is exactly the kind of thing a pure
refactor's "run the untouched tests first" acceptance gate is for — the
mistake was caught in seconds by tests that were never touched, rather
than by manually re-deriving what every log call should say.

### Pattern: Verify Live-Host Mechanics Before Writing the Implementation, Not After
Rather than guess at Ollama's `/api/chat` response shape and streaming
behavior, did a live `curl` against the real host before writing
`OllamaDriver::callModel()`. This surfaced two things that would have been
easy to get wrong silently: `/api/chat` streams NDJSON unless `"stream":
false` is set explicitly (would have silently broken response parsing),
and the default model (`qwen3.5:9b-q4_K_M`) is a hybrid reasoning model —
a trivial echo-JSON test took **20.6s** with its `thinking` phase left on
versus **0.96s** with `"think": false` set, a ~20x difference for
identical `message.content` output. A follow-up live test with a realistic
compliance prompt (a $49,900 structuring-pattern transaction) confirmed
`think: false` still produces schema-correct, semantically correct output
in ~3.9s, and a full end-to-end Tinker call through
`TransactionProcessorService->analyzeTransaction()` (real embedding + real
policy RAG + real Ollama call) completed in ~12s and correctly cited real
policy references from the ingested KB.

### Decision: 32k-Context Model Tag, No 64k Variant
The two runtime prompt templates are ~65-76 words of fixed text; the only
variable part (`{policy_context}`) is capped at literally 3 policy chunks
of ~500 target words each (hardcoded in `fetchPolicyContext()`). Worst
case ≈2,100-2,200 tokens — the `32qwen3.5:latest` tag's 32k window has
~10-15x headroom. No 64k-context tag exists on the host and the token math
shows none is needed; creating one would have been unnecessary ops work
against a requirement that doesn't exist.

### Challenge: Testing a Config Default That the Live Environment Already Overrides
`ComplianceManagerTest`'s new "defaults to ollama when unset" case can't
just call `config('sentinel.ai_driver')`, because this dev environment's
real `.env` sets `SENTINEL_AI_DRIVER=openrouter` explicitly — by the time
the app has booted, the config repository already reflects that override,
not the code-level fallback. Worked around it by clearing
`putenv`/`$_ENV`/`$_SERVER` for the one env var inside the test (restored
in a `finally` block) and re-`require`-ing `config/sentinel.php` fresh,
which re-evaluates its `env()` calls against the now-cleared process
state. This is exactly the ADR's own point about explicit env always
winning over the code default — the test had to work around the same
fact the ADR calls out as the reason a live re-flip of `.env` is a
separate, manual follow-up.

Verified: Phase 1 — `GeminiDriverTest`/`OpenRouterDriverTest` pass
unmodified (44 tests), confirming the hoist changed no observable
behavior. Phase 2 — new `OllamaDriverTest` (18 cases) and
`ComplianceManagerTest` (4 cases) pass; live `curl` and a full Tinker
end-to-end call both verified against the real host. Phase 3 —
`ComplianceManagerTest`'s 5th case locks in the default flip. Full suite
304 passed before Phase 3's addition, same 3 pre-existing failures
(`EmbeddingServiceTest`, `TransactionStreamServiceTest` ×2) throughout —
nothing attributable to this change.

---

## Phase 16 — Fix the 3 Pre-Existing Test Failures — 2026-07-04
Files: tests/Unit/EmbeddingServiceTest.php, tests/Unit/TransactionStreamServiceTest.php, CLAUDE.md

The 3 failures carried as "pre-existing, don't fix unless asked" since
at least Phase 7 were all cases of a test asserting an *old* shape of
something the implementation had since evolved past — not real bugs.

**`EmbeddingServiceTest` — "pipe-delimits the fingerprint fields"**
asserted `substr_count($fingerprint, ' | ') === 4` (5 fields). The
fingerprint has had 6 fields (`Amount`, `Type`, `Category`, `Merchant`,
`Time`, `Message`) since the `Message` field was added — the test was
never updated to expect 5 delimiters instead of 4.

**`TransactionStreamServiceTest` — two amount/merchant tests** were
written against a flat, pre-weighted-simulation merchant list. Since the
"Weighted transaction simulation" feature, `config('sentinel.simulation.merchants')`
holds per-merchant profile objects (`{name, category, weight, amount_min,
amount_max, currencies, is_threat}`), not a flat array of merchant-name
strings — `expect($transaction['merchant'])->toBeIn(config(...))` could
never pass, since a merchant name string is never literally an element
of an array of profile objects. Similarly `config('sentinel.simulation.currencies')`
doesn't exist at all (currencies are per-profile); and the flat
`1.00`–`500.00` amount range doesn't hold once merchant profiles like
`Pacific Forex Exchange` (`amount_min: 50000`, `amount_max: 499900`,
i.e. $500–$4999 after the `/100` cents conversion) are in the weighted
pool.

### Decision: Fix the Tests, Not the Implementation
The generator's behavior (weighted per-merchant profiles, per-merchant
currency/amount ranges) is the documented, intentional Phase 7 design —
confirmed against `TransactionStreamService::generate()` and the
shipped-features list in `README.md`. Rewrote both tests to check each
sampled transaction against *its own* merchant profile (`$profiles[$transaction['merchant']]`)
rather than a global range, run over 20 samples per test to exercise the
weighted pool. Verified stable (not just accidentally passing) by running
the two amount/merchant tests 5 times in a row before moving on.

Verified: full suite green 3 runs in a row (308/308, 0 failures) —
previously 3 failed consistently. Updated `CLAUDE.md`'s "known failures"
note, which had also gone stale (it still named `WatchTransactionsTest`'s
mock shape as failing; that one was actually already fixed as a side
effect of the ADR-0007 Tier 2 drift work earlier this session and the
note was never removed). `tests/ArchTest.php`'s `TraceContextExtractor`
gap remains — order-dependent, passes in the full suite, fails run in
isolation; left as noted, unrelated to this fix.

---

## Phase 17 — Per-Request Compliance Driver Override — 2026-07-04
Files: app/Services/TransactionProcessorService.php, app/Mcp/Tools/AnalyzeTransaction.php, tests/Unit/TransactionProcessorServiceTest.php, tests/Unit/Mcp/AnalyzeTransactionToolTest.php, README.md

Added an optional `?string $driverOverride` parameter to
`TransactionProcessorService::process()` and a matching `driver` field on
the `analyze_transaction` MCP tool, so a caller can force a specific
`ComplianceManager` driver (`gemini`/`openrouter`/`ollama`) instead of the
app-wide `SENTINEL_AI_DRIVER` default. Built for arbiter-l8's
cross-provider disagreement layer, which needs to run the same
transaction through two providers and compare verdicts — something
app-wide, config-only driver selection couldn't support. `ComplianceManager`
is now injected into `TransactionProcessorService` as a second dependency
alongside the already-resolved default `ComplianceDriver`, so the override
path can resolve a driver by name at call time without disturbing the
common path.

### Pattern: Manager-by-Name Resolution as an Escape Hatch Alongside a Fixed Default Dependency
`ComplianceManager::driver($name)` already existed for the app-wide
default (`getDefaultDriver()`), but `TransactionProcessorService` only
ever consumed the already-resolved `ComplianceDriver` instance via
constructor injection. Adding `ComplianceManager` itself as a second,
additional dependency — rather than replacing the first — lets the
common path keep its simple, already-resolved driver (matching every
existing caller's expectations unchanged) while the override path gets
late-bound resolution by name only when a caller actually asks for it.

### Anti-Pattern Avoided: Cache Poisoning from Synthetic Disagreement Probes
The obvious naive implementation would let a driver override flow
through the normal cache read/write path. That breaks the entire feature
it exists for: two calls for the same (or a near-duplicate) transaction
with different override drivers would have the second short-circuit on
the first's cached verdict instead of getting an independent answer, and
the shared semantic cache — which real production traffic relies on —
would get poisoned with whichever synthetic eval-driven provider ran
last. The override path skips both the cache read and the write, not
just the read, so eval instrumentation stays fully isolated from the
cache real traffic depends on.

### Anti-Pattern Avoided: Silently Falling Back to Tier 3 on an Override Failure
The normal path's `catch (\Throwable)` block routes any AI-driver failure
to the deterministic rule-based `ThreatAnalysisService` — appropriate for
production traffic that needs *some* answer regardless of provider
health. The override path deliberately does not do this: if it did, two
providers both failing (e.g. Gemini quota exhausted and OpenRouter down)
would both silently produce the same rule-based verdict, and a
disagreement scorer would read that as "the providers agree" when in
fact neither one ever answered. A driver-override failure propagates as
an exception; callers that want this signal handle it themselves.

### Decision: Shared `gradeAiResult()` Helper Instead of Duplicating the Derivation Logic
The override path needs the exact same risk_level/is_threat/narrative/
confidence/policy_refs/message derivation as the normal cache-miss path.
Rather than duplicating those lines in a second branch, extracted them
into a private `gradeAiResult(array $aiResult, string $merchant): array`
called from both places — concrete present duplication, not a
hypothetical future one, so extracting it is a genuine simplification
rather than premature abstraction.

### Decision: `source: 'driver_override'` as a Fourth Pipeline-Source Value
The existing `source` field only ever took `cache_hit`/`cache_miss`/
`fallback`. Rather than overload one of those, added a fourth explicit
value so the override path is unambiguously distinguishable in logs, the
Redis feed, and the `transactions` table — matching the existing
`fallback` value's role as an observability signal for a distinct
pipeline path.

### Challenge: The Feature's Own New Test Was Correct — an Older Sibling Test Wasn't
No implementation challenge in the driver-override code itself — a
straightforward additive extension of an existing, well-tested pipeline.
The one non-trivial decision (cache read/write behavior under override)
was resolved by asking directly rather than guessing, since it carries
production-traffic side-effect implications not specified anywhere in
the originating ADR.

Running the full suite after landing this phase surfaced a real,
pre-existing bug in a *different*, older test in the same file:
`AnalyzeTransactionToolTest`'s "is_threat false for a low-value
transaction on cache miss" flaked intermittently, taking 2-3s per run
(mocked HTTP calls execute in milliseconds — the duration alone was the
tell). Three of the file's cache-miss tests had never actually mocked
the compliance-analysis HTTP call, only the embedding and vector-cache
endpoints — `Http::fake()`'s partial pattern list lets genuinely
unmatched URLs through to the real network. With Gemini/OpenRouter as
the ambient default and a placeholder `test-key`, that unmocked call
failed with a real 401, which `TransactionProcessorService`'s
`catch (\Throwable)` silently routed to the Tier 3 rule-based fallback —
and the test's chosen amounts ($9000/$12.50 against the $400 threshold)
happened to produce the exact `is_threat` values the tests expected. The
tests were never actually exercising the AI-analysis path their names
claimed to test; they were accidentally passing via Tier 3. Once Ollama
became the real default (no API key required, so the call succeeds for
real), the unmocked request started reaching a live, non-deterministic
LLM instead of failing predictably — and "is a $12.50 coffee purchase
low risk" isn't a 100%-guaranteed answer from a live model. Fixed by
adding an explicit `config(['sentinel.ai_driver' => 'ollama'])` plus a
`'*/api/chat'` fake with a fixed risk_level to all three affected tests,
matching the pattern the file's own `driver_override` test already used
for its forced `openrouter` case. Suite duration dropped from ~27s to
~11s across the full run, confirming no other test has the same gap.

Verified: 4 new `TransactionProcessorServiceTest` cases (driver
resolution + `source: driver_override`, cache bypass, exception
propagation instead of Tier 3 fallback, `observe: false` behavior) and 2
new `AnalyzeTransactionToolTest` cases (cache bypass through the MCP
tool, validation error on an unrecognized `driver` name via `Rule::in`).
Full suite: 318/318 passing, stable across 3 consecutive runs.

---

## Phase 18 — Ground-Truth Export Command (`sentinel:export-ground-truth`) — 2026-07-04
Files: app/Console/Commands/ExportGroundTruth.php, tests/Feature/ExportGroundTruthTest.php, README.md

Added `sentinel:export-ground-truth`, an Artisan command that dumps
`TransactionStreamService::generate()`'s pre-AI synthetic transactions as an
`{"examples": [{"input", "expected_label"}]}` JSON payload — arbiter-l8's
offline harness (`run_eval`) consumes this directly as a new fixture
(`tests/fixtures/sentinel_l7_ground_truth.json`), alongside its existing
hand-written one. Built for arbiter-l8's Phase 3 step 8, closing a gap
flagged all the way back at step 5: the harness only ever had a
hand-written, Synapse-shaped fixture to validate the judge against, which
produced a nonsensical 6.7% accuracy number once the judge started
reasoning over real Sentinel-L7-shaped `raw_output` — not because the judge
reasoned badly, but because the fixture's `expected_label` taxonomy didn't
match what was actually being predicted.

### Pattern: Reuse the Existing Pre-AI Label Instead of Adding a New One
`TransactionStreamService::generate()` already yields `is_threat` per
transaction, sourced from `config('sentinel.simulation.merchants')` before
any AI analysis runs — genuine, non-circular ground truth that already
existed for the seeder's own benchmark stats. The export command adds zero
new labeling logic; it just re-shapes the same generator's output into the
`{input, expected_label}` pairs the eval harness's `EvalDataset` expects.

### Decision: Collapse the Binary `is_threat` to `'high'`/`'low'`, Not a New Three-Way Scheme
Ground truth only ever knows a boolean (threat or not) — there's no
pre-AI signal for `medium` vs `critical`. Rather than inventing a new
ground-truth vocabulary, `expected_label` uses exactly the same collapse
`TransactionProcessorService::gradeAiResult()` already applies internally
(`$isThreat ? 'high' : 'low'` as its own rule-based-fallback convention),
so the exported fixture stays consistent with a rule Sentinel-L7 already
follows rather than introducing a second, competing one. Downstream,
arbiter-l8's validation run treated any of `medium`/`high`/`critical`
as "caught the threat" when scoring against this binary ground truth,
matching `is_threat = risk_level !== 'low'` exactly.

### Decision: Zero Redis Side Effects
`generate()` is a pure, infinite generator over `config()` data — it never
touches `publish()`/`depth()`/the consumer group. The export command calls
only `generate()`, so running it has no interaction with the live stream,
idempotency keys, or consumer lag; it's safe to run against a database-only
or fully offline environment.

### Challenge: None
Straightforward reuse of an existing generator; no surprises.

Verified: 4 new `ExportGroundTruthTest` cases (stdout payload shape,
per-example `{input, expected_label}` keys matching the
`analyze-transaction` tool's argument schema, threat-label collapse
correctness, `--output` file-write path via a mocked `File` facade). Full
suite: 322/322 passing.

---

## Phase 19 — Transaction-Pipeline Idempotency Guard (ADR-0028 Prerequisite) — 2026-07-09
Files: database/migrations/2026_07_09_000001_add_txn_id_unique_partial_to_transactions.php, app/Services/TransactionProcessorService.php, app/Console/Commands/WatchTransactions.php, tests/Unit/TransactionProcessorServiceTest.php

While drafting ADR-0028 (billing classification of `transactions.source`/
`compliance_events.driver_used` rows for Ledger-L5), reviewing what "one
row = one billable event" actually requires surfaced a real gap:
`WatchTransactions.php` only ACKs a stream message after
`TransactionProcessorService::process()` fully completes (embed → vector
search → AI call → cache upsert → DB write), and `transactions.txn_id` had
no uniqueness guarantee. A worker crash in that window — a Railway deploy
restart, OOM, host crash, all routine — leaves the message unacked;
`XAUTOCLAIM` (ADR-0022) reclaims and reprocesses it, and nothing stopped a
second billable `cache_miss` row from being written for the same
transaction. Mirrors the Axiom pipeline's existing fix for the identical
`XAUTOCLAIM` redelivery risk: a partial unique index on
`compliance_events.source_id`, an early-exit `EXISTS` check in `process()`,
and `firstOrCreate` + a caught `UniqueConstraintViolationException`.

### Pattern: Partial Unique Index Scoped to the At-Risk Subset, Not the Whole Column
`CREATE UNIQUE INDEX ... WHERE source != 'driver_override'`, raw SQL via
`DB::statement` (Laravel's `Schema` builder / `PostgresGrammar::compileUnique()`
has no fluent way to express a partial index — the same reason the Axiom
migration used raw SQL instead of `$table->unique()`). Structurally
identical to Axiom's `WHERE source_id != 'unknown'`, but for the opposite
reason: Axiom excludes a *shared sentinel value causing accidental
collisions*; this excludes a *source value whose repeats are intentional*
(see next section).

### Anti-Pattern Avoided: A Literal Axiom Mirror Would Have Broken Cross-Provider Comparison
The obvious port — a plain unique index on `txn_id`, and a dedup check
applied to all three of `process()`'s branches — would have silently
broken the `driver_override` feature from Phase 17: arbiter-l8 calls
`process()` once per provider against the *same* transaction on purpose,
to compare verdicts, and each call must persist its own row. Found by
tracing the `driverOverride` branch's own docblock before writing any
code, not by a test catching it after the fact — the index, the early-exit
`EXISTS` check, and the `firstOrCreate` scope in `recordTransaction()` all
explicitly exclude `source = 'driver_override'` for this reason.

### Decision: `source: 'duplicate'` Is Return-Shape-Only, Never Persisted
The early-exit check returns `source: 'duplicate'` to the caller (so
`WatchTransactions.php` can label it correctly instead of falling into the
`default` "⚠️ Fallback" arm), but `recordTransaction()` is never called on
that path — `'duplicate'` never lands in the `transactions.source` column.
Keeps ADR-0028's billing filter untouched at exactly the same 4
`source` values (`cache_hit`/`cache_miss`/`fallback`/`driver_override`)
it already specifies, and keeps the still-deferred migration-comment fix
(`database/migrations/2026_04_03_052019_create_transactions_table.php:22`)
a 4-value fix, not 5.

### Decision: Deterministic Model-Event-Hook Test for the DB-Constraint-Catch Branch
`firstOrCreate()`'s own `SELECT`-then-`INSERT` sequence means a second
synchronous call in a single-threaded test always finds the row via the
read and never reaches the insert — so the `UniqueConstraintViolationException`
catch can't be triggered through the service's normal call path in tests,
and `sqlite :memory:` (this suite's test DB) is single-connection, ruling
out a real concurrent-connection race either way. `AxiomProcessorService`'s
own equivalent catch block has no test for the same reason. Rather than
leave the branch as untested dead code to match that precedent exactly,
forced the exact exception via a `Transaction::creating()` model event
hook (flushed after) — a deliberate, deterministic improvement over the
Axiom test suite's gap, not an oversight.

### Challenges
The real challenge was in the design, not the implementation: the first
instinct was to mirror Axiom's fix literally (plain unique index, dedup
check applied uniformly across all of `process()`'s branches). That would
have compiled, passed a naive test, and silently dropped every second
`driver_override` row in production — a regression in a feature (Phase 17)
that has no equivalent safety net of its own precisely because it's
designed to write multiple rows per `txn_id`. Caught before any code was
written by re-reading `driverOverride`'s docblock during planning, not by
a test failing after the fact — worth noting because it's the kind of
regression a superficially-passing test suite would not have caught
without a test specifically for it (which this phase adds:
`it('allows two driver_override calls for the same txn_id ...')`).

Verified: 12 new `TransactionProcessorServiceTest` cases under a new
Idempotency section (redelivery-after-`cache_hit`/`cache_miss`/`fallback`
returns `duplicate` and does not re-call the AI driver or analyzer;
`observe: false` bypasses the check entirely; `driver_override` is neither
blocked by nor blocks a prior/subsequent normal row for the same `txn_id`;
two `driver_override` calls for the same `txn_id` both persist; schema-level
constraint checks; the `UniqueConstraintViolationException` suppression
path). Zero existing tests needed changes — confirmed no existing test
calls `process()` more than once, so the new `EXISTS` check is a no-op
against an empty table on every prior test. Full suite: 332/332 passing.
