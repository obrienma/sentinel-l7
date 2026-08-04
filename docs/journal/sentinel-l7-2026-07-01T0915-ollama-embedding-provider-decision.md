---
id: sentinel-l7-2026-07-01T0915-ollama-embedding-provider-decision
repo: sentinel-l7
title: "Ollama Embedding Provider Decision (ADR-0025)"
date: 2026-07-01
phase: 8
tags: [embedding-driver, service-manager-pattern, task-prefix, adr]
files: [docs/adr/0025-ollama-local-embedding-provider.md]
---

# Ollama Embedding Provider Decision (ADR-0025)

Decision-only step, no code yet. With an Ollama server now available, and
Gemini's embedding quota continuing to be the first thing to fail under
burst load (per ADR-0005, ~57 transactions before exhaustion — confirmed
again by the Phase 7 benchmark seeder), wrote ADR-0025 to record the switch
to a local `nomic-embed-text` v1.5 (768-dim) embedding provider ahead of
implementation.

### Decision: `EmbeddingDriver` Interface Mirrors `ComplianceDriver`
Rather than growing `EmbeddingService` with an if/else on provider, the plan
is to give embeddings the same Service Manager treatment `ComplianceDriver`
already has (ADR-0006): an `EmbeddingDriver` contract, `GeminiEmbeddingDriver`
/ `OllamaEmbeddingDriver` implementations, and an `EmbeddingManager` resolving
from a new `SENTINEL_EMBEDDING_DRIVER` env var. Checked `ComplianceDriver` /
`ComplianceManager` / `GeminiDriver` structure before drafting this so the
new interface matches established shape rather than inventing a parallel
pattern.

### Decision: Task-Prefix Parameter Lives on the Interface, Not the Driver
Nomic v1.5 expects `search_document:` / `search_query:` prefixes on indexed
vs. query text — skipping this doesn't error, it just quietly degrades
retrieval quality, which is exactly the silent-partial-failure shape already
flagged as a standing concern in this project. Put a `$task` parameter with
a `TASK_DOCUMENT` default on `EmbeddingDriver::embed()` itself (not bolted
onto `OllamaEmbeddingDriver` alone) so call sites declare intent once and it
degrades to a no-op for whichever driver doesn't need it (Gemini).

### Challenges
Two things complicated what looked like a simple provider swap:

1. **Fixed vector index dimension.** Upstash Vector's index dimension
   (1536, matching `gemini-embedding-001`) can't be changed in place — a
   provider swap means recreating the index and re-ingesting the policy KB
   (ns:`policies`), sequenced carefully so `sentinel:ingest` re-runs
   immediately after recreation, not before (a gap there means RAG silently
   returns zero chunks).
2. **The transaction fingerprint has no clean query/document split.** Policy
   RAG has an obvious asymmetric split (ingest = document, search = query),
   but the semantic-cache fingerprint embed (`TransactionProcessorService`)
   is used both to search the cache and, on a miss, to become the new cache
   entry — there's no "question vs. passage" shape to it. Resolved by
   treating it as `TASK_DOCUMENT` on both sides (dedup/clustering framing —
   consistency between the two comparison sides matters more than which
   specific prefix is nominally correct), documented as the one genuine
   judgment call in ADR-0025 rather than an obvious default.

Implementation (the driver classes, config wiring, and the actual index
migration) is a follow-up step — not done in this entry.
