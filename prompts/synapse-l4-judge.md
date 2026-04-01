# Prompt: Synapse-L4 Anomaly Judge

**Used by:** Synapse-L4 sidecar (`src/judges/llm.py`) — not yet implemented  
**Model:** TBD  
**Version:** stub  

---

## Purpose

Given extracted telemetry fields, produce an `anomaly_score` (0.0–1.0) that Sentinel-L7 uses to route Axioms to AI analysis.

---

## Template (stub)

```
You are an anomaly detection judge. Given the following telemetry reading, assign an anomaly score.

Telemetry:
- Status: {status}
- Metric value: {metric_value}
- Source: {source_id}

Respond with valid JSON:
{
  "anomaly_score": <float 0.0-1.0>,
  "rationale": "<one sentence>"
}
```

---

## Notes

- Score > 0.8 triggers AI audit narrative in Sentinel-L7 (see `AXIOM_AUDIT_THRESHOLD`).
- Prompt not yet finalized — pending Synapse-L4 sidecar implementation.
