---
id: sentinel-l7-2026-07-04T0915-fix-pre-existing-test-failures
repo: sentinel-l7
title: "Fix the 3 Pre-Existing Test Failures"
date: 2026-07-04
phase: 16
tags: [test-maintenance, weighted-sampling, fingerprint]
files: [tests/Unit/EmbeddingServiceTest.php, tests/Unit/TransactionStreamServiceTest.php, CLAUDE.md]
---

# Fix the 3 Pre-Existing Test Failures

The 3 failures carried as "pre-existing, don't fix unless asked" since
at least Phase 7 were all cases of a test asserting an *old* shape of
something the implementation had since evolved past — not real bugs.

**`EmbeddingServiceTest` — "pipe-delimits the fingerprint fields"**
asserted `substr_count($fingerprint, ' | ') === 4` (5 fields). The
fingerprint has had 6 fields (`Amount`, `Type`, `Category`, `Merchant`,
`Time`, `Message`) since the `Message` field was added — the test was
never updated to expect 5 delimiters instead of 4.

**`TransactionStreamServiceTest` — two amount/merchant tests** were
written against a flat, pre-weighted-simulation merchant list. Since the
"Weighted transaction simulation" feature, `config('sentinel.simulation.merchants')`
holds per-merchant profile objects (`{name, category, weight, amount_min,
amount_max, currencies, is_threat}`), not a flat array of merchant-name
strings — `expect($transaction['merchant'])->toBeIn(config(...))` could
never pass, since a merchant name string is never literally an element
of an array of profile objects. Similarly `config('sentinel.simulation.currencies')`
doesn't exist at all (currencies are per-profile); and the flat
`1.00`–`500.00` amount range doesn't hold once merchant profiles like
`Pacific Forex Exchange` (`amount_min: 50000`, `amount_max: 499900`,
i.e. $500–$4999 after the `/100` cents conversion) are in the weighted
pool.

### Decision: Fix the Tests, Not the Implementation
The generator's behavior (weighted per-merchant profiles, per-merchant
currency/amount ranges) is the documented, intentional Phase 7 design —
confirmed against `TransactionStreamService::generate()` and the
shipped-features list in `README.md`. Rewrote both tests to check each
sampled transaction against *its own* merchant profile (`$profiles[$transaction['merchant']]`)
rather than a global range, run over 20 samples per test to exercise the
weighted pool. Verified stable (not just accidentally passing) by running
the two amount/merchant tests 5 times in a row before moving on.

Verified: full suite green 3 runs in a row (308/308, 0 failures) —
previously 3 failed consistently. Updated `CLAUDE.md`'s "known failures"
note, which had also gone stale (it still named `WatchTransactionsTest`'s
mock shape as failing; that one was actually already fixed as a side
effect of the ADR-0007 Tier 2 drift work earlier this session and the
note was never removed). `tests/ArchTest.php`'s `TraceContextExtractor`
gap remains — order-dependent, passes in the full suite, fails run in
isolation; left as noted, unrelated to this fix.
