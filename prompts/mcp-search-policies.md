# Prompt: MCP Tool — SearchPolicies

**Used by:** `App\Mcp\Tools\SearchPolicies` (`$description` property)  
**Seen by:** MCP-compatible clients (Claude Desktop, Cursor, etc.) when selecting tools  
**Version:** 1

---

## Live Description

```
Search the compliance policy knowledge base for relevant regulatory rules.
Use this to retrieve AML, BSA, HIPAA, or GDPR policy context by semantic similarity.
Returns scored policy chunks with their text and metadata. Threshold: 0.70.
```

---

## Notes

- Listing the regulation acronyms (AML, BSA, HIPAA, GDPR) guides the agent toward compliance-domain queries rather than generic searches.
- "Semantic similarity" signals that natural-language queries work better than keyword searches — the agent should send descriptive questions, not boolean terms.
- Stating the threshold (0.70) lets an agent reason about confidence: a low result count means the query was ambiguous or the corpus doesn't cover the topic.
- The 0.70 threshold is intentionally lower than the transaction cache threshold (0.95). Policy retrieval is topical matching, not near-duplicate detection — a compliance question and the policy text that answers it will naturally embed at lower similarity than two near-identical transactions.
- Intended use in the multi-hop pattern: agent calls `search_policies` first to retrieve context, then calls `analyze_transaction` with that understanding rather than relying solely on what Sentinel injects into the Gemini prompt.
- Policy corpus is populated by `php artisan sentinel:ingest` from `policies/*.md`.
