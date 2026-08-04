---
id: sentinel-l7-2026-07-04T0945-ground-truth-export
repo: sentinel-l7
title: "Ground-Truth Export Command (sentinel:export-ground-truth)"
date: 2026-07-04
phase: 18
tags: [ground-truth, arbiter-l8, eval-harness]
files: [app/Console/Commands/ExportGroundTruth.php, tests/Feature/ExportGroundTruthTest.php, README.md]
---

# Ground-Truth Export Command (sentinel:export-ground-truth)

Added `sentinel:export-ground-truth`, an Artisan command that dumps
`TransactionStreamService::generate()`'s pre-AI synthetic transactions as an
`{"examples": [{"input", "expected_label"}]}` JSON payload — arbiter-l8's
offline harness (`run_eval`) consumes this directly as a new fixture
(`tests/fixtures/sentinel_l7_ground_truth.json`), alongside its existing
hand-written one. Built for arbiter-l8's Phase 3 step 8, closing a gap
flagged all the way back at step 5: the harness only ever had a
hand-written, Synapse-shaped fixture to validate the judge against, which
produced a nonsensical 6.7% accuracy number once the judge started
reasoning over real Sentinel-L7-shaped `raw_output` — not because the judge
reasoned badly, but because the fixture's `expected_label` taxonomy didn't
match what was actually being predicted.

### Pattern: Reuse the Existing Pre-AI Label Instead of Adding a New One
`TransactionStreamService::generate()` already yields `is_threat` per
transaction, sourced from `config('sentinel.simulation.merchants')` before
any AI analysis runs — genuine, non-circular ground truth that already
existed for the seeder's own benchmark stats. The export command adds zero
new labeling logic; it just re-shapes the same generator's output into the
`{input, expected_label}` pairs the eval harness's `EvalDataset` expects.

### Decision: Collapse the Binary `is_threat` to `'high'`/`'low'`, Not a New Three-Way Scheme
Ground truth only ever knows a boolean (threat or not) — there's no
pre-AI signal for `medium` vs `critical`. Rather than inventing a new
ground-truth vocabulary, `expected_label` uses exactly the same collapse
`TransactionProcessorService::gradeAiResult()` already applies internally
(`$isThreat ? 'high' : 'low'` as its own rule-based-fallback convention),
so the exported fixture stays consistent with a rule Sentinel-L7 already
follows rather than introducing a second, competing one. Downstream,
arbiter-l8's validation run treated any of `medium`/`high`/`critical`
as "caught the threat" when scoring against this binary ground truth,
matching `is_threat = risk_level !== 'low'` exactly.

### Decision: Zero Redis Side Effects
`generate()` is a pure, infinite generator over `config()` data — it never
touches `publish()`/`depth()`/the consumer group. The export command calls
only `generate()`, so running it has no interaction with the live stream,
idempotency keys, or consumer lag; it's safe to run against a database-only
or fully offline environment.

### Challenge: None
Straightforward reuse of an existing generator; no surprises.

Verified: 4 new `ExportGroundTruthTest` cases (stdout payload shape,
per-example `{input, expected_label}` keys matching the
`analyze-transaction` tool's argument schema, threat-label collapse
correctness, `--output` file-write path via a mocked `File` facade). Full
suite: 322/322 passing.
