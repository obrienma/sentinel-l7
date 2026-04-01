# Prompt: MCP Tool — GetRecentTransactions

**Used by:** `App\Mcp\Tools\GetRecentTransactions` (`$description` property)  
**Seen by:** MCP-compatible clients (Claude Desktop, Cursor, etc.) when selecting tools  
**Version:** 1

---

## Live Description

```
Retrieve the most recent transactions processed by the Sentinel L7 compliance pipeline.
Returns entries from the live feed, newest first, including threat status, source (cache_hit / cache_miss / fallback), and elapsed processing time.
```

---

## Notes

- "Newest first" is important to state — an agent looking for a recently submitted transaction knows to look at the top of the results.
- Exposing `source` (cache_hit / cache_miss / fallback) lets an agent reason about pipeline health: a high fallback rate means the embedding or vector services are degraded.
- This tool reads from `sentinel:recent_transactions` (a Redis list capped at 50 entries). It reflects the live feed, not the full `compliance_events` Postgres audit table — it will not contain historical data beyond the last 50 transactions.
- MCP tool calls do NOT write to this feed (`observe: false` is set on `AnalyzeTransaction`) — so the feed only reflects real pipeline traffic, not agent queries.
