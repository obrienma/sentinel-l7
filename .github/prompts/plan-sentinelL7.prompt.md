# Sentinel-L7 — Launch Sprint (Feb 27 → May 1, 2026)

**TL;DR:** The semantic caching pipeline works end-to-end (`sentinel:stream` → `sentinel:watch`), but the project has six major gaps before launch: fragile service layer (no retry/timeout/logging), placeholder AI analysis, empty consumer/reclaimer commands, no auth, no live dashboard, and no real-time push. This plan sequences 9 weeks of work across 4 phases: harden → build core → connect frontend → ship.

---

## Phase 1 — Harden Services (Week 1–2)

Make `EmbeddingService` and `VectorCacheService` production-grade.

**Step 1.** Add retry/backoff to `EmbeddingService::embed()` in `app/Services/EmbeddingService.php`
   - Wrap the Gemini HTTP call with Laravel's `Http::retry(3, 200, throw: false)` for transient failures (429, 503)
   - Add an explicit `->timeout(10)` on the HTTP client
   - Log failures via `Log::warning()` before throwing, so issues are visible in `laravel/pail`
   - Extract the hardcoded API URL into `config/services.php` under `gemini.embedding_url` for future model migration

**Step 2.** Add retry/backoff + logging to `VectorCacheService` in `app/Services/VectorCacheService.php`
   - Add `Http::retry(2, 150)->timeout(5)` to both `search()` and `upsert()`
   - Replace silent `return null` / `return false` failure paths with `Log::warning('Vector search failed', ['status' => $response->status()])` before returning
   - Remove the unused `use Illuminate\Support\Facades\Cache` import

**Step 3.** Fix config mismatch in `config/services.php` — change `'dimension' => 768` comment to `1536` to match the actual `output_dimensionality` in `EmbeddingService`

**Step 4.** Add a `delete(string $id): bool` method to `VectorCacheService` for future cache eviction needs (e.g., policy updates, stale vector cleanup)

**Step 5.** Update tests in `tests/Unit/EmbeddingServiceTest.php` and `tests/Unit/VectorCacheServiceTest.php`
   - Add test cases for retry behavior (first call fails, second succeeds)
   - Add test cases asserting `Log::warning()` is called on failure paths
   - Add test for the new `delete()` method

**Step 6.** Decide on `upstash/vector-laravel` — it's in `composer.json` but unused. Either refactor `VectorCacheService` to use the SDK (cleaner), or remove it from `composer.json` to eliminate the dead dependency. Recommend: **keep raw HTTP** for now since the implementation works and the SDK adds an abstraction layer you don't control; remove the package from `composer.json`.

---

## Phase 2 — Build Core Architecture (Week 3–5)

Implement the documented-but-missing pieces: AI analysis, consumer groups, and policy RAG.

**Step 7.** Implement `ComplianceManager` + `GeminiDriver` — the Strategy/Manager pattern diagrammed in the README
   - Create `app/Contracts/ComplianceDriver.php` — interface with `analyze(array $transaction, ?array $policyContext): ComplianceResult`
   - Create `app/Services/Compliance/GeminiDriver.php` — uses `google-gemini-php/laravel` to call Gemini 2.0 Flash with structured JSON output, policy-grounded prompt, and the transaction fingerprint
   - Create `app/Services/Compliance/OpenRouterDriver.php` — uses `taecontrol/openrouter-laravel-sdk` as a fallback driver
   - Create `app/Services/ComplianceManager.php` — Laravel Manager class that resolves the active driver from config, with `driver()` method
   - Refactor `ThreatAnalysisService` in `app/Services/ThreatAnalysisService.php` to become a thin wrapper around `ComplianceManager`, keeping the threshold check as a pre-filter before hitting the LLM (avoid API calls for obviously benign transactions)
   - Add config key `sentinel.compliance.driver` (default: `gemini`) in `config/sentinel.php`
   - Write Pest tests for both drivers (mocked HTTP) and the manager resolution

**Step 8.** Implement `sentinel:consume` in `app/Console/Commands/SentinelConsume.php`
   - Use `XREADGROUP GROUP sentinel-workers <consumer-name> COUNT 10 BLOCK 2000` instead of `XREAD BLOCK`
   - Create the consumer group on startup with `XGROUP CREATE transactions sentinel-workers $ MKSTREAM`
   - `XACK` after successful processing
   - Track pending entries — if processing fails, leave message un-ACKed for reclaimer
   - Add `--consumer` option for naming individual workers (for horizontal scaling)
   - Reuse the semantic cache pipeline from `WatchTransactions` but through the new `ComplianceManager`

**Step 9.** Implement `sentinel:recover` as a new command at `app/Console/Commands/SentinelRecover.php`
   - Poll `XPENDING transactions sentinel-workers - + 100` to find messages idle >30s
   - `XCLAIM` orphaned messages and reprocess them
   - Log recovered messages with `Log::info()`
   - Run on a 10-second loop

**Step 10.** Implement `sentinel:ingest` as a new command at `app/Console/Commands/SentinelIngest.php`
   - Accept a `--path` argument pointing to a directory of `.md` policy files
   - Chunk each file into ~500-token segments
   - Embed each chunk via `EmbeddingService::embed()`
   - Upsert into Upstash Vector with a `policies` namespace (separate from the default transaction cache namespace — this requires adding namespace support to `VectorCacheService` or creating a new `PolicyVectorService`)
   - Store source file + chunk index in metadata for attribution

**Step 11.** Uncomment `sentinel:consume` and `sentinel:recover` worker services in `render.yaml` and verify they work with the Dockerfile

---

## Phase 3 — Dashboard + Real-time (Week 6–7)

Connect the frontend to live data.

**Step 12.** Add a metrics API endpoint
   - Create `app/Http/Controllers/MetricsController.php`
   - Endpoint: `GET /api/metrics` — reads the Redis counters (`sentinel_metrics_cache_hit_count`, `sentinel_metrics_cache_miss_count`, `sentinel_metrics_fallback_count`, and their `_time` counterparts)
   - Compute derived values: hit rate %, avg latency, threats/hr (from a new counter)
   - Add route in `routes/web.php` (or create `routes/api.php`)
   - Gate behind `features.dashboard_access` flag

**Step 13.** Wire `Dashboard.vue` to live data in `resources/js/Pages/Dashboard.vue`
   - Replace placeholder "—" values with data fetched from `/api/metrics` on an interval (polling every 5s initially)
   - Add a simple threat feed list showing recent detections

**Step 14.** Add real-time broadcasting for the threat feed
   - Install Laravel Reverb (`composer require laravel/reverb`) — self-hosted WebSocket server, no external dependency, works on Render
   - Configure `BROADCAST_CONNECTION=reverb` in `.env`
   - Dispatch a `ThreatDetected` broadcast event from `sentinel:consume` when a threat is found
   - Listen in `Dashboard.vue` via Laravel Echo + Reverb client
   - The two `// todo: Trigger an Inertia Event or Broadcast` comments in `WatchTransactions.php` get resolved here

**Step 15.** Implement authentication
   - Install `laravel/socialite` for OAuth providers (GitHub as first provider — simplest, good for dev/demo audiences)
   - Add `GET /auth/redirect` and `GET /auth/callback` routes
   - Create `AuthController` with redirect/callback handlers
   - Add `auth` middleware to the `/dashboard` and `/api/metrics` routes
   - Update `Dashboard.vue` to replace the disabled "Sign in" button with a real login flow
   - Existing `users` migration already has the right schema

---

## Phase 4 — Polish + Ship (Week 8–9)

**Step 16.** Centralize feature flags — create `app/Services/FeatureManager.php` that wraps `config('features.*')` with a clean API (`FeatureManager::enabled('dashboard_access')`) and add new flags: `realtime_broadcast`, `oauth_enabled`, `compliance_ai` (to gradually roll out AI driver vs threshold fallback)

**Step 17.** Clean up dead dependencies in `composer.json`
   - Remove `upstash/vector-laravel` if not refactored to use it (Phase 1 decision)
   - Verify `laravel/horizon` is actually wired up (publish config, add Horizon route) or remove it
   - Verify `laravel/mcp` is being used or defer to post-launch

**Step 18.** Add integration tests
   - Currently all tests are mock-only. Add at least one integration test per service that hits a test Upstash index / Redis instance (can be gated behind `@group integration` so they don't run in CI by default)
   - Add a duplicate-UUID idempotency test as noted in ARCHITECTURE.md's constraints table

**Step 19.** Harden deployment
   - Address the `trustProxies(at: '*')` TODO in `bootstrap/app.php` — document it as intentional for Render's single LB, or scope more tightly
   - Ensure Reverb WebSocket worker is added to `render.yaml`
   - Set up health check endpoint (Laravel's `/up` route)

**Step 20.** Final QA pass
   - Run full Pest suite (`composer test`)
   - Smoke test: `sentinel:stream --limit=50` → `sentinel:consume` picks up messages → threats appear on dashboard in real-time
   - Verify XCLAIM recovery: kill a consumer mid-processing, confirm `sentinel:recover` reclaims and reprocesses
   - Verify OAuth login → dashboard access flow

---

## Verification Criteria

- **Phase 1:** `composer test` passes with new retry/logging test cases; `Log::warning` appears in pail output when services fail
- **Phase 2:** `sentinel:consume` processes messages from Redis Streams with consumer groups; `sentinel:recover` reclaims orphaned messages; `sentinel:ingest` populates the policies vector namespace
- **Phase 3:** Dashboard shows live hit rate, latency, and threat feed; OAuth login gates access
- **Phase 4:** Full smoke test end-to-end; `composer test` green; deploy to Render staging

## Key Decisions

- **Keep raw HTTP over `upstash/vector-laravel` SDK** — working code, fewer abstractions, remove dead dep from composer.json
- **Reverb over Pusher/Ably** — self-hosted, no external service cost, works within Render infrastructure
- **GitHub OAuth first** — lowest friction for dev/demo audiences; can add Google/SSO later
- **Polling before WebSockets** — Step 13 (polling) ships before Step 14 (Reverb) as a fallback, so the dashboard works even if broadcasting isn't ready yet
- **Consumer groups replace `sentinel:watch`** — `sentinel:consume` becomes the production command; `sentinel:watch` stays as a debug/dev tool using simple `XREAD`
