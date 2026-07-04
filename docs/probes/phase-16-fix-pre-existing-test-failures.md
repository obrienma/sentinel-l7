# Probes — Phase 16: Fix the 3 Pre-Existing Test Failures

See: docs/journal.md#phase-16

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-16, decision, testing]
---
Q: `TransactionStreamServiceTest` had two tests asserting a flat
`toBeIn(config('sentinel.simulation.merchants'))` check and a global
`1.00`–`500.00` amount range — both consistently failing. Why were the
tests changed instead of the generator?

A: `TransactionStreamService::generate()`'s weighted per-merchant-profile
behavior (`{name, category, weight, amount_min, amount_max, currencies,
is_threat}` objects, not a flat merchant-name list, with per-profile
amount ranges up to $4999 for `Pacific Forex Exchange`) is the documented,
intentional "Weighted transaction simulation" feature from Phase 7 —
confirmed against both the implementation and the shipped-features list
in README.md. The tests were asserting the *old*, pre-Phase-7 shape and
had simply never been updated; the implementation was correct.

Extra: sentinel-l7 · Phase 16 · Decision: Fix the Tests, Not the Implementation
See: docs/journal.md#phase-16

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-16, challenge, testing]
---
Q: `EmbeddingServiceTest`'s "pipe-delimits the fingerprint fields" test
asserted `substr_count($fingerprint, ' | ') === 4`. What was actually
wrong, and how was it found?

A: `EmbeddingService::createTransactionFingerprint()` builds 6 fields
(`Amount`, `Type`, `Category`, `Merchant`, `Time`, `Message`) joined by
`' | '`, which produces 5 delimiters, not 4. The `Message` field was
added at some point after this test was written and the delimiter-count
assertion was never bumped from 4 to 5. Found by reading the actual
`implode(' | ', [...])` array in `EmbeddingService.php` directly rather
than guessing from the test's expectation.

Extra: sentinel-l7 · Phase 16 · Challenge: A Delimiter Count Test Outlived a Field Addition
See: docs/journal.md#phase-16
