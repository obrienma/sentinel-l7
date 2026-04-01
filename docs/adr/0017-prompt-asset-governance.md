# ADR-0017 — Prompt Asset Governance

**Date:** 2026-03-31  
**Status:** Proposed  

---

## Context

Sentinel-L7 and its Synapse-L4 sidecar use LLM prompts as functional components of the compliance pipeline. Currently ~3 prompts exist:

1. `compliance-audit-narrative` — GeminiDriver, Sentinel-L7
2. `synapse-l4-extraction` — Synapse-L4 sidecar (stub)
3. `synapse-l4-judge` — Synapse-L4 sidecar (stub)

At this scale, governance overhead isn't justified. This ADR captures the pattern to adopt when the surface grows or prompts start causing regressions.

---

## Trigger Conditions

Revisit this ADR when any of the following are true:

- Prompt changes cause downstream scoring regressions (a prompt breaks a compliance verdict)
- More than one person is editing prompts
- The prompt count exceeds ~8–10 across both systems
- Synapse-L4 is in active development and the extraction/judge prompts are changing frequently

---

## Proposed Decision (not yet adopted)

Treat prompts as versioned assets:

- **Store** prompt templates as files in `prompts/` (already started)
- **Version** them in git — prompt changes show in diffs alongside the code that uses them
- **Load at runtime** — services read prompt files rather than inlining strings, so changes don't require redeployment
- **Eval suite** — a dedicated test group (`--group=prompts`) runs prompt outputs against a fixture set of known inputs and expected risk levels; this becomes the CI gate for prompt changes

## Consequences (fill in when adopted)

- [ ] `GeminiDriver` refactored to load prompt from `prompts/compliance-audit-narrative.md`
- [ ] Prompt eval fixtures defined
- [ ] CI runs `--group=prompts` on prompt file changes
