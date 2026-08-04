---
id: sentinel-l7-2026-07-02T0930-named-vector-namespaces
repo: sentinel-l7
title: "Named Vector Namespaces, Retire Implicit Default (ADR-0026)"
date: 2026-07-02
phase: 12
tags: [upstash-vector, namespaces, adr, regression-testing]
files: [docs/adr/0026-named-vector-namespaces-retire-default.md, app/Services/VectorCacheService.php, app/Services/TransactionProcessorService.php, tests/Unit/VectorCacheServiceTest.php, tests/Unit/TransactionProcessorServiceTest.php, tests/Feature/WatchTransactionsTest.php, tests/Unit/Mcp/AnalyzeTransactionToolTest.php, README.md, CLAUDE.md]
---

# Named Vector Namespaces, Retire Implicit Default (ADR-0026)

While confirming the Ollama cutover's data in Upstash, only the `default`
namespace was being checked, which is a symptom of a real design smell:
the transaction semantic cache has lived in Upstash's implicit,
unnamed default namespace since ADR-0008, while `policies` is explicitly
named. With multi-tenancy and telemetry-namespace support already on the
roadmap, an implicit exception to an otherwise-named-namespace convention
only gets more confusing as more namespaces are added. Wrote ADR-0026 and
retired the implicit-default code path entirely.

### Decision: Delete the Bare Methods, Don't Just Add a Namespace Argument
`VectorCacheService::search()`/`upsert()`/`delete()` (bare `/query`,
`/upsert`, `/delete` — Upstash's implicit default namespace) are gone, not
deprecated-in-place. Every caller now goes through `searchNamespace()`/
`upsertNamespace()`/`deleteNamespace()` with an explicit namespace string.
`TransactionProcessorService` gained `const NAMESPACE = 'transactions'`,
matching `SentinelIngest`'s existing `const NAMESPACE = 'policies'`
convention. Leaving the bare methods in place "just in case" would have
preserved exactly the inconsistency this ADR exists to remove — a
namespace with no name only stops being confusing once nothing can
address it anymore.

### Challenge: Return-Shape Mismatch Between the Old and New Methods
`search()` returned a single best-match array or `null`. `searchNamespace()`
returns a list of all matches at or above threshold. `TransactionProcessorService`
now calls `searchNamespace($vector, self::NAMESPACE, $threshold, 1)` and
takes `$results[0] ?? null` to reconstruct the old single-match contract —
the threshold itself moved from a constructor-injected property on
`VectorCacheService` (read once from config) to an explicit per-call
argument, since the service is now purely a generic namespaced Upstash
client with no cache-specific defaults baked in.

### Challenge: The Mock/Fake Blast Radius Was Larger Than Expected
Renaming two methods on a widely-mocked service touched four test files:
`VectorCacheServiceTest` (own coverage, rewritten around the new
namespaced methods and `transactions` namespace), `TransactionProcessorServiceTest`
and `WatchTransactionsTest` (Mockery mocks of `VectorCacheService`, ~35
occurrences across both — `shouldReceive('search')` → `shouldReceive('searchNamespace')`
plus wrapping single-match returns in a list, `null` → `[]`), and
`AnalyzeTransactionToolTest` (real `Http::fake()` against `*/query`/`*/upsert`,
which stopped matching once the real HTTP calls moved to `/query/transactions`/
`/upsert/transactions`). Caught the `WatchTransactionsTest` and
`AnalyzeTransactionToolTest` regressions by running the full suite and
diffing against a `git stash`-based baseline rather than assuming the
directly-touched test files were the only blast radius — the same
verification discipline established in Phase 10.

Verified: full suite back to the pre-existing 2-4 flaky/unrelated
failures (Phase 7 fingerprint entropy and merchant-config tests, plus an
order-dependent `ArchTest` gap) with zero failures attributable to this
change.
