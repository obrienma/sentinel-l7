# Backpressure Plan

**Goal:** Give the Sentinel pipeline explicit, graduated flow control so a slow consumer can't be silently overwhelmed by a fast producer.

**Why this matters:** The stream today has a MAXLEN trim — that's lossy, not backpressure. A burst of transactions that outpaces the AI pipeline silently drops the oldest messages with no signal to the producer and no record of what was lost. The transaction stream also uses plain `XREAD` (no consumer group), so a mid-flight worker crash drops in-progress messages permanently. This plan closes both gaps in three steps.

**See also:** ADR-0022 — the XREADGROUP migration in step 2 enables the XAUTOCLAIM self-healing worker pool described there. That ADR supersedes the reclaimer extension approach originally described in step 2.

**Ground rules:**
- Steps must be done in order — each step is a prerequisite for the next.
- No new infrastructure. Redis already has everything needed.
- Every step has at least one test that would catch the regression if the wiring were removed.
- XACK is never gated on quality or backpressure state — messages are always acknowledged once processed.

---

## Step 1 — COUNT limit on XREAD + XLEN producer guard ✅ Done (2026-05-16)

**Estimate:** 1–2 hours  
**GitHub issue:** backpressure: add COUNT limit to transaction XREAD + XLEN producer guard

### What to change

- `TransactionStreamService::read()` — add `COUNT 1` to the `XREAD` call so a deep stream doesn't flood the loop with all pending messages in one read.
- `StreamTransactions` command — before each `XADD`, check `XLEN sentinel:transactions`; if depth exceeds a configurable threshold (default 800), sleep and retry rather than publishing.

### Why this first

No architecture change. The risk of a burst flood is real today; this removes it in under two hours. Prerequisite for step 2 only in the sense that it makes the stream well-behaved before we add consumer group machinery.

### Definition of done
- A unit test asserting `COUNT 1` appears in the raw Redis command sent by `read()`.
- A unit test asserting `StreamTransactions` skips `XADD` when a mocked `XLEN` returns > 800.

### Out of scope
- Configurable per-stream thresholds. One threshold, one config key.

---

## Step 2 — Migrate WatchTransactions to XREADGROUP + XAUTOCLAIM recovery

**Estimate:** 4–6 hours  
**GitHub issue:** backpressure: migrate WatchTransactions to XREADGROUP (consumer group)  
**Depends on:** Step 1 (stream should be well-behaved before adding PEL machinery)  
**See:** ADR-0022 — recovery strategy changed from dedicated reclaimer to XAUTOCLAIM embedded in each worker

### What to change

- `TransactionStreamService` — replace `XREAD BLOCK 0` with `XREADGROUP GROUP sentinel-consumers worker-1 COUNT 1 BLOCK 5000 STREAMS sentinel:transactions >`.
- Add an `ack(string $id)` method to `TransactionStreamService` that issues `XACK`.
- Add an `autoClaim(string $consumer, int $minIdleMs, int $count)` method to `TransactionStreamService` that issues `XAUTOCLAIM`.
- `WatchTransactions` — restructure the read loop:
  1. Call `autoClaim` to steal any messages idle > 30s; check delivery count; process or dead-letter; XACK each.
  2. Call `XREADGROUP >` for new messages; process; XACK.
- Apply the same XAUTOCLAIM pattern to `WatchAxioms` / `AxiomStreamService`, replacing the `ReclaimAxioms` daemon.
- Remove `ReclaimAxioms` command and remove `sentinel:reclaim-axioms` from `composer dev-full` / `Procfile`.
- Create the consumer group on first run (`XGROUP CREATE ... MKSTREAM`).
- Add `sentinel.reclaim.idle_ms` (default: 30000) to `config/sentinel.php`.

### Why this matters beyond backpressure

Plain `XREAD` silently drops messages on worker crash. XREADGROUP gives us a PEL. XAUTOCLAIM embedded in the loop distributes recovery across all running workers — if the reclaimer is down, orphaned messages are still recovered. Fewer processes to deploy and monitor.

Delivery count guard: if `delivery-count >= 3`, log a structured `Log::error` and XACK without processing. This prevents poison messages circulating indefinitely.

### Definition of done
- Pest test: `XAUTOCLAIM` is called before `XREADGROUP` in each worker loop iteration.
- Pest test: `XACK` is called after successful processing.
- Pest test: a message with `delivery-count >= 3` is logged and ACKed without being processed.
- Kill -9 the transaction worker mid-process; observe the other worker (or a restarted instance) claims the pending message via XAUTOCLAIM after 30s.
- `ReclaimAxioms` command deleted; `composer dev-full` updated to two processes (web + worker per stream).
- README architecture table updated — reclaimer row removed, XAUTOCLAIM noted in worker description.
- Confirm Upstash Redis version ≥ 6.2 before shipping.

### Out of scope
- Multi-consumer fan-out. One consumer group, one worker instance per stream.
- Retry-with-backoff for dead-lettered messages (deferred per ADR-0022).

---

## Step 3 — Explicit consumer lag signal via Redis key

**Estimate:** 2–3 hours  
**GitHub issue:** backpressure: explicit consumer lag signal via Redis key  
**Depends on:** Step 2 (requires XREADGROUP + XPENDING to be in place)

### What to change

- `WatchTransactions` — after each `process()` + `ack()` cycle, call `XPENDING sentinel:transactions sentinel-consumers - + 1` and write the count to `sentinel:consumer_lag` as a plain Redis `SET` with a short TTL (e.g. 10s).
- `StreamTransactions` — before each `XADD`, read `sentinel:consumer_lag`. Apply graduated delay:
  - lag > 50 → 500 ms sleep before publish
  - lag > 200 → spin-wait (100 ms polls) until lag drops below 200
- Extract thresholds to `config/sentinel.php` keys (`backpressure.lag_warn`, `backpressure.lag_pause`).

### Why a Redis key and not XLEN

XLEN measures stream depth, which decreases as messages are read (even before they're processed). XPENDING measures unacknowledged messages — the actual work in flight. A slow AI call with low XLEN and high XPENDING would be invisible to an XLEN-based guard. The lag key is a more accurate proxy for "is the consumer overwhelmed."

### Definition of done
- A unit test asserting the lag key is written after each process cycle.
- A unit test asserting `StreamTransactions` sleeps when a mocked lag key returns > 50.
- A unit test asserting `StreamTransactions` spin-waits when lag returns > 200.
- ADR written (or existing ADR updated) documenting the graduated threshold values and the decision to use XPENDING over XLEN.

### Out of scope
- Surfacing the lag key on the dashboard. That's a metrics/observability task.
- Per-domain lag tracking.

---

## Sequencing

```
Step 1 (2h)  →  Step 2 (6h)  →  Step 3 (3h)
```

Total estimate: ~11 hours across two sessions. Step 1 can ship independently. Steps 2 and 3 should ship as a pair if possible — XREADGROUP without the lag signal is still a net win (crash safety + reclaimer removal), but the full backpressure story isn't complete until step 3.

Step 2 is now slightly larger than originally estimated because it covers both streams (transactions + axioms) and replaces the reclaimer daemon rather than extending it.
