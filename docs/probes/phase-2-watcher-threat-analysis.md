---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-2, tier-3-fallback]
---
{{c1::ThreatAnalysisService}} is the {{c2::tier-3 fallback}} in the compliance
pipeline — pure PHP rules with no {{c3::network calls}} — so it always returns
a verdict even when embedding or vector search fails.

Extra: sentinel-l7 · Phase 2 · Pattern: Tier-3 Fallback as a Pure Rule-Based Service
See: docs/journal.md#phase-2

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-2, redis-streams]
---
On the first `XREAD` call in `WatchTransactions`, pass {{c1::$}} to receive
only new messages; every subsequent call passes the {{c2::ID of the last
received message}} ($lastId) so nothing already seen is reprocessed.

Extra: sentinel-l7 · Phase 2 · Pattern: Cursor-Based Stream Consumption
See: docs/journal.md#phase-2

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-2, redis-streams]
---
Passing {{c1::0}} as the ID to every `XREAD` call in a watch loop causes
{{c2::unbounded replay}} — every message from the start of the stream is
reprocessed on each loop iteration, surfacing as duplicate output.

Extra: sentinel-l7 · Phase 2 · Anti-Pattern Avoided: Unbounded Replay from Stream Start
See: docs/journal.md#phase-2

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-2, value-object]
---
`ThreatAnalysisService::analyze()` returns a {{c1::value object}} with public
{{c2::$isThreat}} and {{c3::$message}} properties rather than an associative
array — named-property access without the overhead of a formal class
hierarchy.

Extra: sentinel-l7 · Phase 2 · Decision: analyze() Returns a Value Object, Not an Array
See: docs/journal.md#phase-2
