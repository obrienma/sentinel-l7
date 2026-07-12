# ADR 0022 — XAUTOCLAIM Self-Healing Worker Pool

**Date:** 2026-05-14 (proposed) → 2026-05-16 (accepted + implemented)
**Status:** Accepted

---

## Context

Sentinel-L7 runs two Redis Stream consumer workers — `sentinel:watch` (transactions) and `sentinel:watch-axioms` (Axioms) — plus a separate `sentinel:reclaim-axioms` daemon that uses `XCLAIM` to recover messages abandoned by crashed workers.

The reclaimer pattern has two structural weaknesses:

1. **It is a third process.** `composer dev-full` must start it alongside the two workers. If the reclaimer itself crashes or fails to start, abandoned messages sit in the PEL indefinitely with no recovery.
2. **Recovery is centralised.** A single reclaimer polls on a fixed interval. Under a cascade failure (multiple workers down simultaneously) the reclaimer processes abandoned messages serially rather than in parallel.

Additionally, the transaction stream (`sentinel:watch`) currently uses plain `XREAD` with no consumer group, meaning crashes on that side drop messages entirely — there is no PEL to reclaim from (see backpressure plan, step 2).

Redis 6.2 introduced `XAUTOCLAIM`, which combines `XPENDING` + `XCLAIM` into a single atomic command. This enables a different architecture: each worker heals the pool as part of its normal read loop, eliminating the dedicated reclaimer process.

---

## Decision

Replace the dedicated reclaimer process with an `XAUTOCLAIM` pass at the top of each worker's read loop.

**Read loop structure (per worker):**

```
loop:
  1. XAUTOCLAIM <stream> <group> <consumer> <min-idle-ms> 0-0 COUNT 10
     → process any claimed orphan messages; XACK each
  2. XREADGROUP GROUP <group> <consumer> COUNT 1 BLOCK 5000 STREAMS <stream> >
     → process new message; XACK
```

Step 1 runs on every iteration. If there are no idle messages, `XAUTOCLAIM` returns an empty list and costs one round-trip (~1ms). Step 2 is the existing new-message read. Both steps XACK on completion regardless of processing outcome — poison handling is addressed separately (see Consequences).

**Idle time threshold:** 30 seconds. Gemini round-trips peak at ~8 seconds under load; 30 seconds gives a factor-of-3 margin before a slow-but-alive worker has its message stolen.

**Delivery count guard:** Before processing an autoclaimed message, check its delivery count. If `delivery-count >= 3`, route to a dead-letter log entry (structured `Log::error`) and XACK without processing. This prevents poison messages from circulating indefinitely.

> **Implementation note (2026-05-16):** XAUTOCLAIM does *not* return delivery count metadata in its response (the response shape is `[next-cursor, [[id, [fields...]], ...], [deleted-ids]]`). The guard is therefore implemented as a follow-up `XPENDING <stream> <group> IDLE 0 <id> <id> 1` per claimed message — one extra round-trip per autoclaimed entry. For the default `COUNT 10` per autoClaim pass this is bounded and acceptable. If batch sizes grow significantly, consider a single `XPENDING <stream> <group> - + <count> <consumer>` after the autoClaim and join the responses in memory.

---

## Options considered

### Option A — Keep dedicated reclaimer (status quo)
Separate `ReclaimAxioms` / `ReclaimTransactions` processes poll `XPENDING` + `XCLAIM` on a timer.

Rejected: adds a third (fourth, once transactions are on XREADGROUP) process that must be managed independently; recovery is centralised; if the reclaimer is down, recovery stops entirely.

### Option B — XAUTOCLAIM embedded in each worker (chosen)
Recovery is distributed across all running workers. Losing one worker doesn't stop recovery — the others pick up orphaned messages on their next iteration. Fewer processes to deploy and monitor.

### Option C — Dead-letter queue with separate retry worker
Poison messages routed to a `sentinel:dlq` stream; a separate worker retries with backoff.

Deferred: adds infrastructure complexity. The delivery-count guard in Option B handles the poison case adequately for current scale. If retry-with-backoff becomes a requirement, it can be layered on top.

---

## Consequences

- [x] Both `WatchTransactions` and `WatchAxioms` updated to run `XAUTOCLAIM` at the top of each loop iteration
- [x] `ReclaimAxioms` command and process removed from `composer dev`
- [x] Delivery count guard implemented (log + XACK at `delivery-count >= 3`) via follow-up XPENDING per claimed message
- [x] `min-idle-time` extracted to `config/sentinel.php` as `sentinel.reclaim.idle_ms` (default: 30000); poison threshold as `sentinel.reclaim.delivery_count_limit` (default: 3)
- [x] Pest test: `XAUTOCLAIM` called before `XREADGROUP` in each worker loop iteration
- [x] Pest test: a message with `delivery-count >= 3` is logged and ACKed without being processed
- [x] README architecture diagrams updated — reclaimer node removed, XAUTOCLAIM noted on both worker edges
- [x] Requires Redis 6.2+ — Upstash supports this; confirmed in practice: XAUTOCLAIM has run against Upstash in both workers since acceptance (2026-05-16) with no unsupported-command errors
- [x] This ADR supersedes the reclaimer pattern described in ADR-0016 (Axiom ingestion) and the backpressure plan step 2 (which assumed extending the existing reclaimer to cover transactions)
