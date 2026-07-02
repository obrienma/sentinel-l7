# Probes — Phase 9: EmbeddingDriver Interface + Ollama/Gemini Drivers (ADR-0025 wiring)

See: docs/journal.md#phase-9

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-9, pattern, architecture]
---
Q: `EmbeddingService` needed to support multiple embedding providers, like
`ComplianceDriver` already supports Gemini/OpenRouter. Why wasn't
`EmbeddingService` itself turned into an `EmbeddingDriver` implementation,
or replaced outright?

A: `EmbeddingService` does two unrelated things: build the provider-agnostic
transaction fingerprint string, and make the actual embedding call. Only the
second thing is provider-specific. So `EmbeddingService` kept its role as
the class every call site injects, but its constructor now takes an
`EmbeddingDriver` and `embed()` is a one-line delegation to it —
`createTransactionFingerprint()` stayed untouched. This meant every existing
call site (`TransactionProcessorService`, `SentinelIngest`, `SearchPolicies`,
`GeminiDriver`, `OpenRouterDriver`) needed zero changes to its own logic.

Extra: sentinel-l7 · Phase 9 · Pattern: Split a Concrete Service Into Business Logic + Delegated I/O
See: docs/journal.md#phase-9

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-9, decision, rag]
---
Q: Why do `GeminiDriver`, `OpenRouterDriver`, and the `SearchPolicies` MCP
tool all now pass `EmbeddingDriver::TASK_QUERY` explicitly, even though
`GeminiEmbeddingDriver` ignores that parameter entirely?

A: All three embed a query string against the already-indexed `policies`
namespace — the asymmetric-retrieval case nomic's task prefixes are meant
for. Passing `TASK_QUERY` explicitly today costs nothing under Gemini (which
has no prefix convention and ignores it), but means the correct prefix is
already wired at every RAG query call site before `SENTINEL_EMBEDDING_DRIVER`
is ever flipped to `ollama` — retrieval quality doesn't silently regress on
cutover because there's nothing left to retrofit.

Extra: sentinel-l7 · Phase 9 · Decision: Task-Prefix Constant Threaded Through Every RAG Query Call Site
See: docs/journal.md#phase-9

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-9, challenge, testing]
---
Q: Changing `EmbeddingService`'s constructor to require an `EmbeddingDriver`
broke every `new EmbeddingService()` call in `EmbeddingServiceTest.php` — 22
tests exercising the old inline Gemini HTTP call. How was this resolved
without patching each one with a dummy constructor argument?

A: Split the file along the same line the refactor split the class: the
`createTransactionFingerprint()` tests stayed in `EmbeddingServiceTest.php`,
now instantiating `EmbeddingService` with a `Mockery::mock(EmbeddingDriver::class)`
(fine, since those tests never call `embed()`), while every HTTP-behavior
test moved to a new `GeminiEmbeddingDriverTest.php` targeting
`GeminiEmbeddingDriver` directly — the class that now actually owns that
logic.

Extra: sentinel-l7 · Phase 9 · Challenge: Constructor Change Breaks Direct-Instantiation Tests
See: docs/journal.md#phase-9

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-9, challenge, verification]
---
Q: After the refactor, `composer test` still showed 3 failing tests
(`EmbeddingServiceTest` pipe-delimit count, `ArchTest` `TraceContextExtractor`,
plus `TransactionStreamServiceTest` merchant-config assertions). How was it
confirmed these weren't regressions introduced by the `EmbeddingDriver`
wiring?

A: `git stash` was used to run the full suite against unmodified `master`,
which showed the identical 3 failures with the same error messages — all
pre-date this phase (the fingerprint `message`-field entropy from Phase 7,
and the older ADR-0024 `TraceContextExtractor` arch-test gap). Same failure
count before and after confirmed the wiring change was regression-free.

Extra: sentinel-l7 · Phase 9 · Challenge: Confirming No Regressions via git stash Comparison
See: docs/journal.md#phase-9
