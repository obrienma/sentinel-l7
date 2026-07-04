# Prompt: Compliance Audit Narrative

**Used by:** `App\Services\Compliance\GeminiDriver`, `App\Services\Compliance\OpenRouterDriver`, `App\Services\Compliance\OllamaDriver`  
**Model:** `gemini-2.0-flash` (Gemini), `meta-llama/llama-3.3-8b-instruct:free` default (OpenRouter, overridable via `OPENROUTER_MODEL`), `qwen3.5:9b-q4_K_M` 32k-context tag default (Ollama, overridable via `OLLAMA_CHAT_MODEL`)  
**Version:** 4  
**Template file:** `prompts/compliance-audit-narrative.txt`

### Changelog
- **v4** (2026-07-04): Extended to `OllamaDriver` (ADR-0027). No prompt text change — only the driver roster using this template grew.
- **v3** (2026-04-01): Extracted prompt text to `compliance-audit-narrative.txt`. Both drivers now load from file via `file_get_contents(base_path(...))` + `strtr()` substitution. No prompt text change.
- **v2** (2026-04-01): Extended to `OpenRouterDriver`. Added note on JSON enforcement difference between backends.
- **v1**: Initial version for `GeminiDriver`.

---

## Purpose

Given a Synapse-L4 Axiom (anomaly event) and retrieved policy context, produce a structured compliance audit narrative with a risk level and policy references.

---

## Template

See [`compliance-audit-narrative.txt`](compliance-audit-narrative.txt) — this is the live template loaded by both drivers at runtime.

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

- **GeminiDriver:** `responseMimeType: application/json` is set on the request, but Gemini Flash may still wrap output in markdown fences — strip before parsing.
- **OpenRouterDriver:** no structured-output mode available; JSON compliance is prompt-enforced only. `parseResponse()` strips fences and falls back to `{narrative: null, risk_level: "unknown"}` if the model ignores the instruction.
- **OllamaDriver:** sends `format: "json"` (forces valid JSON syntax, not schema adherence) and `think: false` (qwen3.5 is a hybrid reasoning model — without this it emits a verbose `message.thinking` trace before answering, ~20x slower, with no effect on `message.content`). A locally-run quantized model is less reliable at strict schema adherence than Gemini's native structured-output mode; `parseResponse()`'s existing fallback covers malformed shape.
- If policy RAG fails, `{policy_context}` falls back to `"No specific policy context retrieved."` and the call proceeds in all three drivers.
