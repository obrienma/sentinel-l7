# ADR 0023 — Graduated Consumer Lag Backpressure

**Date:** 2026-05-16
**Status:** Accepted

---

## Context

Steps 1 and 2 of the backpressure plan add an `XLEN`-based depth guard to the producer (`StreamTransactions`) and migrate the consumer (`WatchTransactions`) to `XREADGROUP`. This closes the burst-flood risk and the crash-recovery gap, but does not give the producer a real-time signal of how overwhelmed the consumer actually is.

`XLEN` measures stream depth — the number of messages in the stream that have not yet been read. It drops as soon as a worker reads a message, even before the message is processed. A slow Gemini call (4–8 seconds) means `XLEN` can read zero while 200 messages are in the PEL awaiting acknowledgement. An `XLEN`-based guard would see no problem and continue publishing at full rate.

`XPENDING` on the consumer group measures unacknowledged messages — the actual AI-processing work in flight. This is the correct proxy for "is the consumer overwhelmed?"

---

## Decision

After every `readGroup` + `ack` cycle, `WatchTransactions` writes the current `XPENDING` count to a Redis key `sentinel:consumer_lag` with a 10-second TTL.

`StreamTransactions` reads this key before each `XADD` and applies a graduated delay:

| Lag | Action |
|-----|--------|
| ≤ `lag_warn` (50) | Publish immediately |
| > `lag_warn` (50) | Sleep `lag_warn_sleep_ms` (500ms) once, then publish |
| > `lag_pause` (200) | Spin-wait at `lag_pause_poll_ms` (100ms) polls until lag ≤ `lag_pause`, then publish |

All thresholds are configurable via `config/sentinel.php` and overridable via environment variables. Both delay loops honour `$shouldStop` (SIGINT/SIGTERM) so the producer can exit cleanly while spin-waiting.

The lag key expires in 10 seconds. If the consumer stops writing (crash, restart), the key expires and `readLagKey()` returns 0 — the producer treats stale/missing lag as zero and publishes freely. This is intentional: a dead consumer with a zero-lag signal causes no data loss at the producer (messages accumulate safely in the stream); it's the existing crash-recovery path (XAUTOCLAIM) that recovers in-flight messages.

---

## Options considered

### Option A — Binary pause at a single threshold
Pause the producer completely when `XPENDING > N`, resume when `XPENDING ≤ N`.

Rejected: produces oscillating producer behaviour — sprint to the threshold, pause completely, spike back — which amplifies XPENDING variance rather than dampening it.

### Option B — Graduated delay at two thresholds (chosen)
Soft limit introduces a brief one-shot sleep; hard limit holds the producer in a poll loop until the consumer catches up. Graduated response smooths the producer rate, reducing the amplitude of lag oscillation.

### Option C — Backpressure via Redis Streams BLOCK
Block the producer on a second stream that the consumer ACKs to signal readiness.

Rejected: adds a second stream and coordination pattern with more moving parts than a single Redis key, with no meaningful advantage at current scale.

---

## Consequences

- [x] `WatchTransactions` writes `sentinel:consumer_lag` after every `readGroup` iteration via `TransactionStreamService::writeLagKey(pendingCount())`
- [x] `StreamTransactions` reads lag before each `XADD` via `TransactionStreamService::readLagKey()` and applies the graduated delay
- [x] `TransactionStreamService` exposes: `pendingCount()` (XPENDING summary), `writeLagKey(int)` (SET EX 10), `readLagKey()` (GET, returns 0 if expired)
- [x] All four threshold values extracted to `config/sentinel.php` under `backpressure.*`
- [x] Lag key TTL of 10s means stale lag → 0 → producer publishes freely (safe: consumer recovery is XAUTOCLAIM's job)
- [ ] Step 3 is not wired to `WatchAxioms` — Axiom lag is not yet surfaced. This is out of scope; the transaction pipeline is the primary throughput concern
- [ ] Dashboard: `sentinel:consumer_lag` is not surfaced on the metrics dashboard. Deferred to a future observability task
