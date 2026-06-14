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

*Delivered by:* `EmbeddingService` fingerprint → Upstash Vector similarity search (ns:`default`, threshold ≥ 0.95) → cache hit returns early

---

### ✅ 🕵️ Get a compliance verdict even when AI services are unavailable
> As a compliance operator, I want a rule-based fallback to produce a verdict if the embedding or vector search fails, so that no transaction is silently dropped during an outage.

*Delivered by:* `ThreatAnalysisService` tier-3 fallback (amount thresholds, no I/O); `XACK` always called

---

### ✅ 🕵️ View the live transaction feed on the dashboard
> As a compliance operator, I want to see recently processed transactions in real time, so that I can spot unusual activity as it happens.

*Delivered by:* Redis list feed written by `TransactionProcessorService`; polled by the dashboard every few seconds

---

### ✅ 🤖 Analyze a transaction on demand via AI agent
> As an AI agent, I want to run the full compliance pipeline against a transaction I'm investigating, so that I can include a grounded compliance verdict in my response.

*Delivered by:* `analyze_transaction` MCP tool → `TransactionProcessorService::process($data, observe: false)`

---

### 🚫 🕵️ Scope transaction data by tenant
> As a compliance operator at a multi-tenant platform, I want my team's transactions isolated from other tenants, so that I only see data that belongs to my organisation.

*Deferred:* See ADR-0020 — multi-tenancy and RBAC are being built in `rhizo-book` (TypeScript/WorkOS) instead, not planned for Sentinel-L7. The `routes/web.php` placeholder comment remains as an honest marker of known scope.

---

### 🔲 🕵️ Query historical transactions — not just the live feed
> As a compliance officer, I want to search and filter past transactions by date range, merchant, or risk verdict, so that I can investigate an incident after the fact.

*TODO:* Persist processed transactions to a `transactions` Postgres table (currently only written to a Redis live-feed list, which is ephemeral)

---

### 🚫 🕵️ Configure risk thresholds per tenant
> As a compliance operator, I want to set the anomaly score threshold that triggers AI analysis for my tenant, so that high-sensitivity environments can flag more events without changing the platform default.

*Deferred:* See ADR-0020 — per-tenant configuration depends on the tenant data model that was descoped from Sentinel-L7; not planned here. `config/sentinel.php` keeps a single platform-wide `sentinel.axiom_threshold`.

---

## 📡 Telemetry Compliance (Synapse-L4 Pipeline)

### ✅ 🕵️ Triage anomalous telemetry without drowning in raw logs
> As a compliance officer, I want high-anomaly telemetry events to be automatically surfaced with AI-generated audit narratives in regulatory language, so that my team reviews flagged events — not raw logs.

*Delivered by:* `sentinel:watch-axioms` threshold gate → `GeminiDriver` audit narrative → compliance dashboard

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

*Delivered by:* Policy RAG — `sentinel:ingest` chunking pipeline + `policies/` corpus + score-aware query formulation in `GeminiDriver`

---

### 🔲 🕵️ Correlate a compliance event back to the originating EventHorizon event
> As a compliance officer, I want to click a `source_id` in the compliance dashboard and see the raw EventHorizon event that triggered it, so that I have full traceability from audit finding to source event.

*TODO:* `source_id` is stored on `compliance_events` but no deep-link or cross-system lookup is implemented; requires an EventHorizon query API or embedded event snapshot

---

## 🏗️ Platform Operations

### ✅ 🛠️ Swap AI backends without code changes
> As a platform engineer, I want to change the AI provider via an environment variable, so that I can respond to quota exhaustion or cost changes without a deployment.

*Delivered by:* `ComplianceManager` (Laravel Service Manager); `SENTINEL_AI_DRIVER=gemini|openrouter`

---

### ✅ 🛠️ No event loss on worker crash
> As a platform engineer, I want failed or stalled messages to be automatically reclaimed and reprocessed, so that a worker crash doesn't silently drop events.

*Delivered by:* `sentinel:reclaim` XCLAIM recovery on the `transactions` stream
*Note:* XCLAIM recovery for `synapse:axioms` is a pending TODO

---

### ✅ 🛠️ AI failures don't crash the pipeline
> As a platform engineer, I want a transient AI API failure to log an error and persist the event with a null narrative — not crash the consumer.

*Delivered by:* `try/catch(\Throwable)` in `AxiomProcessorService::routeToAi()`; event persisted with `audit_narrative = null`

---

### ✅ 🛠️ Agent tool calls don't pollute dashboard metrics
> As a platform engineer, I want MCP tool calls to run the compliance pipeline without incrementing dashboard counters, so that agent activity doesn't skew operational metrics.

*Delivered by:* `TransactionProcessorService::process(observe: false)` in MCP tool handlers

---

### ✅ 🛠️ Export a compliance report
> As a platform engineer, I want to export flagged compliance events as CSV or PDF for a given date range, so that I can send a compliance report to auditors without granting them dashboard access.

*Delivered by:* `GET /compliance/export` — `streamDownload()` + `chunk(500)` CSV export with `from`/`to`/`flagged` filters; "Export CSV" toggle and date-range picker on the Compliance page
*TODO:* PDF export not yet implemented

---

### ✅ 🛠️ Detect a stalled or overwhelmed worker before the stream backs up
> As a platform engineer, I want to see the consumer lag for the transaction stream on the dashboard, so that I can tell whether the worker is keeping up before messages pile up unbounded.

*Delivered by:* `sentinel:consumer_lag` (plain `SET`, 10s TTL, written by the worker each `readGroup` cycle per ADR-0023) surfaced via `DashboardController::metrics()`; `LagCard` colour-codes the count against `lag_warn`/`lag_pause` config thresholds and shows a dash when the key has expired (worker offline)

---

### ✅ 🛠️ Detect AI response quality degradation before it accumulates silently
> As a platform engineer, I want a visible counter when AI responses score low quality, so that I can catch model or prompt degradation early instead of discovering it during an audit.

*Delivered by:* `sentinel_metrics_low_quality_count` — incremented by both compliance drivers when `quality_score <= 1`; surfaced as an amber-coloured stat card on the dashboard

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

### 🔲 Authenticate before accessing the MCP endpoint
> As an AI agent operator, I want MCP tool access to require a token, so that the compliance pipeline isn't callable by unauthenticated agents in production.

*TODO:* `Mcp::oauthRoutes()` — OAuth on the MCP endpoint is listed as a pending TODO
