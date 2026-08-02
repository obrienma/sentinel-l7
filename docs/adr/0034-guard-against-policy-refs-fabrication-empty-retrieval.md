# ADR 0034 — Guard Against `policy_refs` Fabrication on Empty Retrieval

**Date:** 2026-08-01 **Status:** Accepted

---

## Context

Arbiter-L8 spike 0001 (`sentinel-eval/docs/spikes/0001-narrative-citation-format.md`) was investigating whether Sentinel-L7's `narrative` field cites `policy_refs` in a consistent, extractable format. It surfaced a more consequential finding along the way.

Across 49 live `cache_miss` compliance-analysis calls (`SENTINEL_AI_DRIVER= openrouter`), `fetchPolicyContext()` logged `chunk_count: 0` for all 49 — no chunk cleared the similarity threshold for any of these transaction queries, and the prompt sent to the model stated `"No specific policy context retrieved."` in every case. Despite that, the model returned a non-empty `policy_refs` on 18 of the 49 calls, at an average stated `confidence` of 0.88 (range 0.6–0.95).

The same batch also included 2 Tier-3-fallback rows, which reached `fetchPolicyContext` (also `chunk_count: 0`) before failing later at the chat-model call itself — likely an OpenRouter free-tier rate-limit hiccup — and falling through to the rule-based path. `source: fallback` in `TransactionProcessorService` therefore isn't monolithic: it covers both "never reached the AI driver" (skips RAG entirely) and "reached RAG and the model call, but the model call itself failed." Not otherwise relevant here since Tier 3's own rule-based narrative never populates `policy_refs` regardless.

This means `policy_refs` is not currently reliable as a record of what was actually retrieved and reasoned over — it can be, and in this sample routinely was, invented by the model with no grounding, asserted alongside high confidence.

Two existing pieces of Sentinel-L7 infrastructure are directly relevant and already anticipate half of this problem:

-   ADR-0018 added `chunk_count` and `filter_used` logging specifically so that `chunk_count=0` could serve as "an explicit signal" for "silent partial failure alerting." That alerting was scoped to the domain-filter case (a filter matching zero chunks); it was never extended to the simpler case of *no filter and no chunks at all*, which is what this spike found.
-   ADR-0019's four-signal quality rubric awards a point for `has_policy_refs` whenever `policy_refs` is non-empty, with no check against `chunk_count`. As written, the rubric currently scores a fabricated citation as a quality *positive* — the exact opposite of what it should do.

This is not a narrow eval-tooling concern. A `narrative` citing a specific policy that was never retrieved, attached to a `compliance_events` row and a confidence score, is a fabricated regulatory citation sitting in what is meant to be an audit trail. It should be fixed at the source rather than detected and worked around downstream in Arbiter-L8.

---

## Decision

**Enforce `policy_refs` grounding programmatically, not through prompting alone.** In `AbstractComplianceDriver` (or each driver's `parseResponse()`, per ADR-0027's hoisted structure), after the AI response is parsed: if `chunk_count === 0` for the retrieval that produced this response, force `policy_refs = []` regardless of what the model returned, irrespective of prompt wording. Do not rely on prompt instructions ("No specific policy context retrieved") as the sole safeguard — this run's data shows the model does not reliably honor that instruction under a nonzero-`chunk_count =0` prompt.

**Surface the correction, don't silently drop it.** When this guard fires (model returned non-empty `policy_refs` but `chunk_count` was 0), log it explicitly — e.g. `Log::warning('{Driver}: policy_refs fabricated on empty retrieval', [...$context, 'model_asserted_refs' => $original_refs])` — so the fabrication rate remains observable rather than disappearing once corrected.

**Amend ADR-0019's rubric.** `has_policy_refs` should require \`chunk\_count

> 0`in addition to non-empty`policy\_refs`. With the guard above in place this becomes automatic (a corrected empty` policy\_refs\` naturally scores 0), but the rubric's stated definition should be updated to reflect the real check, not just inherit the fix incidentally.

---

## Consequences

**Positive**

-   `policy_refs` becomes a trustworthy field — any non-empty value is guaranteed to correspond to at least one chunk that actually cleared the retrieval threshold. Downstream consumers (Arbiter-L8, any future narrative-grounding heuristic, human auditors reading `compliance_events`) can treat it as ground truth again.
-   The `has_policy_refs` quality signal in ADR-0019 stops rewarding fabrication.
-   The fabrication-rate warning log gives a concrete, monitorable number (currently ~37% of non-cache-hit calls in this sample) that can be tracked as the policy corpus grows and retrieval improves.
-   Unblocks a simpler, higher-value Arbiter-L8 heuristic than the one originally scoped: a structural `policy_refs`-vs-`chunk_count` check becomes unnecessary as an eval-side heuristic once enforced at the source — Arbiter-L8 can instead just watch the new warning log / a `policy_context_unavailable` flag if one is added, rather than reimplementing the check independently.

**Negative / Trade-offs**

-   Narratives on `chunk_count=0` calls will now have empty `policy_refs` even when the model's underlying reasoning was reasonable — the guard can't distinguish "model correctly declined to cite anything" from "model would have cited something useful had retrieval worked." The actual problem (why does a 4-chunk corpus miss so often at the current similarity threshold?) is not fixed by this ADR — see Follow-up.
-   Slightly more logging volume (one warning per fabrication event, ~37% of non-cache-hit calls in the sampled run).

---

## Alternatives considered

**Fix via prompting only** (strengthen the "no context retrieved" instruction, add few-shot examples of correct empty-citation behavior). Rejected as the sole fix: this run already had that instruction present in the prompt and the model fabricated citations anyway on 18/49 calls. Prompting can still help as a secondary mitigation but isn't sufficient on its own for something with audit-trail consequences.

**Leave detection to Arbiter-L8** (build the narrative-grounding heuristic as originally scoped, flag fabrication after the fact rather than preventing it). Rejected as the primary fix: an eval harness that measures a bug without the bug being fixed doesn't build confidence in Sentinel-L7, it just documents the gap. Eval tooling remains valuable as an ongoing regression check after this fix ships, not as a substitute for it.

---

## Follow-up (outstanding)

-   Root cause of the 0% retrieval hit rate on this batch is not addressed here: the `policies` namespace currently holds 4 chunks total, and the 0.70 similarity threshold (ADR-0018) may be miscalibrated for the actual query distribution — same category of problem ADR-0015 already identified and fixed for the semantic cache threshold. Worth a follow-up ADR once corpus size grows past the current 4-chunk state, so the threshold is tuned against a representative corpus rather than re-guessed twice.
-   No `high`/`critical` risk\_level samples were available in either Arbiter-L8 spike pull. Fabrication behavior at that end of the risk spectrum — arguably where it matters most — remains unverified.