---
id: sentinel-l7-2026-07-12T0915-vertexai-driver-implementation
repo: sentinel-l7
title: "VertexAIDriver Implementation: Claude Sonnet 4.6 via Vertex AI (ADR-0030)"
date: 2026-07-12
phase: 23
tags: [vertexai, compliance-driver, oauth2, injected-seam, cost-control]
files: [composer.json, composer.lock, config/services.php, .env.example, .gitignore, app/Services/Compliance/VertexAIDriver.php, app/Services/Compliance/VertexAiTokenService.php, app/Services/ComplianceManager.php, app/Mcp/Tools/AnalyzeTransaction.php, tests/Unit/VertexAIDriverTest.php, tests/Unit/ComplianceManagerTest.php, README.md]
---

# VertexAIDriver Implementation: Claude Sonnet 4.6 via Vertex AI (ADR-0030)

Built the fourth `ComplianceDriver` accepted in the prior phase's ADR-0030
review: `VertexAIDriver`, selectable via `SENTINEL_AI_DRIVER=vertexai`,
extending `AbstractComplianceDriver` exactly as ADR-0027 set the precedent
for `OllamaDriver`. Calls Claude Sonnet 4.6 through Vertex AI's Anthropic
publisher path (`publishers/anthropic/models/claude-sonnet-4-6:rawPredict`)
— the Anthropic Messages API request/response shape, not Gemini's
`generateContent` shape, since this driver's whole point is a model
provider genuinely distinct from the existing Gemini/OpenRouter/Ollama
drivers, not just another Gemini call through different auth. Before
writing any code, confirmed against Anthropic's own documentation that
`claude-sonnet-4-6` is a real, current model ID and that Claude on Vertex
AI has no free tier — it bills per-token at direct-API rates, with the
`global` region avoiding the 10% regional/multi-region premium. Also
wired `'vertexai'` into `AnalyzeTransaction`'s MCP tool `DRIVERS`
allowlist per ADR-0030 point 5, without which the driver would be
registered but unreachable through arbiter-l8's per-request override
path.

### Pattern: Injected Seam for an SDK That Bypasses the App's HTTP Client
`google/auth`'s `ServiceAccountCredentials::fetchAuthToken()` performs its
own real HTTP call to Google's OAuth2 token endpoint through an internal
Guzzle handler — not Laravel's `Http` facade — so `Http::fake()` cannot
intercept it. Wrapped the mint step in a small `VertexAiTokenService`
(a single public method, `fetchAccessToken(): string`) and added it as a
third constructor-injected dependency on `VertexAIDriver`, alongside the
`EmbeddingService`/`VectorCacheService` seams `AbstractComplianceDriver`
already establishes. `VertexAIDriverTest` mocks `VertexAiTokenService`
the same way existing driver tests mock `EmbeddingService` — a boundary
the test doubles, not the SDK internals — keeping `VertexAIDriver`
covered by the same never-hit-a-real-external-API rule as the other
three drivers.

### Decision: Per-Request Token Mint, No Caching Layer
ADR-0030 left the choice open — "minted per-request (or cached with a
short TTL)". Implemented per-request minting with no cache: `VertexAiTokenService`
is a stateless wrapper called once per `callModel()` invocation. Simplest
correct behavior, and the same call sites that already catch `\Throwable`
around every `ComplianceDriver::analyze()`/`analyzeTransaction()` call
(confirmed in the prior phase's ADR review) absorb a failed mint
identically to a failed HTTP call. A short-TTL token cache is straightforward
to add later without touching `VertexAIDriver`'s constructor shape —
deferred rather than built speculatively.

### Decision: Explicit `thinking: disabled` + `effort: low` on Every Request
Sonnet 4.6 defaults to `effort: high` with adaptive thinking on.
`VertexAIDriver::callModel()` is a short JSON-classification/extraction
task (compliance narrative + risk level + policy refs, not open-ended
reasoning) — exactly the workload shape Anthropic's own guidance names
for `effort: low` + `thinking: disabled`. Left at the default, the same
call costs roughly 5–7x more (~$0.02–0.03/call vs. ~$0.004–0.005/call,
based on this driver's actual prompt template size — `prompts/compliance-audit-narrative.txt`,
~150 tokens plus retrieved policy context) for reasoning tokens that add
no quality benefit here. Built the cost control into the driver from the
start — `thinking` and `output_config` are unconditional fields on every
request — rather than leaving the expensive default active and hoping to
remember to tune it later.

### Challenges
The main challenge was the test-isolation problem described in the
Pattern section above: `google/auth` doesn't go through the app's HTTP
client, so the first-instinct approach (mock `EmbeddingService`/
`VectorCacheService`, `Http::fake()` the rest, exactly like
`GeminiDriverTest`) would have silently attempted a real network call to
Google's token endpoint the moment a test touched `callModel()`. Caught
before writing a single test, by tracing what `ServiceAccountCredentials::fetchAuthToken()`
actually does rather than assuming it behaved like the codebase's other
HTTP-based dependencies.

Verified: 10 `VertexAIDriverTest` cases (parsed narrative, bearer token
header assertion, `thinking`/`output_config`/`anthropic_version` request
body fields, non-2xx throw, token-mint-failure throw, markdown fence
stripping, unexpected response shape fallback, domain filtering,
transaction narrative + prompt template) plus a `vertexai`-resolves case
added to `ComplianceManagerTest`. Full suite: 389/389 passing, 0
regressions. Pint clean on all new/modified files.
