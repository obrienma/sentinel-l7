---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-1, graceful-shutdown]
---
Graceful shutdown in `sentinel:stream` registers {{c1::pcntl_signal}} handlers
for SIGINT/SIGTERM that flip a {{c2::$running}} flag the `while` loop checks
each iteration, so the in-flight {{c3::XADD}} finishes instead of being torn
mid-write.

Extra: sentinel-l7 · Phase 1 · Pattern: Graceful Shutdown via Signal-Flag Polling
See: docs/journal.md#phase-1

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-1, idempotency]
---
The producer dedups before `XADD` with a {{c1::SETNX}} on `sentinel:seen:{id}`
(24h TTL), an {{c2::O(1)}} check — versus scanning the stream for an existing
message ID via {{c3::XRANGE}}, which is O(N).

Extra: sentinel-l7 · Phase 1 · Pattern: Idempotency Guard via SETNX
See: docs/journal.md#phase-1

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-1, single-source-of-truth]
---
The `sentinel:transactions` stream key is kept as a {{c1::class constant}} on
`TransactionStreamService` (single source of truth) rather than as
{{c2::repeated string literals}} scattered across commands, so a rename is one
edit.

Extra: sentinel-l7 · Phase 1 · Anti-Pattern Avoided: Scattered Magic Stream Keys
See: docs/journal.md#phase-1

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-1, pcntl]
---
If the {{c1::pcntl}} extension is unavailable, `pcntl_signal` handler
registration is a {{c2::silent no-op}} — SIGINT falls through to default
termination, killing the process with no clean loop exit and no error thrown.

Extra: sentinel-l7 · Phase 1 · Challenge: pcntl Extension Silently Absent
See: docs/journal.md#phase-1
