# Prompt: MCP Tool — AnalyzeTransaction

**Used by:** `App\Mcp\Tools\AnalyzeTransaction` (`$description` property)  
**Seen by:** MCP-compatible clients (Claude Desktop, Cursor, etc.) when selecting tools  
**Version:** 1

---

## Live Description

```
Analyze a financial transaction for compliance violations (AML, HIPAA, GDPR, BSA).
Returns risk level, threat flag, compliance message, and pipeline source (cache_hit / cache_miss / fallback).
Near-identical transactions are served from the semantic vector cache without re-running analysis.
```

---

## Notes

- The description is the primary signal an AI agent uses to decide whether to call this tool.
- Mentioning `cache_hit / cache_miss / fallback` explicitly allows an agent to reason about pipeline cost and freshness when choosing whether to call again.
- "Near-identical transactions are served from the semantic vector cache" sets the expectation that repeat calls with similar inputs are cheap — an agent can batch-analyze without worrying about quota burn.
- Calls to this tool pass `observe: false` to `TransactionProcessorService` — they do not increment dashboard metrics or the recent-transactions feed.
