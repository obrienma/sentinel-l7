# ADR 0018 — Domain-Scoped Policy RAG Retrieval

**Date:** 2026-05-01
**Status:** Accepted

---

## Context

`GeminiDriver` and `OpenRouterDriver` both retrieve policy context from the `policies` Upstash Vector namespace before generating an audit narrative. Before this change the query was global — the top-3 most similar chunks across the entire namespace were returned, regardless of which compliance domain applied to the event being analyzed.

With two policies in the corpus (`aml-bsa-compliance.md`, `gdpr-data-processing.md`) this is manageable by proximity. As the corpus grows — HIPAA, drug interaction, sanctions, PCI-DSS — the risk compounds: a high-scoring GDPR chunk can outrank lower-scoring AML chunks for an AML event. Gemini reasons faithfully over whatever it receives, so a mislabelled grounding document produces a confident but wrong audit narrative. There is no runtime signal that the wrong policy was used.

The root cause is that vector similarity answers "which chunks are most like this query?" but not "which chunks are relevant to this compliance domain?" Both signals are needed.

---

## Decision

**Tag at ingest time.** `sentinel:ingest` derives a `domain` string from the filename of each policy file — the first hyphen-delimited segment (`aml-bsa-compliance.md` → `aml`, `gdpr-data-processing.md` → `gdpr`). This tag is written into the Upstash Vector metadata for every chunk alongside the existing `text`, `source`, and `chunk` fields.

**Filter at retrieval time.** `VectorCacheService::searchNamespace()` gains an optional `?string $filter = null` parameter. When non-null the filter string is included in the Upstash Vector query payload (`"domain = 'aml'"`), scoping the similarity search to chunks that match the metadata predicate. The Upstash Vector REST API evaluates the filter server-side before computing similarity scores.

**Domain passed via data array.** Both compliance drivers read `$data['domain'] ?? null`. When present, they build the filter string and pass it to `searchNamespace`. When absent — including for all existing callers — `filter` is `null` and Upstash receives an unfiltered query: identical to the previous behaviour. This makes domain filtering opt-in without changing the `ComplianceDriver` interface.

**Retrieval quality logged.** Every `fetchPolicyContext()` call logs `domain`, `filter_used`, `chunk_count`, and `scores` at `info` level after a successful retrieval. A `chunk_count=0` with `filter_used=true` is an explicit signal that the filter matched no chunks — the foundation for silent partial failure alerting.

---

## Domain derivation convention

Policy filenames must follow the pattern `{domain}-{rest}.md` where `domain` is a lowercase ASCII string without hyphens. Examples:

| Filename | Derived domain |
|---|---|
| `aml-bsa-compliance.md` | `aml` |
| `gdpr-data-processing.md` | `gdpr` |
| `hipaa-privacy-rule.md` | `hipaa` |
| `drug-interaction-monitoring.md` | `drug` |

New policy files added to `policies/` must follow this convention. Running `sentinel:ingest` after adding a file automatically tags its chunks with the correct domain.

---

## Consequences

**Positive**

- Cross-domain grounding contamination is structurally impossible when a domain is stamped — Gemini can only see policy context for the domain it is reasoning about.
- The `chunk_count=0, filter_used=true` log pattern provides a clear hook for silent partial failure monitoring (e.g. alert when a filtered query returns nothing for more than N events in a row).
- Retrieval quality is now observable: similarity scores per retrieval are logged alongside chunk counts.
- Adding a new compliance domain requires no code change — drop a `{domain}-*.md` file in `policies/` and run `sentinel:ingest`.

**Negative / Trade-offs**

- Existing chunks in Upstash lack the `domain` metadata key. Re-running `sentinel:ingest` is required to retag them before domain filtering becomes effective. Until then, any `domain`-stamped query returns zero results from old chunks.
- Callers must stamp `$data['domain']` for the filter to activate. The Axiom pipeline (`AxiomProcessorService`) does not yet stamp domain — it falls through to the unfiltered path. A future change to WatchAxioms or the Synapse-L4 emitter should add `domain` to each Axiom payload.
- The filename convention is an implicit contract. A file named `bsa-compliance.md` would be tagged `bsa`, not `aml`. Policy file naming must be kept consistent.

---

## Alternatives considered

**Frontmatter-based domain tagging**
Embed a `domain:` YAML frontmatter header in each `.md` file and parse it at ingest time. More explicit and robust against naming drift, but requires changes to all existing policy files and adds a parsing step. Deferred — the filename convention is sufficient for the current corpus size.

**Caller-side (PHP) post-filter**
Return all chunks from Upstash and filter by `metadata.domain` in PHP after retrieval. Works but fetches unnecessary data, increases latency, and wastes topK budget on irrelevant chunks. Server-side filtering (Upstash's `filter` parameter) is strictly better.

**Hard-coded domain per driver**
Bind each driver instance to a specific domain at construction time (e.g. a `GeminiAmlDriver`). Rejected: the compliance domain comes from the data being processed, not from which driver is wired. The driver should be domain-agnostic.
