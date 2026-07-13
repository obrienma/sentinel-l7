# Probes — ADR-0030 Review: VertexAIDriver as a Fourth Compliance Driver, 2026-07-12

See: docs/journal.md — "ADR-0030 Review — VertexAIDriver as a Fourth Compliance Driver — 2026-07-12"

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, compliance-driver, error-handling]
---
A draft ADR claimed OAuth2 token-refresh failure would be a new,
unhandled failure mode for `VertexAIDriver`. Checking the actual call
sites showed both `TransactionProcessorService.php` and
`AxiomProcessorService.php` already catch {{c1::`\Throwable`}}
generically around the compliance-driver call and fall back to
{{c2::Tier 3}} — so the claim was removed rather than accepted as-is.

Extra: sentinel-l7 · Anti-Pattern Avoided: Taking a Design Doc's Self-Reported Risk at Face Value
See: docs/journal.md — ADR-0030 Review 2026-07-12

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, mcp, arbiter-l8]
---
`app/Mcp/Tools/AnalyzeTransaction.php` gates its per-request driver
override behind a hardcoded {{c1::`const DRIVERS = ['gemini', 'openrouter', 'ollama']`}}
allowlist. Per README, this MCP tool — not the `SENTINEL_AI_DRIVER` env
default — is the actual mechanism {{c2::arbiter-l8}} uses for
cross-provider disagreement comparison, so a new driver must be added
to this constant to be reachable through it.

Extra: sentinel-l7 · ADR-0030 decision item 5
See: docs/journal.md — ADR-0030 Review 2026-07-12

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, adr-0030, decision]
---
Q: ADR-0030 chose service-account/OAuth2 auth (Option A) for
`VertexAIDriver` over Vertex AI Express Mode's flat API key
(Option B), even though Option B needs no new Composer dependency. Why?

A: Because Option B's auth shape — a flat API key, no service account,
no IAM role — is close enough to `GeminiDriver`'s existing `?key=`
query-param auth that choosing it would undercut the ADR's own stated
motivation: giving arbiter-l8's cross-provider disagreement check a
genuinely distinct auth/infra path, not just a fourth model behind the
same kind of flat-key auth. Option A's new dependency (`google/auth`)
and new secret class (service account JSON) were accepted as the point
of the addition, not a side effect to minimize.

Extra: sentinel-l7 · Decision: Auth Path — Service Account/OAuth2 over Express Mode
See: docs/journal.md — ADR-0030 Review 2026-07-12
