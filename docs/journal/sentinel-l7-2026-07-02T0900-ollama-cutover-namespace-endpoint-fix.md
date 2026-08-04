---
id: sentinel-l7-2026-07-02T0900-ollama-cutover-namespace-endpoint-fix
repo: sentinel-l7
title: "Ollama Embedding Cutover + Upstash Namespace Endpoint Fix"
date: 2026-07-02
phase: 10
tags: [upstash-vector, verification-discipline, test-isolation]
files: [.env.example, config/services.php, app/Services/VectorCacheService.php, app/Console/Commands/SentinelIngest.php, phpunit.xml, tests/Unit/VectorCacheServiceTest.php, tests/Unit/SentinelIngestTest.php, tests/Unit/Mcp/SearchPoliciesToolTest.php]
---

# Ollama Embedding Cutover + Upstash Namespace Endpoint Fix

User recreated the Upstash Vector index at 768 dimensions and pointed
`.env` at a real Ollama server, then asked to re-run `sentinel:ingest`.
Running it surfaced two pre-existing bugs that had nothing to do with the
Phase 9 wiring — the embedding driver swap just happened to be the first
thing to exercise this code path for real.

### Challenge: `.env` Had Two Malformed Values Blocking Ollama Entirely
`OLLAMA_URL = <ip>:11434` — space before `=` (harmless; phpdotenv trims it)
but no `http://` scheme, which breaks Guzzle's URL parsing outright. Also
`OLLAMA_EMBEDDING_MODEL` was commented out, defaulting to bare
`nomic-embed-text`, but the server only had `nomic-embed-text:v1.5` pulled —
Ollama has no implicit `:latest` alias unless the model was pulled without
a tag. Both confirmed by hand: `curl .../api/tags` listed the exact tag
required; a direct `/api/embeddings` call with the bare name 404'd
(`"model not found, try pulling it first"`) while the tagged name worked.

### Anti-Pattern Avoided: Trusting a Command's Own Success Output
`sentinel:ingest` printed "Done. 4 chunks indexed, 0 failed." on the first
run — and it was lying. Ran a real vector search afterward (`results: 0`)
before believing the ingest actually worked, which is what surfaced both
bugs below. A command's own reported exit status is not verification;
checking the state it claims to have changed is.

### Challenge: `VectorCacheService`'s Namespace Endpoints Used the Wrong URL Shape
`searchNamespace()`/`upsertNamespace()` posted to
`{baseUrl}/namespaces/{ns}/query` and `/namespaces/{ns}/upsert` — not a
real Upstash Vector REST endpoint. The correct shape (confirmed via direct
`curl` against the real Upstash instance) is `{baseUrl}/query/{ns}` and
`{baseUrl}/upsert/{ns}` — namespace as a trailing path segment, not nested
under `/namespaces/`. Every namespace-scoped call had been 404ing since
this code was written; the non-namespaced `search()`/`upsert()` methods
(ns:`default`, semantic cache) were unaffected because they never had a
namespace segment to get wrong. This means policy RAG (ns:`policies`,
ADR-0008's dual-namespace strategy) had likely never actually worked in
any environment that exercised it against real Upstash — masked because
`SentinelIngestTest` and the compliance-driver tests all mock
`VectorCacheService` wholesale rather than faking HTTP at the real URL
shape, so nothing ever asserted the literal path being hit.

### Anti-Pattern Avoided: Mocking Away the Thing That Was Actually Broken
The existing `VectorCacheServiceTest` coverage for `searchNamespace` faked
`Http::fake(['*/namespaces/policies/query' => ...])` — asserting the *bug*
as if it were the contract, since the fake pattern matched whatever the
code happened to send. Fixed both the implementation and the test fakes
together, and added new tests that assert the exact resulting URL
(`{baseUrl}/query/{namespace}`, `{baseUrl}/upsert/{namespace}`) rather than
just asserting payload shape — a test that fakes the same wrong path the
code uses can never catch that the path itself is wrong.

### Challenge: `SentinelIngest` Never Checked `upsertNamespace()`'s Return Value
`upsertNamespace()` catches its own HTTP failures and returns `false` — it
doesn't throw. `SentinelIngest::handle()` only counted a chunk as failed
inside a `catch` block, so a `false` return was silently treated as
success. Fixed by throwing when `upsertNamespace()` returns `false`, which
routes it through the existing catch/count/warn path. Root-caused before
the endpoint-path bug was found — the ingest command's own reporting could
not be trusted to reveal the underlying problem.

### Decision: Pin `SENTINEL_EMBEDDING_DRIVER` in `phpunit.xml`
Fixing the endpoint bug and re-running the real ingest set `.env`'s
`SENTINEL_EMBEDDING_DRIVER=ollama` for the first time — which then broke
`SearchPoliciesToolTest` with a real `ConnectionException`, because that
test resolves `EmbeddingService` through the container rather than mocking
it, and its `Http::fake(['*embedContent*' => ...])` only matches Gemini's
URL shape. `SENTINEL_AI_DRIVER` has the same latent coupling (no pin in
`phpunit.xml`) but happened to never break because `.env`'s default already
matched what tests assumed. Added `<env name="SENTINEL_EMBEDDING_DRIVER"
value="gemini"/>` to `phpunit.xml` so test behavior no longer depends on
whatever a developer's local `.env` happens to be pointed at — matching how
`APP_ENV`, `CACHE_STORE`, etc. are already pinned there rather than left to
inherit from `.env`.

Verified end-to-end after all fixes: a real query embed through Ollama
(`search_query:` prefix, 768-dim) against the recreated Upstash index
returned 3 relevant policy chunks (top score 0.83, correctly ranked the
AML policy highest for an AML query) — not just a clean test run, but the
actual retrieval path working against real infrastructure.
