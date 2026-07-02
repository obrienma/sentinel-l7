# Probes — Phase 10: Ollama Embedding Cutover + Upstash Namespace Endpoint Fix

See: docs/journal.md#phase-10

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-10, anti-pattern, verification]
---
Q: `sentinel:ingest` printed "Done. 4 chunks indexed, 0 failed." on a run
that had actually written zero real vectors. What anti-pattern did trusting
that output almost fall into, and what caught it?

A: Trusting a command's own reported success as verification, instead of
checking the state it claims to have changed. Running a real vector search
immediately afterward (`results: 0`) is what surfaced that the ingest had
silently failed — the command's exit status and printed summary were both
wrong, because the underlying `upsertNamespace()` call was failing without
throwing, and nothing downstream checked its return value.

Extra: sentinel-l7 · Phase 10 · Anti-Pattern Avoided: Trusting a Command's Own Success Output
See: docs/journal.md#phase-10

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-10, challenge, upstash]
---
Q: `VectorCacheService::searchNamespace()` and `upsertNamespace()` posted to
`{baseUrl}/namespaces/{ns}/query` and `/namespaces/{ns}/upsert`. What was
wrong with this, and what's the correct Upstash Vector REST shape?

A: That path never existed on Upstash's API — every namespace-scoped call
had been 404ing since the code was written. The correct shape (confirmed by
direct `curl` against the real Upstash REST endpoint) is `{baseUrl}/query/{ns}`
and `{baseUrl}/upsert/{ns}` — namespace as a trailing path segment, not
nested under a `/namespaces/` prefix. This meant ADR-0008's dual-namespace
strategy (policy RAG, ns:`policies`) had likely never actually worked
against real infrastructure in any environment that exercised it.

Extra: sentinel-l7 · Phase 10 · Challenge: VectorCacheService Namespace Endpoints Used the Wrong URL Shape
See: docs/journal.md#phase-10

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-10, anti-pattern, testing]
---
Q: `VectorCacheServiceTest` had coverage for `searchNamespace` that used
`Http::fake(['*/namespaces/policies/query' => ...])` and passed. Why didn't
this test coverage catch that the endpoint path itself was wrong, and how
was the test suite changed to close that gap?

A: The fake pattern matched whatever URL the code happened to send —
asserting the bug as if it were the contract, since `Http::fake` only
verifies that *some* request matching the given pattern was made, not that
the pattern is the *correct* one. Fixed by adding tests that assert the
literal resulting URL (`{baseUrl}/query/{namespace}`,
`{baseUrl}/upsert/{namespace}`) rather than only asserting payload shape —
a test that fakes the same wrong path the code uses can never catch that
the path itself is wrong.

Extra: sentinel-l7 · Phase 10 · Anti-Pattern Avoided: Mocking Away the Thing That Was Actually Broken
See: docs/journal.md#phase-10

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-10, decision, testing]
---
Q: Setting `.env`'s `SENTINEL_EMBEDDING_DRIVER=ollama` for the first time
broke `SearchPoliciesToolTest` with a real `ConnectionException`. Why did
this happen, and why does `SENTINEL_AI_DRIVER` have the same latent risk
without ever having broken anything?

A: `SearchPoliciesToolTest` resolves `EmbeddingService` through the real
container instead of mocking it, and its `Http::fake(['*embedContent*' =>
...])` only matches Gemini's URL shape — once the container resolved
`OllamaEmbeddingDriver` instead, the fake didn't match and the test made a
real network call. `SENTINEL_AI_DRIVER` has the identical coupling (no pin
in `phpunit.xml`) but never surfaced because `.env`'s default happened to
already match what the compliance-driver tests assumed. Fixed by adding
`<env name="SENTINEL_EMBEDDING_DRIVER" value="gemini"/>` to `phpunit.xml`,
matching how `APP_ENV`, `CACHE_STORE`, etc. are already pinned there instead
of left to inherit from a developer's local `.env`.

Extra: sentinel-l7 · Phase 10 · Decision: Pin SENTINEL_EMBEDDING_DRIVER in phpunit.xml
See: docs/journal.md#phase-10
