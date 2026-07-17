# ADR-0032: Policy Corpus for SaaS API Activity Domain

**Date:** 2026-07-16
**Status:** Proposed — decision shape drafted, corpus content and single-tag vs. OR-filter choice open

## Context

Xylem-L6's ADR-0004 named this as one of two prerequisites before its scored-event integration with Sentinel-L7 can be wired: "which SaaS-domain policy documents get indexed, and how the retrieval filter should treat more than one of them per event (single shared domain tag vs. an OR-filter across domains)." This repo's own README already claims SaaS API activity as a target domain, alongside financial events and system telemetry — but "exercised" and "has a real corpus" aren't the same claim. Both other pipelines have genuinely run (transactions for financial events, Axioms for telemetry), but the policy corpus itself — `policies/aml-bsa-compliance.md` and `policies/gdpr-data-processing.md` — is financial/compliance-flavored, not telemetry-specific. There is no domain-tagged corpus for system telemetry today either; an untagged Axiom just falls through to unfiltered retrieval over those same two files. A SaaS-domain corpus would be the first policy content actually authored for a non-financial domain, not an extension of an existing telemetry-specific one.

ADR-0018 established the existing mechanism this would extend: policy files are tagged with a single `domain` string derived from filename convention (`{domain}-{rest}.md`), stamped into Upstash Vector metadata at ingest, and filtered server-side at retrieval via `domain = 'x'`. That ADR also flagged, as of its own date (2026-05-01), that the Axiom pipeline didn't yet stamp `domain` anywhere and so fell through to the unfiltered path. That's no longer accurate as a description of the current gap: `AxiomProcessorService` already reads `$data['domain'] ?? null`, persists it onto `compliance_events.domain`, and passes it through to `ComplianceDriver::analyze()`, where `AbstractComplianceDriver::fetchPolicyContext()` already builds the retrieval filter from it when present. That half of ADR-0018's follow-up is done. What's still missing is one step further upstream: nothing populates `domain` on a real Axiom payload before it reaches the stream in the first place — every existing exercise of this path (`AxiomProcessorServiceTest`, `OpenRouterDriverTest`) sets `domain` by hand. CLAUDE.md's own TODO list names this precisely: *"WatchAxioms or Synapse-L4 emitter needs to stamp `domain` on each Axiom payload for domain-scoped RAG to activate."* This is exactly the point a Xylem-L6 integration would need to close.

## Decision — what's settled

**Extend the existing single-tag mechanism rather than introducing a parallel one.** ADR-0018's filename convention and server-side filter already generalize to a new domain without code changes ("Adding a new compliance domain requires no code change — drop a `{domain}-*.md` file in `policies/` and run `sentinel:ingest`"). A new `saas`-domain policy file (e.g. `saas-api-security.md`) fits this pattern directly.

**The Axiom *producer* side — `WatchAxioms` or the Synapse-L4 emitter — needs to stamp `domain` for SaaS-sourced events**, not `AxiomProcessorService`, which already consumes and forwards it correctly. This closes the actual remaining half of the gap ADR-0018 named as a known follow-up (and that CLAUDE.md's TODO list already tracks), not a new decision.

## Decision — what's open

**Whether SaaS API activity is better served by one shared `saas` tag or an OR-filter across more granular tags** (e.g. `owasp`, `nist`, `soc2`) if the eventual corpus draws from more than one framework and a given event could plausibly implicate more than one. `VectorCacheService::searchNamespace()` already accepts an arbitrary filter string and needs no change either way — it just forwards whatever it's given into the Upstash query payload. The actual single-value constraint lives one layer up, in `AbstractComplianceDriver::fetchPolicyContext()`, which today builds the filter as `"domain = '{$domain}'"` from a single `$data['domain']` value. An OR-filter (`domain = 'owasp' OR domain = 'nist'`) is a small, mechanical extension there — and to the `$data['domain']`-vs-`$data['domains']` shape feeding it — not a redesign, but only worth building if the corpus content actually calls for it.

**Which actual documents populate the corpus** is not decided here and isn't a call this ADR should make unilaterally — it's a content decision, not an architectural one. Recorded as open pending that input, consistent with ADR-0018's own filename-convention contract once chosen.

## Rationale

Reusing ADR-0018's mechanism rather than inventing a new one keeps one retrieval-filtering pattern in the codebase instead of two competing ones for the same underlying problem. Deferring the OR-filter question until the corpus content is known avoids building a filtering capability (multi-value OR) against a guessed-at need — consistent with "wait until it hurts."

## Alternatives Considered

| Option | Pro | Con |
|---|---|---|
| Single shared `saas` tag regardless of corpus breadth | Simplest; no filter changes needed | If the corpus later spans genuinely distinct frameworks (OWASP API Top 10 vs. SOC 2), collapses them into one retrieval scope, risking the same cross-domain contamination ADR-0018 was written to prevent |
| Build OR-filter support now, before corpus exists | Ready for either outcome | Speculative — building filter logic against content that doesn't exist yet |
| Frontmatter-based domain tagging (revisit ADR-0018's rejected alternative) | More robust than filename convention | ADR-0018 already declined this at current corpus size; nothing about the SaaS domain changes that calculus |

## Consequences

- Blocks on a content decision (which documents) before `sentinel:ingest` can run against a real SaaS corpus.
- `WatchAxioms`/the Synapse-L4 emitter gains a `domain` stamp for SaaS-sourced Axioms — a small, scoped change on the producer side, not the full Xylem-L6 wiring (which remains separately not-yet-authorized per Xylem-L6 ADR-0004). `AxiomProcessorService` itself needs no change — it already reads, persists, and forwards `domain` when present.
- The single-tag-vs-OR-filter question stays open until corpus content answers it; this ADR should be revisited (not silently resolved) once that content exists.
