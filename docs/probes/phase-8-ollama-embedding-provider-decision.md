# Probes — Phase 8: Ollama Embedding Provider Decision (ADR-0025)

See: docs/journal.md#phase-8

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-8, decision, architecture]
---
Q: Why does the planned `EmbeddingDriver` interface mirror `ComplianceDriver`
instead of adding an if/else branch inside `EmbeddingService`?

A: `ComplianceDriver` already has a proven Service Manager pattern (ADR-0006)
— an interface, per-provider driver classes, and a `Manager` subclass
resolving the default from an env-backed config key. Reusing that shape for
embeddings (`EmbeddingDriver` / `EmbeddingManager` / `SENTINEL_EMBEDDING_DRIVER`)
keeps both AI-facing pipeline stages swappable via env var with no code
change, instead of growing a second, inconsistent provider-selection
mechanism.

Extra: sentinel-l7 · Phase 8 · Decision: EmbeddingDriver Interface Mirrors ComplianceDriver
See: docs/journal.md#phase-8

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-8, nomic, rag]
---
`nomic-embed-text` v1.5 expects text to be prefixed with
{{c1::search_document:}} when indexing content and {{c2::search_query:}}
when embedding a query — skipping this doesn't error, it just quietly
degrades retrieval quality because the model was trained expecting that
signal.

Extra: sentinel-l7 · Phase 8 · Decision: Task-Prefix Parameter Lives on the Interface
See: docs/journal.md#phase-8

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-8, decision, semantic-cache]
---
Q: Policy RAG has a clean `search_document`/`search_query` split between
ingest and query. Why doesn't the transaction-fingerprint embed call in
`TransactionProcessorService` get the same clean split — and what was
decided instead?

A: The fingerprint vector is used for both sides of the same comparison: it
searches the semantic cache, and on a miss it becomes the new cache entry
itself. There's no question-vs-passage asymmetry to hang a query/document
split on. Decided to use `TASK_DOCUMENT` uniformly on both sides — closer to
a dedup/clustering framing than asymmetric retrieval — because keeping both
sides of the comparison consistent with each other matters more than which
specific prefix is nominally "correct" for a single call.

Extra: sentinel-l7 · Phase 8 · Decision: Task-Prefix for the Transaction Fingerprint
See: docs/journal.md#phase-8

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-8, challenge, upstash-vector]
---
Q: Why can't swapping the embedding provider (Gemini → Ollama/nomic) be done
with just an env var flip?

A: Upstash Vector's index dimension is fixed at creation (1536, matching
`gemini-embedding-001`) and nomic-embed-text v1.5 outputs 768 dimensions.
A vector index's dimension can't change in place, so the swap requires
recreating the index at 768 dimensions and re-ingesting the policy KB
(ns:`policies`) immediately after — re-ingesting too late (or not at all)
leaves RAG retrieval silently returning zero chunks. The semantic cache
(ns:`default`) needs no manual action; it just starts cold.

Extra: sentinel-l7 · Phase 8 · Challenge: Fixed Vector Index Dimension
See: docs/journal.md#phase-8
