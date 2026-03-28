# ADR-0004: Upstash as Managed Redis and Vector Provider

**Date:** 2026-02-05
**Status:** Accepted

## Context

The pipeline requires two distinct data stores:
1. A Redis instance for Streams, Laravel cache (metrics counters), and the recent transactions feed.
2. A vector database for semantic caching and policy RAG retrieval.

These could be separate providers (e.g. Redis Cloud + Pinecone, or self-hosted Redis + Weaviate). Upstash provides both under one account with a serverless, pay-per-request pricing model and a REST API — meaning both stores are accessible from any HTTP client without a persistent TCP connection.

## Decision

Use Upstash for both:
- **Upstash Redis** — for Redis Streams, Laravel cache, and the recent transactions list.
- **Upstash Vector** — for the semantic cache (`default` namespace) and policy RAG (`policies` namespace).

## Consequences

**Positive:**
- Single account, single billing, one set of credentials to manage.
- Serverless pricing: no charge for idle time — well-suited for a project that may have sparse traffic.
- REST API for Upstash Vector means no persistent connection required; standard Laravel `Http` facade calls work without a native client.
- Both services work identically in local dev and production — no environment-specific config beyond the URL and token.

**Negative:**
- Vendor lock-in to Upstash's REST API shape. Migrating to a self-hosted Redis would require removing the TLS/REST wrappers; migrating the vector store would require re-indexing all vectors.
- REST-based vector queries carry HTTP overhead vs a native TCP client (e.g. Pinecone's SDK). Acceptable at current scale.
- Upstash free tier limits apply: 10,000 vector upserts/day, 200MB storage. These are sufficient for development and demo traffic, but require a paid plan for production volume.
