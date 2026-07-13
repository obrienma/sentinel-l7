# ADR-0030: VertexAIDriver as a Fourth Compliance-Analysis Driver

**Date:** 2026-07-12
**Status:** Accepted

## Context

`ComplianceManager` (ADR-0006) currently registers three `ComplianceDriver` implementations — `GeminiDriver`, `OpenRouterDriver`, `OllamaDriver` (default per ADR-0027) — all extending `AbstractComplianceDriver` (ADR-0027), which owns prompt building, policy RAG retrieval, response parsing, and quality-score logging. Each concrete driver implements only `callModel(string $prompt): string`.

Adding a fourth driver against Google Cloud's Vertex AI — rather than Gemini's direct Developer API, which `GeminiDriver` already calls — is motivated by two things: it strengthens Arbiter-L8's cross-provider disagreement checking with a genuinely distinct auth/infra path (not just another model endpoint), and it's real, defensible Google Cloud experience — IAM roles, service accounts, and quota/project scoping, not just hitting a REST endpoint with an API key.

`GeminiDriver` calls `generativelanguage.googleapis.com` with a flat `?key=` query param (see `config/services.php`, `services.gemini.api_key`). Vertex AI does not support that auth model for its standard path — this is the crux of the decision below.

## Decision

**1. Add `VertexAIDriver extends AbstractComplianceDriver`, registered in `ComplianceManager` as `createVertexaiDriver()`, selectable via `SENTINEL_AI_DRIVER=vertexai`.** No change to `ComplianceManager`'s existing three drivers or the `ollama` default (ADR-0027).

**2. Auth: service account + OAuth2, `roles/aiplatform.user`.**
- A service account JSON key, `roles/aiplatform.user` scoped to one project, and an OAuth2 access token minted per-request (or cached with a short TTL) via the `google/auth` PHP library (`google/auth` on Packagist — not currently a dependency; `composer.json` has no `google/*` beyond `google-gemini-php/laravel`, which talks to the Developer API, not Vertex).
- Request: `POST https://{REGION}-aiplatform.googleapis.com/v1/projects/{PROJECT_ID}/locations/{REGION}/publishers/google/models/{MODEL}:generateContent`, `Authorization: Bearer {token}`.
- New config: `services.vertexai.project_id`, `services.vertexai.region`, `services.vertexai.credentials_path` (path to the service account JSON, gitignored — mirrors how `.env`-sourced secrets are already handled elsewhere in this repo).
- Chosen over Vertex AI Express Mode (a flat API key, no service account, no IAM role) specifically because Express Mode produces a materially weaker resume claim — "got an API key from a slightly different Google product," not "configured IAM" — and doesn't serve the stated motivation of a genuinely distinct auth/infra path from `GeminiDriver`. See Alternatives Considered.

**3. Model:** Gemini 2.0 Flash via Vertex's `publishers/google/models/gemini-2.0-flash` path (parity with `GeminiDriver`'s direct-API model choice, so cross-provider comparison in Arbiter-L8 isn't confounded by comparing different model generations).

**4. Response shape:** Vertex AI's `generateContent` response envelope matches the Developer API's (`candidates[0].content.parts[0].text`), so `VertexAIDriver::callModel()` should be near-identical to `GeminiDriver::callModel()` structurally — the divergence is entirely in the request's auth header and URL construction, not response parsing.

**5. `app/Mcp/Tools/AnalyzeTransaction.php` must add `'vertexai'` to its `DRIVERS` allowlist (and description text).** This tool's per-request `driver` override — validated today via `const DRIVERS = ['gemini', 'openrouter', 'ollama']` behind `Rule::in()` — is the mechanism `README.md` documents as "built for arbiter-l8's online disagreement layer." `TransactionProcessorService::process()` itself has no such allowlist (`$this->complianceManager->driver($driverOverride)` is generic), but this tool does, and it is the actual interface Arbiter-L8 drives cross-provider comparisons through. Without this change, `VertexAIDriver` would be registered and reachable via `SENTINEL_AI_DRIVER=vertexai` but unreachable through the per-request override this ADR's stated motivation depends on.

## Consequences

**Positive:**
- Fourth driver gives Arbiter-L8's cross-provider disagreement check a genuinely separate infra path (GCP IAM vs. flat API keys), not just another model behind the same auth shape.
- Real, interview-defensible GCP IAM/service-account experience, extending an abstraction (`AbstractComplianceDriver`) already proven out across three prior drivers rather than new scope.
- Zero risk to the three existing drivers or the `ollama` default — purely additive, same pattern as ADR-0027's `OllamaDriver` addition.

**Negative:**
- Adds a new Composer dependency (`google/auth`) and a new class of secret to manage (service account JSON) that this repo hasn't needed before — `GeminiDriver`'s, `OpenRouterDriver`'s, and `OllamaDriver`'s credentials are all flat strings from `.env`.
- Vertex AI is a *new* GCP product surface for this repo — unlike ADR-0027's Ollama addition, there's no existing GCP infra here to extend, so this is genuinely new integration work, not just a new driver class.
- Free-tier framing needs a gut check: Vertex AI's per-request Gemini calls are not covered by GCP's Always Free compute/storage quotas the way Cloud Run or Compute Engine are — this driver's actual cost profile should be confirmed against current Vertex AI pricing before treating it as a "free" addition to the stack.

## Alternatives Considered

**Vertex AI Express Mode (flat API key, no service account, no IAM role).** Rejected. Materially weaker resume claim than Option A's IAM/service-account path — an interviewer asking "walk me through the service account setup" gets no real answer under this path — and closer in shape to `GeminiDriver`'s existing auth, which undercuts the "genuinely distinct auth/infra path" half of this ADR's own motivation. It's also faster to ship with no new Composer dependency, and carries real product-level restrictions (lower quotas, historically incomplete model/region availability) that would need verification against current docs — a legitimate choice if the near-term goal were "demoable fourth driver fastest," but not what was chosen here.

**Route Gemini calls through Vertex AI exclusively, retiring the direct-API `GeminiDriver`.** Rejected — collapses the two genuinely different auth surfaces (flat API key vs. IAM) that make this addition useful for Arbiter-L8's disagreement checking in the first place, and drops a working driver for no functional gain.

**Skip the abstraction, copy `GeminiDriver` wholesale with URL/auth changes.** Rejected per the precedent set explicitly in ADR-0027 — `AbstractComplianceDriver` exists specifically so a fourth driver is a `callModel()` override, not a ~245-line copy.

## Notes from Review

- An earlier draft of this ADR listed OAuth2 token-refresh failure as a new, unhandled failure mode under Consequences. That's not accurate: both call sites that invoke a `ComplianceDriver` — `TransactionProcessorService.php:162` and `AxiomProcessorService.php:124` — already catch `\Throwable` generically around `analyze()`/`analyzeTransaction()` and fall back to Tier 3 (or, for the Axiom path, log and persist without AI enrichment). A token-mint `RuntimeException` thrown from `VertexAIDriver::callModel()` is caught identically to `GeminiDriver`'s existing `RuntimeException` today — no new handling is required before this is production-safe.
