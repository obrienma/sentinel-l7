---
id: sentinel-l7-2026-02-19T2350-watcher-threat-analysis
repo: sentinel-l7
title: "Real-time Watcher + Threat Analysis (sentinel:watch)"
date: 2026-02-19
phase: 2
tags: [tier-3-fallback, cursor-based-consumption, redis-streams, value-object]
files: [app/Console/Commands/WatchTransactions.php, app/Services/ThreatAnalysisService.php, tests/Unit/ThreatAnalysisServiceTest.php]
---

# Real-time Watcher + Threat Analysis (sentinel:watch)

Commits: `36ed585`

Added `WatchTransactions` (an infinite `XREAD` loop) and
`ThreatAnalysisService` (a rule-based L7 compliance checker) — the first
end-to-end pipeline: stream producer → Redis → consumer → threat verdict → CLI
output.

### Pattern: Tier-3 Fallback as a Pure Rule-Based Service
`ThreatAnalysisService` is the tier-3 fallback: amount-threshold and
category-flag rules implemented in pure PHP with no network calls. Because it
performs no I/O, it cannot throw on a downstream outage and always returns a
verdict — the property that lets `TransactionProcessorService` fall back to it
unconditionally when embedding or vector search fails.

### Pattern: Cursor-Based Stream Consumption
`WatchTransactions` tracks a `$lastId` cursor passed to every `XREAD` call.
The first call uses `$` (only new messages from this point forward); every
subsequent call passes the previously-received message ID. This guarantees the
consumer advances monotonically through the stream and never reprocesses a
message within the session.

### Anti-Pattern Avoided: Unbounded Replay from Stream Start
An early version polled `XREAD` from `0` every iteration, replaying the entire
stream history on each loop. The failure mode is silent duplication — no error,
just every historical transaction reprocessed repeatedly, surfacing as
duplicate CLI output. Cursor-based reads (above) eliminate it.

### Decision: `analyze()` Returns a Value Object, Not an Array
`ThreatAnalysisService::analyze()` returns a plain PHP object with public
`$isThreat` and `$message` properties rather than an associative array. This
gives call sites named-property access (`$result->isThreat`) without the
overhead of a formal class hierarchy — the minimal step up from an array that
still buys typo-safety at call sites.
