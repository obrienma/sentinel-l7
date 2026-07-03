# Probes — Phase 11: Fix Permanently-Tripped XLEN Backpressure Gate

See: docs/journal.md#phase-11

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-11, anti-pattern, debugging]
---
Q: Three symptoms appeared at once — dashboard stuck at 307, ~99% cache
hit rate, and only one vector in Upstash. What debugging anti-pattern was
almost fallen into, and what single check resolved all three?

A: Chasing each symptom as a separate problem — treating the hit rate as
an embedding-quality bug and the stuck counter as a dashboard-refresh bug.
Both were red herrings. A single side-by-side check — `XLEN` vs. the
`XPENDING` summary count on the same stream — showed `XLEN` at 801 while
the consumer group's actual pending backlog was 0, explaining all three
symptoms as one root cause rather than three.

Extra: sentinel-l7 · Phase 11 · Anti-Pattern Avoided: Debugging Symptoms Instead of Finding the One Cause
See: docs/journal.md#phase-11

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-11, challenge, redis-streams]
---
Q: `sentinel:stream`'s backpressure gate paused the producer whenever raw
`XLEN` exceeded 800. Why did this become permanently, uselessly tripped
once the stream had ever grown large, even with a fully-drained consumer
backlog?

A: `TransactionStreamService::publish()` writes with `XADD ... MAXLEN ~
1000` — approximate trimming that only removes old entries as a side
effect of new writes, never in response to consumption. Once the stream
had grown to ~1000 entries in its lifetime, `XLEN` stayed pinned near that
ceiling forever, regardless of how completely the consumer group had
caught up. Raw stream size and actual backlog are different things once
`MAXLEN` trimming is involved, and the gate was measuring the wrong one.

Extra: sentinel-l7 · Phase 11 · Challenge: XADD MAXLEN Makes Raw Stream Size a Bad Backpressure Signal
See: docs/journal.md#phase-11

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-11, decision, backpressure]
---
Q: Why was the broken `XLEN` backpressure gate deleted outright instead of
recalibrating its threshold to a higher number?

A: ADR-0023's graduated consumer-lag backpressure (`XPENDING`-based
`lag_warn`/`lag_pause`) already measures the thing that actually matters —
real unacknowledged backlog — and runs two checks below the broken gate in
the same loop. No threshold value fixes a signal that stops correlating
with backlog once `MAXLEN` trimming keeps the raw stream size pinned near
its cap; the gate had to go, not just get a bigger number.

Extra: sentinel-l7 · Phase 11 · Decision: Delete the XLEN Gate Rather Than Patch It
See: docs/journal.md#phase-11

---
type: basic
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-11, challenge, metrics]
---
Q: The dashboard read exactly 307 both before and partway through this
session's data-generation attempt. Why didn't that mean nothing was
happening, and what confirmed the real explanation?

A: `sentinel_metrics_*` counters are plain Redis keys with no session
boundary — `sentinel:reset-metrics` was never run, so "307" was weeks of
accumulated history, not a live session count. Cross-checking the
worker's own live log (98 hits, 1 miss captured in that session) against
the cumulative Redis counter (300 hits, 7 misses) showed the session's
real contribution was small relative to old data — the counter looking
static didn't mean the pipeline was idle, just that new activity was a
small fraction of a large accumulated total.

Extra: sentinel-l7 · Phase 11 · Challenge: Metrics Counters Have No Session Boundary
See: docs/journal.md#phase-11
