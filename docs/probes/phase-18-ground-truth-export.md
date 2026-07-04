# Probes — Phase 18: Ground-Truth Export Command (`sentinel:export-ground-truth`)

See: docs/journal.md#phase-18

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-18, pattern, reuse]
---
Q: Why doesn't `sentinel:export-ground-truth` add any new labeling logic to
determine whether a synthetic transaction is a threat?

A: `TransactionStreamService::generate()` already yields `is_threat` per
transaction, sourced from `config('sentinel.simulation.merchants')` before
any AI analysis runs — genuine, non-circular ground truth that already
existed for `TransactionSeeder`'s own benchmark stats. The export command
just re-shapes that same generator's output into `{input, expected_label}`
pairs; it doesn't introduce a second source of truth.

Extra: sentinel-l7 · Phase 18 · Pattern: Reuse the Existing Pre-AI Label Instead of Adding a New One
See: docs/journal.md#phase-18

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-18, decision, taxonomy]
---
Q: Ground truth only knows a binary `is_threat` flag, but Sentinel-L7's
real `risk_level` taxonomy has four values (`low`/`medium`/`high`/
`critical`). Why does the export command collapse `is_threat` to just
`'high'`/`'low'` instead of inventing a finer-grained ground-truth scheme?

A: There's no pre-AI signal that could justify picking `medium` vs
`critical` for a given threat — only whether it's a threat at all. Rather
than inventing a new vocabulary, the collapse reuses the exact rule
`TransactionProcessorService::gradeAiResult()` already applies internally
for its own rule-based fallback (`$isThreat ? 'high' : 'low'`), so the
fixture stays consistent with a convention Sentinel-L7 already follows.

Extra: sentinel-l7 · Phase 18 · Decision: Collapse the Binary `is_threat` to `'high'`/`'low'`, Not a New Three-Way Scheme
See: docs/journal.md#phase-18

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-18, side-effects]
---
`sentinel:export-ground-truth` has zero interaction with the live Redis
stream, idempotency keys, or consumer lag, because it only ever calls
`generate()` — never {{c1::publish()}} — on `TransactionStreamService`.

Extra: sentinel-l7 · Phase 18 · Decision: Zero Redis Side Effects
See: docs/journal.md#phase-18
