# ADR-0020: Multi-Tenancy and RBAC Deferred — Not Implemented in Sentinel-L7

**Date:** 2026-05-07
**Status:** Accepted

## Context

The Sentinel-L7 CLAUDE.md TODO list includes multi-tenancy as a forward work item: tenant-scoped middleware on the `auth` route group and tenant-prefixed Redis stream keys. Two placeholder comments exist in the codebase (`routes/web.php` and `DashboardController`) marking where tenant scoping would be wired in.

The question was whether to implement multi-tenancy using a managed auth platform (WorkOS or Auth0), which both offer Organizations, RBAC, and SSO as first-class primitives.

Sentinel-L7's primary purpose is as a portfolio piece. The target audience is employers hiring for TypeScript roles — specifically companies that use WorkOS or Auth0 in their stack. The backend is PHP/Laravel.

## Decision

Do not implement multi-tenancy or RBAC in Sentinel-L7. Implement WorkOS multi-tenant auth and RBAC in an existing TypeScript project instead.

**Preferred project:** `rhizo-book` — a TypeScript health appointment scheduler with provider and patient roles. The existing role distinction maps naturally to WorkOS Organizations (clinic or practice) with role-differentiated memberships (provider vs. patient). Healthcare scheduling with enterprise-grade auth is a coherent portfolio story.

**Preferred platform:** WorkOS over Auth0. WorkOS is purpose-built for B2B multi-tenant SaaS; its `@workos-inc/authkit-nextjs` SDK is tightly scoped to this use case and has cleaner DX. Auth0 is broader and older; WorkOS signals stronger awareness of what modern SaaS companies are building on.

## Alternatives Considered

**Separate process instances per client** (one web + worker + reclaimer per tenant):
- **Pros:** Rock-solid isolation, independent scaling, one tenant's failure is contained.
- **Cons:** Operational overhead — 3N processes instead of 3. DevOps complexity (provisioning, monitoring, cleanup). Resource waste for small clients.
- **Rejected because:** For a portfolio piece, this adds no value and would make the system harder to understand. For a real product, this tradeoff would only make sense at scale and with explicit compliance requirements; most SaaS platforms operate as shared infrastructure with tenant scoping because the operational burden doesn't justify the isolation benefits until you have the revenue and regulatory drivers to support it.

## Consequences

**Positive:**
- The hiring signal (WorkOS/Auth0 familiarity) lands in a TypeScript codebase, directly relevant to the target roles.
- Sentinel-L7 stays focused on its core strengths: AI-driven compliance pipeline, Redis Streams, vector semantic caching, and policy RAG.
- No premature architectural complexity added to the Laravel backend for a feature no real user needs.

**Negative:**
- The `routes/web.php` and `DashboardController` TODO comments remain unresolved. They are kept as honest markers of known scope, not removed.
- If Sentinel-L7 ever pivots toward a real multi-tenant product, the absence of a tenant data model would require additive migration work.
