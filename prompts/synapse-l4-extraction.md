# Prompt: Synapse-L4 Telemetry Extraction

**Used by:** Synapse-L4 sidecar (`src/extractors/llm.py`) — not yet implemented  
**Model:** TBD  
**Version:** stub  

---

## Purpose

Given raw telemetry from EventHorizon, extract a structured representation suitable for the Judge pass.

---

## Template (stub)

```
You are a telemetry extraction system. Given the following raw event, extract the key fields.

Raw event:
{raw_event}

Respond with valid JSON:
{
  "status": "<ok|warning|critical>",
  "metric_value": <float>,
  "source_id": "<string>"
}
```

---

## Notes

- Prompt not yet finalized — pending Synapse-L4 sidecar implementation.
