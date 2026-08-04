---
id: sentinel-l7-2026-07-11T0900-docs-audit-adr-consistency-sweep
repo: sentinel-l7
title: "Docs Audit — ADR Consistency Sweep (0001–0029)"
date: 2026-07-11
phase: docs-audit
tags: [adr, supersession, docs-audit, consistency-sweep]
files: ["docs/adr/0001", "docs/adr/0003", "docs/adr/0005", "docs/adr/0006", "docs/adr/0008", "docs/adr/0015", "docs/adr/0016", "docs/adr/0019", "docs/adr/0021", "docs/adr/0022", "docs/adr/0028", "docs/adr/0029", "CLAUDE.md", "README.md"]
---

# Docs Audit — ADR Consistency Sweep (0001–0029)

A full read of all 29 ADRs cross-checked against the code, prompted by
"do these stand up?" Findings clustered into three classes: outright
factual errors (an ADR-number collision, a bucket-boundary gap, a wrong
stream key), stale point-in-time claims that later work invalidated
without annotation, and one contract edge case (empty-batch
`next_cursor`) that the code handled correctly but the ADR never
specified.

### Pattern: Supersession Annotation Over Rewrite
ADRs are immutable decision records — when a later decision overturns
an earlier one, the correct move is a dated status amendment
("Superseded in part by ADR-NNNN", "amended 2026-MM-DD — X was replaced
by Y") on the old record, never a silent rewrite of its body. The body
stays as the honest record of what was believed at the time; the status
line is the navigation layer that keeps a reader landing on ADR-0003 or
ADR-0006 from acting on a dead architecture. Six ADRs (0003, 0005,
0006, 0008, 0016, 0019) got amendment notes pointing forward to
ADR-0022 (reclaimer removed), ADR-0025/0027 (Ollama defaults), and
ADR-0026 (default namespace retired).

### Anti-Pattern Avoided: Silent Documentation Drift
The recent ADRs (0028, 0029) name their own scope reversals explicitly
— 0029 even calls out which single line of 0028 it supersedes. The
early ADRs never did this, so the corpus had accumulated six records
that a new reader would take at face value and be wrong: a "stub"
driver that's fully implemented, a reclaimer daemon that no longer
exists, a `default` namespace no code path touches, a 0.95 threshold
that shipped as 0.90. The failure mode isn't the staleness itself —
it's that nothing distinguishes a still-true ADR from an overturned one
until someone reads all 29 against the code.

### Challenges
The 29% cache-hit-rate figure had contradictory provenance: ADR-0001
said "29%, with 0 hits on a cold cache run of 57 transactions" (which
also self-contradicts — 29% and 0 hits can't describe one run), while
ADR-0015 attributed the same 29% to a 2,293-transaction run. Neither
git history nor code can settle which run produced it. Resolved by
taking ADR-0015's more specific claim as authoritative for the 29%
(cross-referencing it from 0001) and keeping the 57-transaction cold
run as a separate 0-hit observation. If the real provenance differs,
both ADRs now at least tell one consistent story to correct.

Also: ADR-0015's file carried the heading "ADR-0003" — a copy-paste
number collision with the real ADR-0003 that had survived since March
because nothing ever validates heading-vs-filename agreement. Worth a
future lint (a 5-line Pest test could assert every `docs/adr/NNNN-*.md`
heading starts with its own number).

### Decision: Fix-in-Place vs. Annotate — Split by Error Class
Factual errors that were never true (wrong stream key name, the
time-bucket spec omitting 21:00–23:59 — code maps it to `night` via the
`match` default arm, so night is really 21:00–05:59 wrapping midnight;
the stale migration-comment claim in ADR-0028) were corrected in place —
preserving a false statement has no archival value. Decisions that were
true when written but later overturned got dated amendments instead.
ADR-0015 was the judgment call: still marked "Proposed / pending
empirical testing" while ADR-0002 records its threshold change shipping
in commit `48b83bd` and `config/services.php` defaults to 0.90. Flipped
to Accepted (2026-03-28) with the outstanding work (hit-rate benchmark,
nomic re-validation per ADR-0025) moved to an explicit follow-up
section, mirroring ADR-0002's structure.
