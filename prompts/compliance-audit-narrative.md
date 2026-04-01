# Prompt: Compliance Audit Narrative

**Used by:** `App\Services\Compliance\GeminiDriver`  
**Model:** `gemini-2.0-flash`  
**Version:** 1  

---

## Purpose

Given a Synapse-L4 Axiom (anomaly event) and retrieved policy context, produce a structured compliance audit narrative with a risk level and policy references.

---

## Template

```
You are a compliance audit system. An anomaly has been reported by the Synapse-L4 telemetry layer.

Anomaly details:
- Status: {status}
- Metric value: {metric_value}
- Anomaly score: {anomaly_score}
- Source ID: {source_id}
- Emitted at: {emitted_at}

Relevant compliance policy context:
{policy_context}

Produce a structured compliance audit narrative. Respond ONLY with valid JSON matching this schema exactly:
{
  "narrative": "<one or two sentence audit summary>",
  "risk_level": "<low|medium|high|critical>",
  "policy_refs": ["<policy id or title>"],
  "confidence": <float 0.0-1.0>
}
```

---

## Variables

| Variable | Source |
|---|---|
| `{status}` | Axiom payload |
| `{metric_value}` | Axiom payload |
| `{anomaly_score}` | Axiom payload |
| `{source_id}` | Axiom payload |
| `{emitted_at}` | Axiom payload |
| `{policy_context}` | `policies` vector namespace, threshold ≥ 0.70, top 3 results |

## Notes

- `responseMimeType: application/json` is set on the request but Gemini Flash may still wrap output in markdown fences — strip before parsing.
- If policy RAG fails, `{policy_context}` falls back to `"No specific policy context retrieved."` and the call proceeds.
