# Probes — Phase 12: Named Vector Namespaces, Retire Implicit Default (ADR-0026)

See: docs/journal.md#phase-12

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-12, decision, architecture]
---
Q: Why were `VectorCacheService::search()`/`upsert()`/`delete()` deleted
outright instead of just adding an optional namespace parameter to them?

A: Deleting them entirely was the point — leaving the bare, implicit-
default-namespace methods in place "just in case" would have preserved
the exact inconsistency ADR-0026 exists to remove. A namespace with no
name only stops being confusing once nothing in the codebase can address
it anymore; every caller now goes through `searchNamespace()`/
`upsertNamespace()`/`deleteNamespace()` with an explicit namespace string,
with no bare fallback left to accidentally reach for.

Extra: sentinel-l7 · Phase 12 · Decision: Delete the Bare Methods, Don't Just Add a Namespace Argument
See: docs/journal.md#phase-12

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-12, challenge, refactor]
---
Q: `search()` returned a single best-match array or `null`. `searchNamespace()`
returns a list of matches. How did `TransactionProcessorService` bridge
that gap, and what else moved as part of the same change?

A: It calls `searchNamespace($vector, self::NAMESPACE, $threshold, 1)` and
takes `$results[0] ?? null` to reconstruct the old single-match contract.
The similarity threshold itself moved from a property `VectorCacheService`
read once from config in its constructor to an explicit argument the
caller passes per call — `VectorCacheService` became a purely generic
namespaced Upstash client with no cache-specific defaults baked into it,
consistent with how `GeminiDriver`/`OpenRouterDriver`/`SearchPolicies`
already pass their own threshold explicitly.

Extra: sentinel-l7 · Phase 12 · Challenge: Return-Shape Mismatch Between the Old and New Methods
See: docs/journal.md#phase-12

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-12, challenge, testing]
---
Q: Renaming two methods on `VectorCacheService` ended up touching four
separate test files. Why did the blast radius extend beyond the file
that owns those methods' own tests, and how were the extra regressions
caught?

A: `TransactionProcessorServiceTest` and `WatchTransactionsTest` both mock
`VectorCacheService` directly with Mockery (~35 occurrences combined) and
needed `search`/`upsert` renamed to `searchNamespace`/`upsertNamespace`
with return values reshaped into lists. `AnalyzeTransactionToolTest` uses
real `Http::fake()` against the literal `*/query`/`*/upsert` URL patterns,
which stopped matching once real requests moved to `/query/transactions`/
`/upsert/transactions`. Both extra regressions were caught by running the
full suite and diffing against a `git stash` baseline rather than
assuming the directly-edited files were the only ones affected — the same
discipline established when the Upstash namespace endpoint bug was fixed
in Phase 10.

Extra: sentinel-l7 · Phase 12 · Challenge: The Mock/Fake Blast Radius Was Larger Than Expected
See: docs/journal.md#phase-12
