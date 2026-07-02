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
