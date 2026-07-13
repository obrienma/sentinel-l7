# ADR-0030: VertexAIDriver as a Fourth Compliance-Analysis Driver

**Date:** 2026-07-12
**Status:** Accepted

## Context

`ComplianceManager` (ADR-0006) currently registers three `ComplianceDriver` implementations — `GeminiDriver`, `OpenRouterDriver`, `OllamaDriver` (default per ADR-0027) — all extending `AbstractComplianceDriver` (ADR-0027), which owns prompt building, policy RAG retrieval, response parsing, and quality-score logging. Each concrete driver implements only `callModel(string $prompt): string`.

Adding a fourth driver against Google Cloud's Vertex AI — calling Claude, not the Gemini model `GeminiDriver` already calls via its direct Developer API — is motivated by two things: it strengthens Arbiter-L8's cross-provider disagreement checking with a genuinely distinct auth/infra path *and* a genuinely distinct model provider behind it (not just another Gemini call through different auth), and it's real, defensible Google Cloud experience — IAM roles, service accounts, and quota/project scoping, not just hitting a REST endpoint with an API key.

`GeminiDriver` calls `generativelanguage.googleapis.com` with a flat `?key=` query param (see `config/services.php`, `services.gemini.api_key`). Vertex AI does not support that auth model for its standard path — this is the crux of the decision below.

## Decision

**1. Add `VertexAIDriver extends AbstractComplianceDriver`, registered in `ComplianceManager` as `createVertexaiDriver()`, selectable via `SENTINEL_AI_DRIVER=vertexai`.** No change to `ComplianceManager`'s existing three drivers or the `ollama` default (ADR-0027).

**2. Auth: service account + OAuth2, `roles/aiplatform.user`.**
- A service account JSON key, `roles/aiplatform.user` scoped to one project, and an OAuth2 access token minted per-request via the `google/auth` PHP library (`google/auth` on Packagist — not previously a dependency; `composer.json` had no `google/*` beyond `google-gemini-php/laravel`, which talks to the Developer API, not Vertex).
- Request: `POST https://{REGION}-aiplatform.googleapis.com/v1/projects/{PROJECT_ID}/locations/{REGION}/publishers/anthropic/models/{MODEL}:rawPredict`, `Authorization: Bearer {token}`.
- New config: `services.vertexai.project_id`, `services.vertexai.region` (default `global` — no pricing premium and best availability, per Google's own recommendation; regional/multi-region endpoints carry a 10% premium), `services.vertexai.credentials_path` (path to the service account JSON, gitignored — mirrors how `.env`-sourced secrets are already handled elsewhere in this repo).
- Chosen over Vertex AI Express Mode (a flat API key, no service account, no IAM role) specifically because Express Mode produces a materially weaker resume claim — "got an API key from a slightly different Google product," not "configured IAM" — and doesn't serve the stated motivation of a genuinely distinct auth/infra path from `GeminiDriver`. See Alternatives Considered.

**3. Model: Claude Sonnet 4.6, via Vertex AI's Anthropic publisher path (`publishers/anthropic/models/claude-sonnet-4-6`).** Not Gemini — this driver's whole point is to give Arbiter-L8 a genuinely different model provider (Anthropic) behind a genuinely different auth surface (GCP IAM), alongside the existing Gemini (direct API), OpenRouter, and Ollama drivers.
- **No free tier.** Claude on Vertex AI bills per-token at the same rates as the direct Anthropic API — confirmed against Anthropic's own Vertex AI documentation before implementation. The `global` region default avoids the regional/multi-region 10% premium; there is no further discount available.
- **Every request explicitly sets `thinking: {"type": "disabled"}` and `output_config: {"effort": "low"}`.** Sonnet 4.6 defaults to `effort: high` with adaptive thinking on. This driver's actual workload — a short JSON-classification/extraction task (compliance narrative + risk level + policy refs, not open-ended reasoning) — is exactly the case Anthropic's own guidance names for `effort: low` + `thinking: disabled`: leaving the default active would add substantial billed "thinking" tokens for no quality benefit (roughly a 5–7x cost difference, based on this driver's actual prompt template size — `prompts/compliance-audit-narrative.txt`, ~150 tokens plus retrieved policy context).

**4. Request/response shape: Anthropic's Messages API, not Gemini's `generateContent` shape.** Claude on Vertex AI is a different publisher with a different envelope: `anthropic_version` is a required request-body field (fixed value `vertex-2023-10-16`) rather than a header, `messages: [{role, content}]` replaces Gemini's `contents: [{role, parts}]`, and the model's reply text is at `content[0].text` in the response rather than `candidates[0].content.parts[0].text`. `model` is not passed in the request body at all — it's part of the URL path. `VertexAIDriver::callModel()` still fits the same `callModel(string $prompt): string` contract as the other three drivers; the divergence from `GeminiDriver` is in the request/response shape and the auth mechanism, not the contract with `AbstractComplianceDriver`.

**5. `app/Mcp/Tools/AnalyzeTransaction.php` must add `'vertexai'` to its `DRIVERS` allowlist (and description text).** This tool's per-request `driver` override — validated today via `const DRIVERS = ['gemini', 'openrouter', 'ollama']` behind `Rule::in()` — is the mechanism `README.md` documents as "built for arbiter-l8's online disagreement layer." `TransactionProcessorService::process()` itself has no such allowlist (`$this->complianceManager->driver($driverOverride)` is generic), but this tool does, and it is the actual interface Arbiter-L8 drives cross-provider comparisons through. Without this change, `VertexAIDriver` would be registered and reachable via `SENTINEL_AI_DRIVER=vertexai` but unreachable through the per-request override this ADR's stated motivation depends on.

## Consequences

**Positive:**
- Fourth driver gives Arbiter-L8's cross-provider disagreement check a genuinely separate infra path (GCP IAM vs. flat API keys) *and* a genuinely separate model provider (Anthropic vs. the existing Gemini/OpenRouter/Ollama drivers) — not just another model behind the same auth shape.
- Real, interview-defensible GCP IAM/service-account experience, extending an abstraction (`AbstractComplianceDriver`) already proven out across three prior drivers rather than new scope.
- Zero risk to the three existing drivers or the `ollama` default — purely additive, same pattern as ADR-0027's `OllamaDriver` addition.

**Negative:**
- Adds a new Composer dependency (`google/auth`) and a new class of secret to manage (service account JSON) that this repo hasn't needed before — `GeminiDriver`'s, `OpenRouterDriver`'s, and `OllamaDriver`'s credentials are all flat strings from `.env`.
- Vertex AI is a *new* GCP product surface for this repo — unlike ADR-0027's Ollama addition, there's no existing GCP infra here to extend, so this is genuinely new integration work, not just a new driver class.
- No free tier: this is a real, ongoing per-token cost, not a demoable free addition to the stack. Mitigated but not eliminated by the explicit `effort: low` / `thinking: disabled` request shape in decision #3.

## Alternatives Considered

**Vertex AI Express Mode (flat API key, no service account, no IAM role).** Rejected. Materially weaker resume claim than the IAM/service-account path — an interviewer asking "walk me through the service account setup" gets no real answer under this path — and closer in shape to `GeminiDriver`'s existing auth, which undercuts the "genuinely distinct auth/infra path" half of this ADR's own motivation. It's also faster to ship with no new Composer dependency, and carries real product-level restrictions (lower quotas, historically incomplete model/region availability) that would need verification against current docs — a legitimate choice if the near-term goal were "demoable fourth driver fastest," but not what was chosen here.

**Gemini via Vertex AI instead of Claude.** Rejected. Would have kept the distinct-auth-surface benefit but not the distinct-model-provider benefit — Arbiter-L8 would still only be comparing Gemini responses across two auth paths, not comparing genuinely different model providers. Claude via Vertex gives both axes at once.

**Skip the abstraction, copy `GeminiDriver` wholesale with URL/auth changes.** Rejected per the precedent set explicitly in ADR-0027 — `AbstractComplianceDriver` exists specifically so a fourth driver is a `callModel()` override, not a ~245-line copy. Also wouldn't have worked as-is regardless: Claude's Messages API shape on Vertex is different enough from Gemini's `generateContent` shape that "copy `GeminiDriver` and change the URL" was never actually viable.

## Notes from Review

- An earlier draft of this ADR listed OAuth2 token-refresh failure as a new, unhandled failure mode under Consequences. That's not accurate: both call sites that invoke a `ComplianceDriver` — `TransactionProcessorService.php:162` and `AxiomProcessorService.php:124` — already catch `\Throwable` generically around `analyze()`/`analyzeTransaction()` and fall back to Tier 3 (or, for the Axiom path, log and persist without AI enrichment). A token-mint `RuntimeException` thrown from `VertexAIDriver::callModel()` is caught identically to `GeminiDriver`'s existing `RuntimeException` today — no new handling is required before this is production-safe.
