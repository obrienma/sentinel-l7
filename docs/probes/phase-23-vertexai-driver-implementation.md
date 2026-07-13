# Probes — Phase 23: VertexAIDriver Implementation: Claude Sonnet 4.6 via Vertex AI (ADR-0030), 2026-07-12

See: docs/journal.md — "Phase 23 — VertexAIDriver Implementation: Claude Sonnet 4.6 via Vertex AI (ADR-0030) — 2026-07-12"

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, compliance-driver, testing]
---
`google/auth`'s `ServiceAccountCredentials::fetchAuthToken()` performs
its own real HTTP call through an internal {{c1::Guzzle}} handler, not
Laravel's `Http` facade — so `{{c2::Http::fake()}}` cannot intercept it,
requiring a mockable wrapper class instead.

Extra: sentinel-l7 · Pattern: Injected Seam for an SDK That Bypasses the App's HTTP Client
See: docs/journal.md — Phase 23 2026-07-12

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, compliance-driver, dependency-injection]
---
`VertexAIDriver` takes a third constructor-injected dependency,
{{c1::`VertexAiTokenService`}}, alongside the `EmbeddingService`/
`VectorCacheService` seams `AbstractComplianceDriver` already
establishes — so tests can mock the token-minting boundary instead of
hitting Google's real OAuth2 endpoint.

Extra: sentinel-l7 · Pattern: Injected Seam for an SDK That Bypasses the App's HTTP Client
See: docs/journal.md — Phase 23 2026-07-12

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, compliance-driver, decision]
---
ADR-0030 left the OAuth2 token-minting strategy open — per-request, or
cached with a short TTL. `VertexAIDriver` implements
{{c1::per-request minting with no cache}}, deferring a token cache as
an addition that wouldn't require changing the driver's constructor
shape.

Extra: sentinel-l7 · Decision: Per-Request Token Mint, No Caching Layer
See: docs/journal.md — Phase 23 2026-07-12

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, mcp, arbiter-l8]
---
Adding `VertexAIDriver` to `ComplianceManager` alone was not enough to
make it reachable via arbiter-l8's cross-provider comparisons — ADR-0030
point 5 also required adding `'vertexai'` to
{{c1::`AnalyzeTransaction`'s MCP tool `DRIVERS` allowlist}}, the actual
interface that per-request override drives.

Extra: sentinel-l7 · Phase 23 implementation of ADR-0030 point 5
See: docs/journal.md — Phase 23 2026-07-12

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, vertexai, cost]
---
Claude on Vertex AI has {{c1::no free tier}} — it bills per-token at the
same rates as the direct Anthropic API, with a {{c2::10%}} premium on
regional/multi-region endpoints (avoided by using the `global` region).

Extra: sentinel-l7 · Verified against Anthropic's Vertex AI docs before implementation
See: docs/journal.md — Phase 23 2026-07-12

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, vertexai, cost]
---
Sonnet 4.6 defaults to {{c1::effort: high}} with {{c2::adaptive thinking}}
on. For `VertexAIDriver`'s short JSON-classification workload, this
inflates cost roughly 5–7x (about $0.02–0.03/call) versus explicitly
disabling thinking and setting effort to low (about $0.004–0.005/call).

Extra: sentinel-l7 · Decision: Explicit thinking:disabled + effort:low on Every Request
See: docs/journal.md — Phase 23 2026-07-12

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, vertexai, api-shape]
---
Claude on Vertex AI uses a different publisher path and endpoint verb
than Gemini on Vertex AI: {{c1::publishers/anthropic}} instead of
publishers/google, and {{c2::rawPredict}} instead of :generateContent —
plus `anthropic_version` moves from a header (direct API) to a required
body field on Vertex.

Extra: sentinel-l7 · Anthropic's Messages API shape, not Gemini's generateContent shape
See: docs/journal.md — Phase 23 2026-07-12
