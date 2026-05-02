# ADR 0019 — Output Quality Scoring on Compliance Driver Responses

**Date:** 2026-05-02
**Status:** Accepted

---

## Context

Both `GeminiDriver` and `OpenRouterDriver` parse a JSON response from the AI backend and return a structured result. When the backend degrades — shorter justifications, missing policy citations, risk levels clustering at `'unknown'` — the pipeline continues to function normally: the worker completes, `XACK` fires, the `compliance_events` row is written, and the dashboard shows green. There is no exception to catch and no operational alert to trigger. The degradation is behavioral, not operational.

The specific failure modes of concern are:

- **False negatives** — a genuinely high-risk event receives a `risk_level` of `'low'` or `'unknown'` because the model produced a low-confidence, poorly-grounded response
- **Narrative drift** — responses become progressively shorter and less specific over time as the policy corpus ages or the model's behavior shifts
- **Silent policy citation loss** — the model stops referencing specific regulations, producing generic narratives that satisfy the schema but carry no compliance value

None of these failure modes produce an error. They are only detectable by inspecting the content of the response.

---

## Decision

Score every compliance driver response against a four-signal quality rubric before returning it. The score is logged as structured data on every call. Responses scoring at or below a threshold additionally emit a warning-level log entry as an operational alert hook.

**Rubric signals (1 point each):**

| Signal | Check | Log key |
|--------|-------|---------|
| Policy citation | `policy_refs` is non-empty | `has_policy_refs` |
| Risk level resolved | `risk_level` ≠ `'unknown'` | `has_risk_level` |
| Narrative substance | `strlen(narrative)` ≥ 150 chars | `above_length_min` |
| Driver confidence | `confidence` ≥ 0.6 | `above_confidence` |

`quality_score` = sum of passing signals (0–4).

**Log behaviour:**
- Every call: `Log::info('{Driver}: response quality', $context)` — builds the baseline
- `quality_score` ≤ 1: additionally `Log::warning('{Driver}: low quality score', $context)` — the operational alert hook

**Context payload** logged on both calls: `source_id`, `domain`, all four signal booleans, `narrative_length`, `confidence`, `quality_score`.

**Implementation:** A private `logResponseQuality(array $result, array $data): void` method on each driver, called in `analyze()` between `parseResponse()` and `return`. The return value of `analyze()` and the `ComplianceDriver` interface are unchanged.

**Thresholds as private constants:** `NARRATIVE_LENGTH_MIN = 150` and `QUALITY_WARNING_THRESHOLD = 1` are `private const` on each driver class. They are rubric definitions — fixed across all environments so the measurement baseline is stable — not deployment-time tunables.

---

## Warning threshold rationale

The warning threshold is ≤ 1, not 0. A score of 0 is already covered: it only occurs when `parseResponse()` returns the fallback shape (null narrative, `'unknown'` risk, empty refs, zero confidence), which always coincides with an existing `'unexpected response shape'` warning. Alerting at 0 would be redundant.

A score of 1 catches a more subtle failure mode: a structurally valid response where only one signal passes. The most common pattern is `has_risk_level=true` with everything else failing — the model resolved a risk level but produced no policy grounding, no substantive narrative, and expressed low confidence. This is early-stage degradation, not parse failure, and it has no existing alert.

---

## Consequences

**Positive**

- Every response is now observable. The `info` log on every call builds a baseline against which anomalies are detectable.
- `source_id` and `domain` are included in every log entry, enabling trend analysis by event source and compliance domain.
- The `warning` entries are the first operational signal for behavioral degradation. They can be wired to an alert threshold (e.g. N consecutive `quality_score=0` events) without any code change to the driver.
- The composite score is more useful than any single signal alone: sustained multi-signal failure indicates systemic degradation; isolated single-signal failure indicates a specific, diagnosable problem.

**Negative / Trade-offs**

- Quality scores are not persisted to Postgres alongside the `compliance_events` record. Trend analysis requires querying logs rather than the database. If historical quality data proves valuable for compliance auditing, a schema migration would be needed to promote scores to stored columns.
- The 150-character minimum for narrative substance is a rough heuristic. Real-world calibration may reveal the threshold needs adjustment once baseline data accumulates. Changing it requires a code change and redeployment.
- The four signals weight all dimensions equally. A missing policy citation (`has_policy_refs=false`) and low confidence (`above_confidence=false`) each contribute −1 to the score, despite having different severity implications. A weighted score could be more precise but adds complexity before baseline data justifies it.

---

## Alternatives considered

**Persist quality scores to Postgres**
Add a `quality_score` column to `compliance_events` and write the score alongside the narrative. More queryable and auditable, but requires a migration, a schema decision, and couples the monitoring concern to the data model before there is evidence the scores are worth persisting. Deferred: logging captures the signal now; promotion to a column can follow once the scores prove actionable.

**Surface quality scores on the dashboard**
Display `quality_score` next to each compliance event on the Flags/Events page. Deferred for the same reason: the scores need to run against real traffic before the right display format (badge, sparkline, trend indicator) is clear.

**Alert in application code rather than log-based**
Trigger an in-process action (e.g. re-run the analysis, raise an exception, write a flag to Redis) when quality falls below threshold. Rejected: application-level reactions to quality signals require knowing what the right reaction is, which requires evidence from the baseline. Log-based alerting is the right first step — it captures the signal without committing to a response.
