# ADR-0026: Named Vector Namespaces — Retire the Implicit Default Namespace

**Date:** 2026-07-02
**Status:** Accepted

## Context

ADR-0008 established a dual-namespace strategy for Upstash Vector: the `default` (Upstash's implicit, unnamed) namespace holds the transaction semantic cache, and a named `policies` namespace holds the policy RAG corpus. This has worked functionally, but it surfaced a real point of confusion during the Ollama embedding cutover (ADR-0025): when checking the Upstash console for evidence that data was flowing, it was easy to look only at the unnamed/default namespace and miss that `policies` — a namespace that has to be deliberately selected in the console's namespace picker — held separate, correctly-indexed data the whole time.

Two things push this from "minor UX confusion" to worth fixing structurally:

1. **Multi-tenancy is an open TODO** (see `CLAUDE.md`: tenant-scoped middleware, tenant-prefixed stream keys). An unnamed default namespace has no name to prefix per tenant — every other namespace in a future multi-tenant design would need a tenant qualifier, but the default namespace structurally can't carry one without first giving it an explicit name.
2. **Telemetry data is a stated future namespace.** Once there are three or more purpose-specific namespaces (`transactions`, `policies`, `telemetry`, ...), leaving one of them implicit and unnamed is inconsistent and actively harder to reason about than naming all of them the same way.

Relying on "default" for one data type while everything else is explicitly named is a convention that only gets more confusing as more namespaces are added — not less.

## Decision

Introduce an explicit `transactions` namespace for what is currently the semantic cache in Upstash's implicit default namespace. Retire `VectorCacheService::search()`, `upsert()`, and `delete()` — the methods that target Upstash's unnamed default namespace via bare `/query`, `/upsert`, `/delete` — entirely. Every vector operation in the codebase goes through an explicitly-named-namespace method (`searchNamespace()`/`upsertNamespace()`, plus a new `deleteNamespace()`) from this point forward. No code path should ever address Upstash's implicit default namespace again.

`TransactionProcessorService` (the only caller of the old bare methods) is updated to call the namespaced equivalents with `'transactions'` as the namespace argument.

## Consequences

**Positive:**
- One consistent pattern for every namespace, present and future — no implicit/unnamed exception to remember or explain.
- Sets up a straightforward pattern for adding a `telemetry` namespace later, and for tenant-prefixed namespaces (e.g., `tenant123:transactions`) if multi-tenancy is built out, without needing to first retrofit an unnamed namespace into a nameable one.
- Removes an entire class of "did you check the right namespace" confusion going forward.

**Negative:**
- The handful of vectors currently sitting in Upstash's implicit default namespace become orphaned — no code will read or write there again. This is treated as acceptable: it is a cache, the entries are cheap to regenerate, and this mirrors the same accepted-cold-cache tradeoff already made during the Ollama embedding cutover (ADR-0025) rather than writing a one-off migration script for a handful of cache entries.
- `VectorCacheServiceTest` coverage for the old bare `search()`/`upsert()`/`delete()` methods needs to move to the namespaced equivalents (the same restructuring already done for `searchNamespace`/`upsertNamespace` when their endpoint-path bug was fixed).

## Alternatives considered

**Keep the bare default-namespace methods alongside the namespaced ones:** Rejected. This is exactly the inconsistency causing the confusion — leaving an unnamed exception in place doesn't fix the "which namespace has my data" problem, it just adds a second correct-but-inconsistent way to write vectors.

**Migrate existing default-namespace vectors into the new `transactions` namespace instead of letting the cache go cold:** Rejected as unnecessary engineering for a handful of semantic-cache entries — regenerating them is strictly cheaper than writing and verifying a one-off migration script, and the cache is designed to tolerate exactly this kind of cold start.
