---
id: sentinel-l7-2026-07-01T0900-weighted-simulation-benchmark-seeder
repo: sentinel-l7
title: "Weighted Transaction Simulation + Benchmark Seeder"
date: 2026-07-01
phase: 7
tags: [weighted-sampling, simulation, semantic-cache, fingerprint]
files: [database/seeders/TransactionSeeder.php, config/sentinel.php, app/Services/TransactionStreamService.php, app/Services/EmbeddingService.php]
---

# Weighted Transaction Simulation + Benchmark Seeder

Replaced the flat, uniform-probability merchant list driving `sentinel:stream`
with a set of weighted merchant profiles, and added `TransactionSeeder` — a
seeder that runs 500 transactions through the real pipeline
(`TransactionStreamService::generate()` → `TransactionProcessorService::process()`)
and prints a benchmark table of cache-hit rate, fallbacks, and threat rate.

### Pattern: Weighted Random Selection via Index-Repetition Pool
`config('sentinel.simulation.merchants')` is now a list of profiles, each
carrying a `weight`. `TransactionStreamService::generate()` builds a pool
array once per generator lifetime by repeating each profile's index `weight`
times, then draws from it with `array_rand()`. This is weighted sampling
without pulling in a dedicated weighted-random-choice algorithm (e.g.
alias method) — proportional representation falls out of how many times an
index appears in the pool, and the per-draw cost stays a single `array_rand()`
call. Each profile also carries its own `amount_min`/`amount_max` and
`currencies`, so amount ranges are now realistic per merchant category
instead of one global range applied to every merchant.

### Anti-Pattern Avoided: Uniform-Probability Merchant Selection
The old `array_rand($merchants)` over a flat list gave every merchant equal
selection probability regardless of real-world transaction volume — a
low-frequency forex profile would appear in the simulated stream exactly as
often as a high-frequency grocery profile. That flattens the traffic
distribution the benchmark is supposed to be measuring cache behavior
against. The weighted pool fixes this directly at the sampling step rather
than by post-hoc filtering.

### Decision: Fold Free-Text `message` into the Semantic-Cache Fingerprint
`EmbeddingService::createTransactionFingerprint()` now appends
`Message: <template text>` to the fingerprint string, and each merchant
category has 4–5 message templates it draws from at random. This raises
fingerprint entropy — two transactions identical in amount tier, category,
merchant, and time bucket can now still land on different fingerprints
depending on which template was picked, which cuts against the cache-hit
rate the new seeder is trying to benchmark. Went ahead with it anyway,
judged as a reasonable design choice for now; it intersects the
already-open ADR-0002 evaluation of which fingerprint fields help vs. hurt
cache-hit rate, and the ADR-0015 question of whether the 0.95 similarity
threshold is too strict. No ADR update accompanies this change — worth
revisiting together with ADR-0002/ADR-0015 rather than in isolation.
