# Prompt: Transaction Compliance Analysis

**Used by:** `App\Services\Compliance\GeminiDriver`, `App\Services\Compliance\OpenRouterDriver` (via `analyzeTransaction()`, called from `App\Services\TransactionProcessorService` on a Tier 2 cache miss)
**Model:** `gemini-2.0-flash` (Gemini), `meta-llama/llama-3.3-8b-instruct:free` default (OpenRouter, overridable via `OPENROUTER_MODEL`)
**Version:** 1
**Template file:** `prompts/transaction-compliance-analysis.txt`

### Changelog
- **v1** (2026-07-03): Initial version. Introduced to close the ADR-0007 drift — Tier 2 (`TransactionProcessorService` cache miss) now calls the AI driver with policy RAG instead of the rule-based `ThreatAnalysisService`, matching the documented three-tier design.

---

## Purpose

Given a transaction and retrieved policy context, produce a structured compliance risk assessment with a risk level and policy references. Shares the same output schema as [`compliance-audit-narrative`](compliance-audit-narrative.md) so both drivers can reuse `parseResponse()` / `logResponseQuality()` unchanged.

---

## Template

See [`transaction-compliance-analysis.txt`](transaction-compliance-analysis.txt) — this is the live template loaded by both drivers at runtime.

---

## Variables

| Variable | Source |
|---|---|
| `{merchant}` | Transaction payload (`merchant` or `merchant_name`) |
| `{amount}` | Transaction payload |
| `{currency}` | Transaction payload |
| `{policy_context}` | `policies` vector namespace, threshold ≥ 0.70, top 3 results (no `domain` filter — transactions don't carry a domain tag) |

## Notes

- `risk_level` is mapped to `isThreat` in `TransactionProcessorService` as `risk_level !== 'low'` — anything above low (medium/high/critical/unknown) is treated as a threat.
- If the AI call throws (quota, timeout, malformed response after retries), `TransactionProcessorService`'s outer catch routes to `ThreatAnalysisService` (Tier 3) — the same fallback used for embedding/vector infra failures.
- If policy RAG fails, `{policy_context}` falls back to `"No specific policy context retrieved."` and the call proceeds in both drivers.
