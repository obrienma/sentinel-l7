# ADR-0027: Ollama as the Default Compliance-Analysis Driver

**Date:** 2026-07-04
**Status:** Accepted

## Context

ADR-0006 established `ComplianceDriver`/`ComplianceManager` for the compliance-analysis (Tier 2) step, with `GeminiDriver` and `OpenRouterDriver` as the two registered implementations. ADR-0025 later adopted a local Ollama server for the *embedding* step only, scoped explicitly to avoid touching compliance analysis. Neither Tier 2 driver has ever used Ollama.

A Tailscale-reachable Ollama host (`100.82.223.70:11434`) is now available with a large model library, including several `qwen3.5:9b-q4_K_M`-based tags. The goal is to make Ollama the default compliance-analysis driver too — for the same cost/quota motivation as ADR-0025 — "rather than accumulating additional tech debt" by bolting on a third near-duplicate driver file.

Two things stood in the way of a simple third-driver addition:

1. **`GeminiDriver` and `OpenRouterDriver` were ~95% byte-identical.** Both were 244-line classes differing only in the outbound HTTP call (URL/auth/body shape/response path) and the class-name prefix baked into every log message. Copy-pasting a third near-duplicate would compound the exact tech debt this decision is meant to reduce.
2. **Ollama's chat API has mechanics neither existing driver's HTTP call needed to handle** — streaming-by-default responses and (for this specific model) a verbose reasoning/"thinking" phase — that had to be verified against the live host before committing to an implementation.

## Decision

**1. Extract `AbstractComplianceDriver`, then add `OllamaDriver` as a third concrete subclass.**

This is a deliberate departure from this codebase's established convention: ADR-0025 explicitly mirrored ADR-0006's "no shared implementation between provider drivers" pattern for the embedding-driver pair (`GeminiEmbeddingDriver`/`OllamaEmbeddingDriver` are still fully independent, untouched by this decision). The compliance-driver trio is different in degree — measured at ~95% file-identical, not just "some overlap" — and a third copy would have made the divergence risk (a bugfix applied to only one driver) concrete rather than theoretical. `AbstractComplianceDriver` (`app/Services/Compliance/`) now owns prompt building, policy RAG retrieval, output-quality scoring, and response parsing; each concrete driver implements only `callModel(string $prompt): string`. Per-driver log-message prefixes (`'GeminiDriver: ...'`, `'OllamaDriver: ...'`) are preserved via `class_basename(static::class)` rather than a hardcoded string, so the hoist changed zero observable log output.

**2. Model: `qwen3.5:9b-q4_K_M`, the `32qwen3.5:latest` (32k-context) tag.**

Context-budget check: the two runtime prompt templates are ~65-76 words of fixed text; the only variable part (`{policy_context}`) is capped at 3 chunks (hardcoded in `fetchPolicyContext()`) of ~500 target words each. Worst case ≈2,100-2,200 tokens — the 32k-context tag has ~10-15x headroom. **No 64k-context tag was created** — none exists on the host and none is needed.

**3. Ollama-specific API mechanics, verified live before implementation:**
- `POST /api/chat` (not `/api/generate`) — matches `OpenRouterDriver`'s message-array shape, response at `message.content`.
- `"stream": false` — mandatory. Ollama's `/api/chat` streams NDJSON by default; without this, response parsing silently breaks.
- `"format": "json"` — Ollama's analogue of Gemini's `responseMimeType`. Forces valid JSON *syntax*, not schema adherence — `parseResponse()`'s existing malformed-shape fallback (inherited, unchanged) is the real safety net here, not a formality.
- `"think": false` — qwen3.5 is a hybrid reasoning model. A live test against the real host with a trivial prompt took **20.6s** with thinking enabled (most of it an unused `message.thinking` chain-of-thought trace) versus **0.96s** with `think: false` — a ~20x difference for identical `content` output. A live test with a *realistic* compliance prompt (a $49,900 structuring-pattern transaction) with `think: false` completed in **3.9s** and produced schema-correct, semantically correct output (`risk_level: high`, correctly citing the structuring policy). A full end-to-end call through `TransactionProcessorService->analyzeTransaction()` (real embedding + real policy RAG + real Ollama call) completed in **~12s**.

**4. Config:** `services.ollama.chat_model` (default `32qwen3.5:latest`) and `services.ollama.chat_timeout` (default `60`, env `OLLAMA_CHAT_TIMEOUT`) — generous relative to the ~1-12s observed real-world latency above, to absorb host-load or network variance without misclassifying a slow-but-successful call as a Tier 3 fallback trigger.

**5. Default flip:** `config/sentinel.php`'s `ai_driver` default changes from `'gemini'` to `'ollama'` (`SENTINEL_AI_DRIVER` env var, if set, still wins — this is additive to existing behavior, not a removal of Gemini/OpenRouter).

## Consequences

**Positive:**
- Closes the compliance-analysis quota/cost dependency on hosted LLM APIs, mirroring ADR-0025's embedding-side motivation — the two AI call sites in the pipeline are now consistently swappable per-provider via env var, with Ollama as the no-cost default for both.
- `AbstractComplianceDriver` removes ~190 lines of duplicated logic per additional driver and eliminates the class of bug where a fix lands in one driver but not its sibling.
- The `think: false` finding is a reusable fact for any future integration with this or similarly-configured reasoning models on this host — a ~20x latency difference is not a minor tuning knob.

**Negative:**
- A locally-run quantized 9B model is inherently less reliable at strict JSON schema adherence than Gemini Flash's native structured-output mode. `"format": "json"` only forces valid syntax; a malformed-but-valid-JSON response (missing fields, wrong types) is still possible and would silently degrade to `parseResponse()`'s `risk_level: unknown` fallback rather than erroring loudly. This is an accepted, named risk, not a gap papered over by the existing fallback.
- Compliance analysis now has a runtime dependency on the same Ollama host's uptime that the embedding step already depends on (ADR-0025) — if the Tailscale host is down, compliance analysis fails over to Tier 3 (`ThreatAnalysisService`) by default where previously only an embedding failure could trigger that path.
- The 60s `chat_timeout` default is based on a small number of live observations (single-digit-second real latency for realistic prompts) rather than sustained load testing; it may need tuning once this runs under production-like concurrency.

**Explicit contrast with ADR-0025:** reverting `SENTINEL_AI_DRIVER` back to `gemini`/`openrouter` is a **pure env-var flip**. Unlike the embedding case — where reverting requires recreating the Upstash Vector index at a different dimension and re-ingesting the policy KB, because the index can only serve one embedding space at a time — `ComplianceDriver::analyze()`/`analyzeTransaction()` are stateless synchronous calls with no persisted index or format lock-in. This decision carries a materially lower reversal cost than its embedding-side precedent.

## Alternatives Considered

**Create a 64k-context model tag.** Rejected. The context-budget analysis above shows ~10-15x headroom on the 32k tag already; there is no realistic path to needing more context under the current prompt design (3 RAG chunks, ~500 words each).

**Copy-paste a third near-duplicate driver instead of abstracting.** Rejected per explicit direction — a third ~245-line copy would have compounded the exact duplication problem this decision exists to reduce, and diverged from house convention (no shared base class between provider drivers) without acknowledging or improving on it.

**Keep Gemini/OpenRouter as the default, offer Ollama as opt-in only.** Rejected — contradicts the stated goal of using Ollama by default rather than accumulating tech debt from an unused, merely-available integration.

**Use `/api/generate` instead of `/api/chat`.** Rejected. `/api/generate` is a single-string-completion endpoint; `/api/chat`'s message-array shape matches `OpenRouterDriver`'s existing request shape, keeping the three drivers' request construction conceptually parallel even though each hits a different provider.
