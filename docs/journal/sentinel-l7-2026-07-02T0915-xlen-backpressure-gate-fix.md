---
id: sentinel-l7-2026-07-02T0915-xlen-backpressure-gate-fix
repo: sentinel-l7
title: "Fix Permanently-Tripped XLEN Backpressure Gate"
date: 2026-07-02
phase: 11
tags: [backpressure, redis-streams, root-cause-analysis]
files: [app/Console/Commands/StreamTransactions.php, config/sentinel.php, tests/Feature/StreamTransactionsTest.php]
---

# Fix Permanently-Tripped XLEN Backpressure Gate

With the Ollama cutover live, tried to generate fresh demo data by running
all services and `sentinel:stream --limit=100`. Nothing moved: the
dashboard total sat at 307, `sentinel:watch` was visibly processing
transactions with an implausible ~99% cache-hit rate, and Upstash's
`default` namespace held exactly one vector. Three symptoms, one root
cause.

### Anti-Pattern Avoided: Debugging Symptoms Instead of Finding the One Cause
The instinct was to chase each symptom separately — investigate the high
hit rate as an embedding-quality problem, investigate "stuck at 307" as a
dashboard refresh problem. Both were red herrings. Stepping back with a
single side-by-side check (`XLEN` vs `XPENDING` summary on the same
stream) resolved all three symptoms at once: `XLEN` read 801 while the
consumer group's pending count was `0` — a fully-drained backlog reported
as a deep one.

### Challenge: `XADD ... MAXLEN ~ 1000` Makes Raw Stream Size a Bad Backpressure Signal
`sentinel:stream`'s original backpressure gate (ADR-0022 "step 1") paused
the producer whenever `TransactionStreamService::depth()` (raw `XLEN`)
exceeded 800. Approximate `MAXLEN` trimming only removes entries as new
ones are added — it does nothing in response to consumption. Once the
stream has ever grown to ~1000 entries in its lifetime, `XLEN` stays
pinned near that ceiling indefinitely, regardless of how completely the
consumer group has caught up. The gate had nothing left to measure once
that point was reached; it just permanently blocked the producer. Every
transaction `sentinel:watch` was processing (odd, unfamiliar merchant
names like "Blendz" and "Sandwich.net" that don't exist in the current
`config('sentinel.simulation.merchants')`) turned out to be old backlog
sitting in that near-1000-entry buffer from long before the current
merchant profiles existed — not anything from this session.

### Decision: Delete the XLEN Gate Rather Than Patch It
ADR-0023's graduated consumer-lag backpressure (`XPENDING`-based
`lag_warn`/`lag_pause`) already measures the thing that actually matters —
real unacknowledged backlog — and sits two checks below the broken gate in
the same loop. Removed the `XLEN` gate entirely instead of trying to
recalibrate its threshold, since no threshold fixes a signal that doesn't
correlate with backlog once `MAXLEN` trimming is in play. Also removed the
now-dead `publish_pause_threshold`/`publish_pause_ms` config and the test
that exercised the deleted gate. `TransactionStreamService::depth()`
itself was left in place — it is not wrong, just not useful for this
purpose — since it's still directly tested and cost nothing to keep.

### Challenge: `TransactionProcessorService` Also Never Resets Once Started
A smaller, related lesson: `sentinel_metrics_*` counters are plain Redis
keys with no session boundary — `sentinel:reset-metrics` was never run
this session, so the dashboard's "307" mixed weeks-old accumulated counts
with the ~99 events actually processed by this session's worker. Not a
bug, but a reminder that a stat looking static doesn't mean nothing is
happening — cross-checking a command's own live log against the
cumulative counter it feeds was what separated "no new activity" from
"old data dominates the total."

Verified after the fix: `sentinel:stream --limit=100` published all 100
transactions immediately (zero pause messages, vs. zero *successful*
publishes across ~16 minutes and 1973 pause messages before the fix).
Upstash `default` namespace grew from 1 vector to 3+ during the drain;
Postgres `transactions` table (cleared to 0 beforehand for a clean read)
picked up 91 new rows within seconds of the run finishing.
