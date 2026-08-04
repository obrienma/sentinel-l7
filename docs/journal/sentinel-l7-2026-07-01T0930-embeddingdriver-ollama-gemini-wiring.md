---
id: sentinel-l7-2026-07-01T0930-embeddingdriver-ollama-gemini-wiring
repo: sentinel-l7
title: "EmbeddingDriver Interface + Ollama/Gemini Drivers (ADR-0025 wiring)"
date: 2026-07-01
phase: 9
tags: [embedding-driver, task-prefix, rag, regression-testing]
files: [app/Contracts/EmbeddingDriver.php, app/Services/Embedding/GeminiEmbeddingDriver.php, app/Services/Embedding/OllamaEmbeddingDriver.php, app/Services/EmbeddingManager.php, app/Services/EmbeddingService.php, app/Providers/AppServiceProvider.php, config/sentinel.php, config/services.php, .env.example, app/Mcp/Tools/SearchPolicies.php, app/Services/Compliance/GeminiDriver.php, app/Services/Compliance/OpenRouterDriver.php, tests/Unit/EmbeddingServiceTest.php, tests/Unit/GeminiEmbeddingDriverTest.php, tests/Unit/OllamaEmbeddingDriverTest.php]
---

# EmbeddingDriver Interface + Ollama/Gemini Drivers (ADR-0025 wiring)

Implemented the structure ADR-0025 designed on paper: an `EmbeddingDriver`
contract, `GeminiEmbeddingDriver` / `OllamaEmbeddingDriver` implementations,
and an `EmbeddingManager` (`Illuminate\Support\Manager`) resolving from
`SENTINEL_EMBEDDING_DRIVER` — the same shape as the existing
`ComplianceDriver`/`ComplianceManager` pair. `EmbeddingService` no longer
makes the Gemini HTTP call itself; it now takes an `EmbeddingDriver` in its
constructor and delegates `embed()` to it, keeping only
`createTransactionFingerprint()` (provider-agnostic) as its own logic.

### Pattern: Split a Concrete Service Into "Business Logic" + "Delegated I/O"
Rather than making `EmbeddingService` itself implement `EmbeddingDriver` (or
replacing it wholesale), it stayed the single class every call site already
injects, but its constructor now takes an `EmbeddingDriver` and `embed()`
becomes a one-line delegation. Fingerprint construction — which has nothing
to do with which embedding provider is active — stays put. This kept every
call site (`TransactionProcessorService`, `SentinelIngest`, `SearchPolicies`,
`GeminiDriver`, `OpenRouterDriver`) unchanged except for the two that needed
to pass `EmbeddingDriver::TASK_QUERY` explicitly.

### Decision: Task-Prefix Constant Threaded Through Every RAG Query Call Site
Both `GeminiDriver::fetchPolicyContext()` and `OpenRouterDriver`'s equivalent
policy-context lookup, plus the MCP `SearchPolicies` tool, embed a *query*
string against the already-indexed `policies` namespace — all three now pass
`EmbeddingDriver::TASK_QUERY` explicitly instead of relying on the
`TASK_DOCUMENT` default. `GeminiEmbeddingDriver` ignores the parameter
entirely (Gemini has no prefix convention), so this costs nothing today but
means flipping `SENTINEL_EMBEDDING_DRIVER` to `ollama` doesn't silently
regress RAG retrieval quality — the correct prefix is already wired at every
call site, not something to retrofit later.

### Challenges
Swapping `EmbeddingService`'s constructor signature broke `new
EmbeddingService()` everywhere it was called directly — 22 tests in
`EmbeddingServiceTest.php` that exercised the old inline Gemini HTTP call.
Rather than patch each call site with a dummy argument, split the file: the
`createTransactionFingerprint()` tests stayed in `EmbeddingServiceTest.php`
(now instantiating with a `Mockery::mock(EmbeddingDriver::class)`, since
fingerprint tests never touch `embed()`), and the HTTP-behavior tests moved
wholesale to a new `GeminiEmbeddingDriverTest.php` targeting the class that
now actually owns that logic. Confirmed via `git stash` that the one
fingerprint test still failing (`pipe-delimits the fingerprint fields`,
asserting 4 pipe-delimited sections when the Phase 7 `message` field pushed
it to 5) and the `ArchTest` `TraceContextExtractor` gap both pre-date this
phase — same 3 failures on `master` with or without this change, confirming
no regression was introduced by the refactor.

Tests that mock `EmbeddingService` directly (`GeminiDriverTest`,
`OpenRouterDriverTest`, `SentinelIngestTest`, `TransactionProcessorServiceTest`,
`WatchTransactionsTest`) needed no changes — none of them constrain `embed()`
call arguments with `->with(...)`, so adding the `$task` parameter to real
call sites didn't invalidate their mocks.

Upstash Vector index recreation, `sentinel:ingest` re-run at 768 dimensions,
and re-validating the similarity threshold against nomic's score
distribution are still open — this phase is code-complete but the actual
provider cutover (flipping `SENTINEL_EMBEDDING_DRIVER=ollama` against a real
index) has not happened yet.
