---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-4, retries]
---
Retry-with-backoff before tier-3 fallback: `EmbeddingService::embed()` retries
{{c1::3}}× at {{c2::200ms}}; `VectorCacheService` retries {{c3::2}}× at
{{c4::150ms}}. Both use {{c5::fixed-delay}} retry, not exponential backoff —
the consumer loop is synchronous, so growing delays would themselves become a
latency problem.

Extra: sentinel-l7 · Phase 4 · Pattern: Retry with Fixed Delay Before Tier-3 Fallback
See: docs/journal.md#phase-4

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-4, observability]
---
Sentinel's observability for transient failures (embedding, vector search) is
{{c1::Log::warning}} with structured context (service name, error message) —
there's no dedicated {{c2::APM}}; logs are the primary signal, searchable via
Railway's log drain.

Extra: sentinel-l7 · Phase 4 · Pattern: Log::warning as the Observability Hook for Every Failure Path
See: docs/journal.md#phase-4

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-4, http]
---
Without a timeout on `Http::post()`, a hung Gemini/Upstash connection in the
single-threaded `while(true)` consumer loop causes {{c1::head-of-line
blocking}} — every transaction queued behind it stalls indefinitely. Fixed
with {{c2::->timeout(10)}} (embedding) and {{c3::->timeout(5)}} (vector).

Extra: sentinel-l7 · Phase 4 · Anti-Pattern Avoided: Head-of-Line Blocking from Unbounded HTTP Calls
See: docs/journal.md#phase-4

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-4, testing]
---
To test retry logic without real `sleep()` delays, use {{c1::Http::sequence()}}
to return {{c2::N-1 failure}} responses followed by a {{c3::success}} response
— the service retries against the sequence with no real network or sleep.

Extra: sentinel-l7 · Phase 4 · Challenge: Testing Retries Without Real Sleep
See: docs/journal.md#phase-4

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-4, vector-cache]
---
`VectorCacheService::delete()` was added during hardening with
{{c1::no current caller}}, because cache invalidation on a {{c2::compliance
policy change}} requires evicting every cached verdict under the old policy —
deferring it would block that future work entirely.

Extra: sentinel-l7 · Phase 4 · Decision: Speculative VectorCacheService::delete()
See: docs/journal.md#phase-4
