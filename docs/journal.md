# Engineering Journal — Sentinel-L7

Per-phase engineering journal. Typed, vocabulary-enforced sections (see
`~/.claude/skills/journal-anki.md`). Paired Anki probes live in
`docs/probes/phase-N-<name>.md`. Migrated from `LEARNING_LOG.md`.

---

## Phase 1 — Stream Simulator (`sentinel:stream`) — 2026-02-09 – 2026-02-19
Commits: `9697431`, `d0d8375`
Files: app/Console/Commands/StreamTransactions.php, app/Services/TransactionStreamService.php, config/sentinel.php, tests/Feature/StreamTransactionsTest.php, tests/Unit/TransactionStreamServiceTest.php

Built `sentinel:stream`, an Artisan command that seeds Redis Streams with
synthetic transactions for the downstream compliance pipeline to consume.

### Pattern: Graceful Shutdown via Signal-Flag Polling
`StreamTransactions` registers `pcntl_signal` handlers for `SIGINT`/`SIGTERM`
that flip a `$running` boolean rather than calling `exit()` directly. The
`while ($running)` loop tests the flag once per iteration, so an in-flight
`XADD` completes before the process exits. This is cooperative cancellation:
the signal marks intent, the loop chooses a safe point to honour it, which is
what prevents a mid-write tear on CTRL-C.

### Pattern: Idempotency Guard via `SETNX` Before Stream Writes
Before `XADD`, `TransactionStreamService::publish()` issues
`SETNX sentinel:seen:{id}` with a 24h TTL. An already-present key means the
transaction was published before, so the write is skipped and `false` returned.
The guard lives in a dedicated key namespace, keeping the dedup check O(1) and
independent of stream length — the producer-side complement to an idempotent
receiver downstream.

### Anti-Pattern Avoided: Scattered Magic Stream Keys
The tempting shortcut is to inline the `sentinel:transactions` string wherever
a command needs it. Instead the key is a single class constant on
`TransactionStreamService`, read only there; commands obtain it through the
service. Single source of truth — a later rename is one edit, not a grep across
every command file.

### Challenge: `pcntl` Extension Silently Absent
Symptom: CTRL-C killed the process abruptly with no clean loop exit. Root
cause: `pcntl_signal` requires the `pcntl` extension enabled; when it is not,
handler registration is a silent no-op — no error, the signal just falls
through to default SIGINT termination. Fix: confirmed the extension present in
the Render build (it was) and locally. The failure mode is insidious precisely
because nothing throws.

### Decision: `--limit` / `--speed` as Tunable Options
`--limit=10` (transactions per run) and `--speed=1000` (ms between writes) are
command options with conservative defaults that won't spam a dev Redis. Pushing
`--limit=100 --speed=100` enables load testing with no code change — keeping
the knobs out of code and in the invocation.
