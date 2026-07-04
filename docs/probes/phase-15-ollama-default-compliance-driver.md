# Probes — Phase 15: Ollama as Default Compliance-Analysis Driver (ADR-0027)

See: docs/journal.md#phase-15

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-15, decision, architecture]
---
Q: ADR-0025 established "no shared implementation between provider
drivers" as this codebase's convention, mirroring ADR-0006. Why did
`AbstractComplianceDriver` deliberately break that convention for
`GeminiDriver`/`OpenRouterDriver`/`OllamaDriver`, instead of extending
the same "no shared base class" pattern to the third driver too?

A: Degree, not philosophy. Diffing `GeminiDriver`/`OpenRouterDriver`
showed ~95% byte-identical code — only the outbound HTTP call and the
log-message class-name prefix differed. Adding a third driver the same
way would have been a third ~245-line copy of that same duplication,
making a bugfix-applied-to-only-one-driver risk concrete rather than
theoretical. The embedding-driver pair (`GeminiEmbeddingDriver`/
`OllamaEmbeddingDriver`) was deliberately left untouched — this
exception applies to the `ComplianceDriver` trio specifically, where
the measured duplication was high enough to justify a named departure.

Extra: sentinel-l7 · Phase 15 · Decision: Abstract the Three Drivers, Despite House Convention Being the Opposite
See: docs/journal.md#phase-15

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-15, challenge, refactor]
---
Q: The first version of `AbstractComplianceDriver` used `static::class`
to preserve each subclass's log-message prefix (e.g. `'OpenRouterDriver:
policy RAG retrieval'`) through the hoist. Running the untouched
`GeminiDriverTest`/`OpenRouterDriverTest` against it immediately failed.
Why, and what was the fix?

A: `static::class` resolves to the *fully-qualified* class name
(`App\Services\Compliance\OpenRouterDriver`), not the short class name
the existing tests assert on in their `Log::shouldReceive(...)->with(...)`
expectations. Fixed with `class_basename(static::class)`. This is exactly
what a pure refactor's "run the untouched tests first" acceptance gate is
for — the mistake was caught in seconds by tests nobody edited, instead
of needing to manually re-derive what every log line should say.

Extra: sentinel-l7 · Phase 15 · Challenge: static::class Broke the Hoist on First Try
See: docs/journal.md#phase-15

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-15, pattern, verification]
---
Q: Before writing `OllamaDriver::callModel()`, a live `curl` against the
real Ollama host was run first rather than implementing from API docs
alone. What did that surface, and how much did it matter?

A: Two things that would have been easy to get wrong silently: (1)
Ollama's `/api/chat` streams NDJSON by default unless `"stream": false`
is set explicitly — would have silently broken response parsing; (2) the
default model (`qwen3.5:9b-q4_K_M`) is a hybrid reasoning model — a
trivial echo-JSON test took 20.6s with its `thinking` phase left on vs.
0.96s with `"think": false`, a ~20x difference for identical
`message.content` output. Both were verified live before the
implementation was written, not discovered after the fact.

Extra: sentinel-l7 · Phase 15 · Pattern: Verify Live-Host Mechanics Before Writing the Implementation, Not After
See: docs/journal.md#phase-15

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-15, challenge, testing]
---
Q: `ComplianceManagerTest`'s "defaults to ollama when unset" case
couldn't just call `config('sentinel.ai_driver')` and assert `'ollama'`.
Why not, and what workaround was used?

A: This dev environment's real `.env` sets `SENTINEL_AI_DRIVER=openrouter`
explicitly, so by the time the app has booted, the config repository
already reflects that override rather than the code-level `'ollama'`
fallback — asserting against the booted config would test the live
override, not the default. Worked around it by clearing
`putenv`/`$_ENV`/`$_SERVER` for that one env var inside the test
(restored in a `finally` block) and re-`require`-ing
`config/sentinel.php` fresh, which re-evaluates its `env()` calls against
the now-cleared process state. Notably, this is the same fact the ADR
itself calls out — explicit env always wins over the code default — just
surfacing as a test-authoring problem instead of a deployment one.

Extra: sentinel-l7 · Phase 15 · Challenge: Testing a Config Default That the Live Environment Already Overrides
See: docs/journal.md#phase-15
