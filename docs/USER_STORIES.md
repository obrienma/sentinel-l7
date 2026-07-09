# User Stories — Sentinel-L7

Stories are organised by domain. Each story is marked:
- ✅ **Implemented** — delivered in the current codebase
- 🔲 **Aspirational** — not yet built; a TODO exists in README and CLAUDE.md
- 🚫 **Deferred** — explicitly out of scope for this project; see the linked ADR

---

## 💳 Financial Transaction Compliance

### ✅ 🕵️ Process high volumes of transactions without blocking the web process
> As a compliance operator, I want transaction ingestion and analysis to happen asynchronously, so that traffic spikes don't degrade the dashboard or API response times.

*Delivered by:* Redis Streams (`XADD` from web process, `XREADGROUP` in dedicated worker); web process never waits for analysis

---

### ✅ 🕵️ Reduce redundant AI calls for recurring transaction patterns
> As a compliance operator, I want semantically similar transactions to reuse a cached compliance verdict, so that we don't pay for an LLM call on every near-duplicate pattern.

*Delivered by:* `EmbeddingService` fingerprint → Upstash Vector similarity search (ns:`transactions`, threshold ≥ 0.95) → cache hit returns early
*Known issue:* a narrow-profile merchant (tight amount range + templated message) can embed near-identically across every transaction it generates. If the first one is ever misanalyzed, every later similar transaction inherits that stale, wrong verdict indefinitely rather than getting an independent re-analysis. See the "Known issues" section of the README and the 🔲 story below.

---

### ✅ 🕵️ Get a compliance verdict even when AI services are unavailable
> As a compliance operator, I want a rule-based fallback to produce a verdict if the embedding or vector search fails, so that no transaction is silently dropped during an outage.

*Delivered by:* `ThreatAnalysisService` tier-3 fallback (amount thresholds, no I/O); `XACK` always called

---

### ✅ 🕵️ View the live transaction feed on the dashboard
> As a compliance operator, I want to see recently processed transactions in real time, so that I can spot unusual activity as it happens.

*Delivered by:* Redis list feed (`sentinel:recent_transactions`) written by `TransactionProcessorService`; polled by the dashboard every few seconds

---

### ✅ 🕵️ Retain a durable record of every processed transaction, not just the live feed
> As a compliance operator, I want processed transactions written to a permanent store, so that the audit trail survives past the Redis feed's rolling window.

*Delivered by:* `transactions` Postgres table; every call to `TransactionProcessorService::process()` persists a row alongside the ephemeral Redis feed entry

---

### ✅ 🤖 Analyze a transaction on demand via AI agent
> As an AI agent, I want to run the full compliance pipeline against a transaction I'm investigating, so that I can include a grounded compliance verdict in my response.

*Delivered by:* `analyze_transaction` MCP tool → `TransactionProcessorService::process($data, observe: false)`

---

### ✅ 🛠️ Compare AI providers on the same transaction for disagreement measurement
> As a platform engineer, I want to force a specific AI driver on a given transaction and bypass the semantic cache entirely, so that I can score the same input through two different providers and measure where they disagree.

*Delivered by:* `TransactionProcessorService::process($data, driver: 'gemini'|'openrouter'|'ollama')` and the matching `analyze_transaction` MCP parameter — skips cache read/write and never falls back to Tier 3 on failure. Built for arbiter-l8's online disagreement layer.

---

### ✅ 🛠️ Export labeled ground-truth transactions for offline model evaluation
> As a platform engineer, I want a labeled sample of pre-AI transaction outcomes exported to a file, so that I can evaluate a compliance driver's judgments against known-correct labels outside the live pipeline.

*Delivered by:* `php artisan sentinel:export-ground-truth --count=200 --output=ground-truth.json` — feeds arbiter-l8's offline eval fixtures

---

### ✅ 🛠️ Simulate realistic transaction volume for testing
> As a platform engineer, I want simulated traffic to reflect real merchant volume distribution — not a flat uniform random pick — so that load tests and cache-hit-rate measurements resemble production behaviour.

*Delivered by:* `simulation.merchants` config — weighted profiles (category, weight, amount range, currencies, `is_threat`); `TransactionStreamService::generate()` samples via an index-repetition pool

---

### ✅ 🛠️ Benchmark pipeline performance before shipping a change
> As a platform engineer, I want to run N simulated transactions through the live pipeline and get back cache-hit rate, fallback count, embedding-call count, and threat rate, so that I can catch a regression before it reaches production.

*Delivered by:* `database/seeders/TransactionSeeder.php`

---

### 🚫 🕵️ Scope transaction data by tenant
> As a compliance operator at a multi-tenant platform, I want my team's transactions isolated from other tenants, so that I only see data that belongs to my organisation.

*Deferred:* See ADR-0020 — multi-tenancy and RBAC are being built in `rhizo-book` (TypeScript/WorkOS) instead, not planned for Sentinel-L7. The `routes/web.php` placeholder comment remains as an honest marker of known scope.

---

### 🔲 🕵️ Query historical transactions — not just the live feed
> As a compliance officer, I want to search and filter past transactions by date range, merchant, or risk verdict, so that I can investigate an incident after the fact.

*TODO:* The `transactions` Postgres table is now populated on every pipeline run, but no controller, route, or UI exposes it yet — there is no way to query it outside `php artisan tinker` or direct SQL.

---

### 🚫 🕵️ Configure risk thresholds per tenant
> As a compliance operator, I want to set the anomaly score threshold that triggers AI analysis for my tenant, so that high-sensitivity environments can flag more events without changing the platform default.

*Deferred:* See ADR-0020 — per-tenant configuration depends on the tenant data model that was descoped from Sentinel-L7; not planned here. `config/sentinel.php` keeps a single platform-wide `sentinel.axiom_threshold`.

---

### 🔲 🕵️ Catch a stale cached verdict before it silently repeats forever
> As a compliance operator, I want the semantic cache to recognise when it keeps returning the same verdict for a narrow-profile merchant, so that one bad AI judgment doesn't get replayed indefinitely instead of being periodically re-examined.

*TODO:* No cache-invalidation policy or per-merchant TTL exists today. Discovered during arbiter-l8's Phase 3 live judge validation; worked around there via the per-request driver override (which bypasses the cache), not fixed here. See README "Known issues".

---

## 📡 Telemetry Compliance (Synapse-L4 Pipeline)

### ✅ 🕵️ Triage anomalous telemetry without drowning in raw logs
> As a compliance officer, I want high-anomaly telemetry events to be automatically surfaced with AI-generated audit narratives in regulatory language, so that my team reviews flagged events — not raw logs.

*Delivered by:* `sentinel:watch-axioms` threshold gate (`anomaly_score > AXIOM_AUDIT_THRESHOLD`, default 0.8) → active `ComplianceDriver` audit narrative → compliance dashboard

---

### ✅ 🕵️ Complete audit trail — every event, not just flagged ones
> As a compliance officer, I want every ingested telemetry event recorded regardless of anomaly score, so that I can demonstrate to regulators that nothing was silently discarded.

*Delivered by:* `AxiomProcessorService` always writes a `ComplianceEvent` row; `routed_to_ai = false` for sub-threshold events

---

### ✅ 🕵️ Toggle between flagged and full event log
> As a compliance officer, I want to switch between AI-flagged events and the full event log, so that I can investigate context around a flagged event without leaving the dashboard.

*Delivered by:* `?flagged=1/0` filter in `ComplianceController`; toggle button on the compliance page

---

### ✅ 🕵️ Narratives grounded in actual policy documents
> As a compliance officer, I want audit narratives to reference specific regulatory obligations (AML thresholds, HIPAA access rules, GDPR data handling) rather than generic AI output, so that I can act on them without cross-referencing policy documents manually.

*Delivered by:* Policy RAG — `sentinel:ingest` chunking pipeline + `policies/` corpus + score-aware query formulation in `AbstractComplianceDriver`

---

### ✅ 🛠️ Never re-run AI analysis on a re-delivered Axiom
> As a platform engineer, I want a re-delivered Axiom (same `source_id`, retried after a claim or crash) to skip the AI call entirely if it was already processed, so that a stream re-delivery doesn't double-bill an LLM call or produce a duplicate audit record.

*Delivered by:* `AxiomProcessorService` runs an `EXISTS` check on `source_id` before routing to AI; `firstOrCreate` + a partial unique index on `source_id` remain as a concurrent-race fallback at the DB layer

---

### ✅ 🕵️ Get a reasoned verdict for an Axiom even when AI is unavailable
> As a compliance officer, I want a telemetry event that couldn't reach the AI driver to still get a deterministic, threshold-referencing verdict — not a blank narrative — so that an outage doesn't leave gaps in the audit trail that look identical to "nothing happened here."

*Delivered by:* `AxiomThreatAnalysisService` — gives `AxiomProcessorService` an ADR-0007-style deterministic verdict (`risk_level: high`, threshold-referencing narrative) when the AI driver throws; `driver_used` is stamped `fallback` so the degraded path stays observable instead of persisting `risk_level: unknown` / `narrative: null`

---

### 🔲 🕵️ Correlate a compliance event back to the originating EventHorizon event
> As a compliance officer, I want to click a `source_id` in the compliance dashboard and see the raw EventHorizon event that triggered it, so that I have full traceability from audit finding to source event.

*TODO:* `source_id` is stored on `compliance_events` but no deep-link or cross-system lookup is implemented; requires an EventHorizon query API or embedded event snapshot

---

### 🔲 🕵️ Confirm an event's identity survives its full trip, not just at the ends
> As a compliance officer, I want proof that the same event ID present at EventHorizon is the one that lands on the Axiom's `source_id`, so that "audit trail" means an unbroken chain of custody, not two systems that happen to agree at the endpoints.

*TODO:* The early-exit dedup inside `AxiomProcessorService` is done (see above), but the provenance of `source_id` through the full EventHorizon → Synapse-L4 → Axiom chain has not been independently verified end-to-end.

---

### 🔲 🕵️ Ground telemetry audit narratives in the correct domain's policy, every time
> As a compliance officer, I want every Axiom to carry the domain it originated from (AML, HIPAA, GDPR), so that its audit narrative is always grounded in the matching policy chunks — the way transaction analysis already is — instead of falling back to an unfiltered, unscoped retrieval.

*TODO:* `AxiomProcessorService` and `AbstractComplianceDriver` already support an optional `domain` field end-to-end (filtered RAG query, `under_indexed` logging, span attribute), but neither `WatchAxioms` nor the Synapse-L4 emitter is guaranteed to stamp `domain` on every Axiom payload yet. See ADR-0018.

---

## 🏗️ Platform Operations

### ✅ 🛠️ Swap AI backends without code changes
> As a platform engineer, I want to change the AI provider via an environment variable, so that I can respond to quota exhaustion or cost changes without a deployment.

*Delivered by:* `ComplianceManager` (Laravel Service Manager); `SENTINEL_AI_DRIVER=ollama|gemini|openrouter` (`ollama` is the default, see ADR-0027)

---

### ✅ 🛠️ Swap embedding providers without code changes
> As a platform engineer, I want to change the embedding provider via an environment variable, so that I can move off a rate-limited or costly embedding API without touching pipeline code.

*Delivered by:* `EmbeddingManager` (Laravel Service Manager); `SENTINEL_EMBEDDING_DRIVER=ollama|gemini` — Ollama `nomic-embed-text` (768-dim, task-prefixed `search_document`/`search_query`) is the default (ADR-0025), Gemini `embedding-001` (1536-dim) remains available

---

### ✅ 🛠️ No event loss on worker crash
> As a platform engineer, I want failed or stalled messages to be automatically reclaimed and reprocessed by any surviving worker, so that a worker crash doesn't silently drop events and I don't need to run a separate reclaimer process.

*Delivered by:* `XAUTOCLAIM` embedded at the top of every loop iteration on **both** `sentinel:watch` (`transactions`) and `sentinel:watch-axioms` (`synapse:axioms`) — messages idle past `sentinel.reclaim.idle_ms` (default 30000) are claimed by any running sibling on its next iteration; the worker pool scales horizontally with no dedicated reclaimer daemon (ADR-0022, superseding the earlier dedicated `sentinel:reclaim` design)

---

### ✅ 🛠️ Guarantee a poison message can't circulate forever
> As a platform engineer, I want a message that fails processing repeatedly to be hard-acknowledged instead of endlessly reclaimed, so that one malformed event can't loop through the worker pool indefinitely and starve real traffic.

*Delivered by:* delivery-count guard in the `XAUTOCLAIM` pass — `deliveryCount(id)` checked via `XPENDING`; at `>= sentinel.reclaim.delivery_count_limit` (default 3) the worker logs an error and `XACK`s without processing (ADR-0022)

---

### ✅ 🛠️ AI failures don't crash the pipeline
> As a platform engineer, I want a transient AI API failure to be caught and logged rather than crash the consumer, so that one bad response doesn't take down the worker process.

*Delivered by:* `try/catch(\Throwable)` in `AxiomProcessorService::routeToAi()`; on failure the event still persists via `AxiomThreatAnalysisService`'s deterministic fallback verdict (see the Telemetry Compliance section above) rather than crashing or silently dropping the event

---

### ✅ 🛠️ Agent tool calls don't pollute dashboard metrics
> As a platform engineer, I want MCP tool calls to run the compliance pipeline without incrementing dashboard counters, so that agent activity doesn't skew operational metrics.

*Delivered by:* `TransactionProcessorService::process(observe: false)` in MCP tool handlers

---

### ✅ 🛠️ Isolate the semantic cache and policy corpus into explicit namespaces
> As a platform engineer, I want every vector write and query to target an explicitly named namespace, so that nothing can silently land in Upstash's implicit default namespace and become invisible to a future tenant-prefixing scheme.

*Delivered by:* ADR-0026 — `VectorCacheService` has no bare/default-namespace methods; transaction semantic cache lives in `transactions`, policy RAG in `policies`. Sets the pattern for future namespaces (e.g. `telemetry`) and tenant-prefixed namespacing.

---

### ✅ 🛠️ Export a compliance report
> As a platform engineer, I want to export flagged compliance events as CSV or PDF for a given date range, so that I can send a compliance report to auditors without granting them dashboard access.

*Delivered by:* `GET /compliance/export` — `streamDownload()` + `chunk(500)` CSV export with `from`/`to`/`flagged` filters; "Export CSV" toggle and date-range picker on the Compliance page
*TODO:* PDF export not yet implemented

---

### ✅ 🛠️ Detect a stalled or overwhelmed worker before the stream backs up
> As a platform engineer, I want to see the consumer lag for the transaction stream on the dashboard, so that I can tell whether the worker is keeping up before messages pile up unbounded.

*Delivered by:* `sentinel:consumer_lag` (plain `SET`, 10s TTL, written by the worker each `readGroup` cycle per ADR-0023) surfaced via `DashboardController::metrics()`; `LagCard` colour-codes the count against `lag_warn`/`lag_pause` config thresholds and shows a dash when the key has expired (worker offline). The producer (`sentinel:stream`) also applies a graduated soft-limit sleep at lag > 50 and a spin-wait at lag > 200, so the simulator backs off before the queue backs up further.

---

### ✅ 🛠️ Detect AI response quality degradation before it accumulates silently
> As a platform engineer, I want a visible counter when AI responses score low quality, so that I can catch model or prompt degradation early instead of discovering it during an audit.

*Delivered by:* `sentinel_metrics_low_quality_count` — incremented by every compliance driver when `quality_score <= 1`; surfaced as an amber-coloured stat card on the dashboard

---

### ✅ 🛠️ See retrieval coverage per domain, not just pass/fail
> As a platform engineer, I want to know when a domain-filtered policy query comes back thin (fewer than 2 chunks) instead of just silently proceeding with weak grounding, so that I can tell whether the policy corpus has a coverage gap for a given domain before it shows up as a bad narrative.

*Delivered by:* `AbstractComplianceDriver` logs `mean_score` and an `under_indexed` flag per RAG query; `Log::warning` fires when a domain-filtered query returns fewer than 2 chunks

---

### ✅ 🛠️ Protect authentication and streaming endpoints from abuse
> As a platform engineer, I want login, signup, and streaming endpoints to be rate-limited per IP or per user, so that a scripted attack or a runaway client can't hammer the app or exhaust upstream API quota.

*Delivered by:* named `RateLimiter::for()` limiters — `login` (per-IP), `signup` (per-IP), `ai-stream` (per authenticated user, falling back to IP) — all thresholds config-backed via `RATE_LIMIT_*` env vars in `config/sentinel.php`

---

### ✅ 🛠️ Trace a request across service boundaries, not just within one process
> As a platform engineer, I want a Synapse-L4 emitted Axiom's trace context to continue as a child span inside the Sentinel worker, so that I can follow one event's full journey across two services in a single trace instead of stitching logs together by hand.

*Delivered by:* OpenTelemetry wide spans (`OtelServiceProvider`, `axiom.process`) decorated with `source_id`, `anomaly_score`, `domain`, `routed_to_ai`; `traceparent` extracted from the stream entry continues the upstream trace as a child span (ADR-0024)

---

### ✅ 🛠️ Visualize pipeline health without reading raw logs
> As a platform engineer, I want throughput, latency percentiles, and AI routing/confidence signals on a dashboard, so that I can assess service health at a glance instead of grepping structured logs.

*Delivered by:* companion Grafana stack (Tempo + Prometheus) — 9 TraceQL-metrics panels over `axiom.process` / `axiom.ai_analysis` span attributes; no separate Prometheus counters required

---

### 🔲 🛠️ Get paged before AI or retrieval degradation accumulates silently
> As a platform engineer, I want the existing quality-score and retrieval-coverage signals to trigger an active alert — not just populate a dashboard I have to remember to check — so that a slow model or a policy-corpus coverage gap gets caught before it shows up in an audit.

*TODO:* `quality_score` and `under_indexed` are logged and counted today (see the two ✅ stories above), but nothing watches them — e.g. `quality_score=0` for N consecutive events, or zero-chunk filtered retrieval persisting, currently requires a human to notice.

---

### 🔲 🛠️ Configure Gemini/OpenRouter HTTP timeouts without a code change
> As a platform engineer, I want every AI driver's HTTP timeout to be a config value, so that I can tune timeout behaviour for a slow provider without a deploy — consistent with the project rule that no numeric threshold is hardcoded.

*TODO:* `OllamaDriver`'s timeout is config-backed (`services.ollama.chat_timeout`), but `GeminiDriver`/`OpenRouterDriver`'s `callModel()` still hardcode `Http::timeout(15)`/`Http::timeout(30)` inline — they weren't touched when `AbstractComplianceDriver` was extracted (ADR-0027).

---

### 🔲 🛠️ Run tests automatically on every push
> As a platform engineer, I want the Pest suite and architecture tests to run in CI on every push, so that a regression is caught before merge instead of at the next manual test run.

*TODO:* No CI pipeline exists yet; tests are run locally only.

---

### 🔲 🛠️ Prepare the vector layer for a third event type
> As a platform engineer, I want telemetry events to get their own named Upstash Vector namespace (e.g. `telemetry`), so that the pattern established for `transactions` and `policies` (ADR-0026) extends cleanly to Synapse-L4 telemetry rather than being bolted on ad hoc later.

*TODO:* Only two namespaces (`transactions`, `policies`) exist today; no telemetry-specific namespace has been created.

---

## 🤖 AI Agent (MCP)

### ✅ Look up policy before analyzing
> As an AI agent, I want to retrieve relevant regulatory policy chunks before analyzing a transaction, so that my analysis is grounded in the rules that actually apply.

*Delivered by:* `search_policies` MCP tool → `VectorCacheService::searchNamespace('policies', ...)` → agent calls `analyze_transaction` next

---

### ✅ Analyze a transaction on demand
> As an AI agent, I want to run the full compliance pipeline against a transaction I'm investigating, so that I can include a grounded verdict in my response.

*Delivered by:* `analyze_transaction` MCP tool → `TransactionProcessorService::process($data, observe: false)`

---

### ✅ See recent transaction activity without querying Postgres directly
> As an AI agent, I want to pull the most recently processed transactions — including threat status, cache hit/miss/fallback source, and elapsed processing time — so that I can reason about current activity without a direct database connection.

*Delivered by:* `get_recent_transactions` MCP tool → reads `sentinel:recent_transactions` Redis feed (limit 1–50, default 20)

---

### 🔲 Authenticate before accessing the MCP endpoint
> As an AI agent operator, I want MCP tool access to require a token, so that the compliance pipeline isn't callable by unauthenticated agents in production.

*TODO:* `Mcp::oauthRoutes()` — OAuth on the MCP endpoint is listed as a pending TODO
