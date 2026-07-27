# ADR-0033: Reversal of ADR-0020 — WorkOS AuthKit for Sentinel-L7 Multi-Tenant Auth

**Date:** 2026-07-27
**Status:** Accepted

## Context

ADR-0020 (2026-05-07) decided against implementing multi-tenancy or RBAC in
Sentinel-L7, on the grounds that the hiring signal (WorkOS/Auth0 familiarity)
should land in a TypeScript codebase rather than the PHP/Laravel backend. The
designated destination was `rhizo-book`, a TypeScript health-appointment
scheduler, where provider/patient roles were expected to map onto WorkOS
Organizations. Two TODO markers were left in place as an honest record of the
deferred work: a tenant-scoping comment in `routes/web.php`, and
`DashboardController`'s `// TODO: scope all data queries by
auth()->user()->tenant_id when multitenancy lands`.

ADR-0031 (2026-07-16) later added a tenant label to `compliance_events` for
Ledger-L5 billing correlation, and explicitly declined to reopen ADR-0020 —
that work was a data correlation problem, not an auth or isolation problem.
That distinction still holds and is unaffected by this reversal.

Since ADR-0020, two things changed the calculus:

1. **`rhizo-book`'s actual state.** The repo already has a working auth stack
   — NextAuth on the frontend, Passport + JWT on the backend — with a `Role`
   model distinguishing `ProviderProfile`/`PatientProfile`. There is no
   Organization or tenant model. It is not an actively maintained project.
   Implementing WorkOS there would mean replacing a functioning, unrelated
   auth system and building multi-tenancy from zero, not filling an
   identified gap. The existing NextAuth/Passport work already stands as
   evidence of building that class of system in TypeScript.

2. **Sentinel-L7's gap is a better match for what WorkOS actually solves.**
   The dashboard has a live login flow (`AuthController`, per ADR-0014) and a
   flat `User` model with no tenant boundary — exactly the shape of problem
   AuthKit is built to close, with less new-build work than a green-field
   implementation. Sentinel-L7 is also the compliance engine in the suite;
   enterprise buyers of compliance tooling expect SSO/Directory Sync/audit
   export as a matter of course, which is arguably a more coherent narrative
   for AuthKit than a scheduling app.

ADR-0014 (custom minimal auth instead of Breeze) is not reversed by this
decision. Its reasoning — avoiding a second, non-shadcn component system —
still applies to the login page's UI. What changes is the session/identity
backend underneath it, which ADR-0014 did not anticipate replacing.

## Decision

Reverse ADR-0020. Implement WorkOS AuthKit as Sentinel-L7's dashboard
authentication layer, replacing `AuthController`'s hand-rolled
`Auth::attempt()` flow.

- WorkOS Organization maps to a Sentinel-L7 tenant. The AuthKit session
  yields an `organization_id`, stored on a new `tenant_id` column on `User`.
- `DashboardController` and `ComplianceController` queries are scoped by
  `tenant_id`, resolving both outstanding TODOs.
- SSO, Directory Sync, and Audit Log export are **not** part of this phase —
  they remain optional per-tenant add-ons, implemented later only if a real
  tenant asks for them. AuthKit alone (free up to 1M MAU) is sufficient to
  establish the organization boundary.
- `rhizo-book` is untouched by this decision. Its NextAuth/Passport auth
  stands as-is, serving as separate evidence of TypeScript auth work rather
  than a WorkOS integration specifically.

## Alternatives Considered

**Leave ADR-0020 as-is.**
- **Rejected because:** the two TODOs would remain unresolved indefinitely,
  and Sentinel-L7's compliance-domain framing turned out to be a stronger
  narrative fit than ADR-0020 credited at the time.

**Implement in `rhizo-book` per the original ADR-0020 plan.**
- **Rejected because:** would require replacing a working, unrelated auth
  system and building a tenant model from scratch — a larger lift for the
  same hiring signal, in a project that isn't in active use.

## Consequences

**Positive:**
- Closes both outstanding TODOs with a real tenant data model, not a
  placeholder.
- The hiring signal lands in the compliance-domain codebase, a stronger fit
  for security/compliance-adjacent roles than originally assumed.
- Avoids opening scope on a lower-priority project.

**Negative:**
- Introduces WorkOS AuthKit SDK integration and a session-handling change
  that ADR-0014's "minimal surface area, nothing else" premise was
  explicitly designed to avoid needing. ADR-0014 is not reversed, but its
  scope narrows to the login page's UI; the identity backend it assumed is
  superseded here.
- Adds an external dependency and its associated cost model (free to 1M
  MAU, then priced per additional million) to the stack.
- ADR-0031's tenant label passthrough on `compliance_events` remains
  correlation-only and unaffected — this ADR does not touch that pipeline.