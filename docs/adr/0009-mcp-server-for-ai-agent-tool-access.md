# ADR-0009: MCP Server Exposing Compliance Pipeline as AI Agent Tools

**Date:** 2026-03-23
**Status:** Accepted

## Context

The compliance pipeline is useful not just as a dashboard but as a capability that AI agents (Claude Desktop, Cursor, etc.) could invoke directly — to analyze a transaction on demand, search policies, or inspect the recent transaction feed. Without an integration point, agents can only receive static prompt descriptions of the system.

Model Context Protocol (MCP) is an emerging standard for exposing tools to AI agents over JSON-RPC. Laravel's HTTP layer makes it straightforward to add an MCP endpoint alongside the existing web routes.

## Decision

Add an MCP server at `POST /mcp` (registered in `routes/ai.php`). The server exposes three tools:

| Tool | What it does |
|------|-------------|
| `analyze_transaction` | Runs a transaction through the full three-tier compliance pipeline |
| `search_policies` | Semantic search over the policy knowledge base (Upstash ns:policies, threshold ≥ 0.70) |
| `get_recent_transactions` | Returns the live recent transactions feed from Redis |

The server is implemented in `App\Mcp\Servers\SentinelServer` and delegates to the same services used by the dashboard — no parallel implementation.

## Consequences

**Positive:**
- Any MCP-compatible client can use the compliance pipeline as a tool without understanding the internals.
- Reuses existing services (`TransactionProcessorService`, `EmbeddingService`, `VectorCacheService`) — no logic duplication.
- The MCP endpoint is additive — it does not change how the dashboard or worker operate.

**Negative:**
- The `/mcp` endpoint is unauthenticated in the current implementation — it relies on the obscurity of the URL and the assumption that the app runs behind a load balancer. Authentication (API key or OAuth) should be added before any production exposure.
- MCP is a young standard; the protocol may evolve in ways that require updates to the server implementation.
