---
id: sentinel-l7-2026-07-12T0900-adr0030-vertexai-driver-review
repo: sentinel-l7
title: "ADR-0030 Review — VertexAIDriver as a Fourth Compliance Driver"
date: 2026-07-12
phase: adr-review
tags: [adr, vertexai, compliance-driver, arbiter-l8]
files: [docs/adr/0030-vertexai-compliance-analysis-driver.md, README.md]
---

# ADR-0030 Review — VertexAIDriver as a Fourth Compliance Driver

A drafted ADR proposing `VertexAIDriver` — a fourth `ComplianceDriver`
alongside Ollama (default), Gemini, and OpenRouter, targeting Vertex
AI rather than Gemini's direct Developer API — was reviewed for
acceptance before any implementation code was written. The draft
carried an explicit open question (service-account/OAuth2 auth vs.
Vertex AI Express Mode's flat API key) and its own tentative risk
analysis; both were checked against the actual codebase rather than
accepted on the draft's own account.

### Anti-Pattern Avoided: Taking a Design Doc's Self-Reported Risk at Face Value
The draft listed OAuth2 token-refresh failure as a new failure mode
`AbstractComplianceDriver` doesn't anticipate. Reading the actual call
sites — `TransactionProcessorService.php:162` and
`AxiomProcessorService.php:124` — showed both already catch
`\Throwable` generically around `analyze()`/`analyzeTransaction()` and
fall back to Tier 3, so a `RuntimeException` from a failed token mint
is handled identically to `GeminiDriver`'s existing `RuntimeException`
today. The trap was re-reasoning about the design in the abstract and
accepting the doc's own stated risk; the fix was grepping the two catch
blocks before writing it into the accepted version. The claim was
removed rather than carried forward as an accepted risk.

The same read-before-accepting pass also surfaced a gap the draft
missed entirely: `app/Mcp/Tools/AnalyzeTransaction.php` hardcodes
`const DRIVERS = ['gemini', 'openrouter', 'ollama']` behind a
`Rule::in()` validator. Per `README.md`, this per-request driver
override — not `SENTINEL_AI_DRIVER` — is the mechanism arbiter-l8
actually drives cross-provider disagreement comparisons through;
`TransactionProcessorService::process()` itself has no allowlist, but
this MCP tool does. Without adding `'vertexai'` here, the new driver
would be registered and selectable by env var but unreachable through
the exact interface the ADR's own stated motivation depends on. Added
as an explicit decision item rather than left implicit.

### Decision: Auth Path — Service Account/OAuth2 (Option A) over Express Mode (Option B)
The draft presented both paths without finalizing one. Option A (a
service account, `roles/aiplatform.user`, OAuth2 access tokens minted
via the `google/auth` PHP library) was chosen over Option B (Vertex AI
Express Mode's flat API key, no new Composer dependency) specifically
because Option B's auth shape is close enough to `GeminiDriver`'s
existing `?key=` query param that it would have undercut the ADR's own
stated motivation — a genuinely distinct auth/infra path for
arbiter-l8's disagreement checking, not just a fourth model behind the
same flat-key auth. Option A costs a new dependency and a new class of
secret (service account JSON) this repo hasn't needed before; that cost
was accepted as the point of the addition, not a side effect of it.

### Challenges
None in the technical-debugging sense — this was a docs-only review
step, no code was written or run. The substantive work was the two
corrections above (an overstated risk claim, an understated scope), and
both surfaced from reading the call sites and the MCP tool directly
rather than from reasoning about the design on paper.
