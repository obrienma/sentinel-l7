# Probes — Docs Audit: ADR Consistency Sweep (0001–0029), 2026-07-11

See: docs/journal.md — "Docs Audit — ADR Consistency Sweep (0001–0029) — 2026-07-11"

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, docs-audit, adr, pattern]
---
When a later ADR overturns an earlier one, the earlier record gets a dated
{{c1::status amendment ("Superseded by ADR-NNNN")}} rather than a rewrite of
its body — ADRs are {{c2::immutable}} decision records, and the body stays as
the honest account of what was believed at the time.

Extra: sentinel-l7 · Pattern: Supersession Annotation Over Rewrite
See: docs/journal.md — Docs Audit 2026-07-11

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, docs-audit, semantic-cache, threshold]
---
Sentinel-L7's semantic-cache similarity threshold default is
{{c1::0.90}} (ADR-0015, lowered from 0.95), tuned against
{{c2::Gemini `gemini-embedding-001`}} embeddings — so it must be re-validated
from scratch against nomic-embed-text's score distribution (ADR-0025).

Extra: sentinel-l7 · ADR-0015 / ADR-0025
See: docs/journal.md — Docs Audit 2026-07-11

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, docs-audit, fingerprint]
---
In the transaction fingerprint's time-of-day buckets, the `night` bucket
covers {{c1::21:00–05:59, wrapping midnight}} — it is produced by both the
`< 6` arm and the {{c2::`default`}} arm of the `match` in `EmbeddingService`,
which is why a spec listing only 00:00–05:59 silently omits three hours.

Extra: sentinel-l7 · ADR-0001 · Challenge: bucket-boundary gap
See: docs/journal.md — Docs Audit 2026-07-11

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, docs-audit, usage-endpoint, cursor]
---
In `GET /usage`, when a pipeline's array is empty, its `next_cursor` entry
{{c1::echoes the request's cursor value unchanged}} — `next_cursor` is never
null and never moves backwards, so Ledger-L5 can persist it blindly.

Extra: sentinel-l7 · ADR-0029 · implemented as `max('id') ?? $since`
See: docs/journal.md — Docs Audit 2026-07-11

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, docs-audit, decision]
---
Q: During the ADR consistency sweep, some errors were fixed in place while
other stale content only got dated amendment notes. What distinguishes the
two classes?

A: Whether the statement was ever true. Factual errors that were never true
(wrong stream key name, a bucket spec omitting 21:00–23:59, a claim about a
migration comment that didn't match the file) were corrected in place —
preserving a false statement has no archival value. Decisions that were true
when written but later overturned (the reclaimer daemon, the `default`
namespace, the stub OpenRouterDriver) kept their original bodies and got
dated status amendments pointing to the superseding ADR, preserving the
historical record while keeping readers from acting on dead architecture.

Extra: sentinel-l7 · Decision: Fix-in-Place vs. Annotate — Split by Error Class
See: docs/journal.md — Docs Audit 2026-07-11
