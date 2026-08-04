---
id: sentinel-l7-2026-07-04T0900-ollama-default-compliance-driver
repo: sentinel-l7
title: "Ollama as Default Compliance-Analysis Driver (ADR-0027)"
date: 2026-07-04
phase: 15
tags: [compliance-driver, abstract-base-class, late-static-binding, live-verification, adr]
files: [app/Services/Compliance/AbstractComplianceDriver.php, app/Services/Compliance/GeminiDriver.php, app/Services/Compliance/OpenRouterDriver.php, app/Services/Compliance/OllamaDriver.php, app/Services/ComplianceManager.php, config/sentinel.php, config/services.php, .env.example, tests/Unit/OllamaDriverTest.php, tests/Unit/ComplianceManagerTest.php, prompts/compliance-audit-narrative.md, prompts/transaction-compliance-analysis.md, docs/adr/0027-ollama-compliance-analysis-driver.md, README.md, CLAUDE.md, docs/SERVICES.md, docs/ARCHITECTURE.md, docs/AI_PIPELINE.md, docs/diagrams/SYSTEM_ARCHITECTURE.md, docs/DEV_GETTING_STARTED.md, docs/DEPLOYMENT.md, docs/USER_STORIES.md]
---

# Ollama as Default Compliance-Analysis Driver (ADR-0027)

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
