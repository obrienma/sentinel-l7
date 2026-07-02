# Probes — Phase 7: Weighted Transaction Simulation + Benchmark Seeder

See: docs/journal.md#phase-7

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-7, simulation]
---
`TransactionStreamService::generate()` implements weighted sampling by
building an {{c1::index-repetition pool}} — each merchant profile's index is
repeated `weight` times in a flat array, then drawn with a single
{{c2::array_rand()}} call — giving proportional selection without a dedicated
weighted-random-choice algorithm.

Extra: sentinel-l7 · Phase 7 · Pattern: Weighted Random Selection via Index-Repetition Pool
See: docs/journal.md#phase-7

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-7, anti-pattern]
---
The anti-pattern avoided in the old merchant simulation was
{{c1::uniform-probability selection}} over a flat list — every merchant had
equal odds regardless of realistic transaction volume, flattening the
traffic distribution the {{c2::cache-hit-rate benchmark}} depends on.

Extra: sentinel-l7 · Phase 7 · Anti-Pattern Avoided: Uniform-Probability Merchant Selection
See: docs/journal.md#phase-7

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-7, decision, semantic-cache]
---
Q: Why does folding the new `message` field into `createTransactionFingerprint()`
put it in tension with the benchmark seeder introduced in the same phase?

A: Each merchant category draws from 4–5 message templates at random, so two
transactions identical in amount tier, category, merchant, and time bucket can
still land on different fingerprints depending on which template was picked.
That increases fingerprint entropy and can suppress cache hits — directly
counter to what the new `TransactionSeeder` benchmark is trying to measure
(cache-hit rate). The decision was made anyway without an accompanying ADR
update, and intersects the already-open ADR-0002 (fingerprint field impact)
and ADR-0015 (0.95 similarity threshold) questions.

Extra: sentinel-l7 · Phase 7 · Decision: Fold Free-Text message into the Semantic-Cache Fingerprint
See: docs/journal.md#phase-7
