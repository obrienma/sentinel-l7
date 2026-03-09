# Multitenancy

> **Status: Not implemented — design intent documented.**
> This file exists to capture the plan so architectural decisions made now don't foreclose it later.

## Current State

- Single-tenant. All data belongs to one implicit tenant.
- `users` table has no `tenant_id`.
- Stream consumer uses tenant-scoped `XADD` keys — the groundwork is already in place at the infrastructure layer.
- `routes/web.php` has a `TODO` comment marking where tenant middleware should be added.

## Hard Rules (Apply Now)

- **Never assume one user = one tenant.** Any new data model should include `tenant_id` from the start — it's far cheaper to add a nullable column now than to migrate millions of rows later.
- **Never hardcode a global query.** All future queries on tenant-owned data should go through a scoped repository or trait, not raw `Model::all()`.

## Planned Approach

### 1. Data Model

```
tenants
  id
  name
  slug          (subdomain or identifier)
  plan          (free | pro | enterprise)
  created_at

users
  id
  tenant_id     (FK → tenants.id)
  name
  email
  password
  ...
```

All other domain models (`transactions`, `flags`, `policies`, etc.) get `tenant_id` as well.

### 2. Global Query Scope

A `BelongsToTenant` trait applied to every tenant-scoped model:

```php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if ($tenantId = app('current.tenant.id')) {
                $query->where('tenant_id', $tenantId);
            }
        });
    }
}
```

This ensures `Transaction::all()` automatically filters by the current tenant — no per-query scoping needed.

### 3. Middleware

A `SetTenantContext` middleware resolves the tenant from the authenticated user and binds it to the container:

```php
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->instance('current.tenant.id', auth()->user()->tenant_id);
        return $next($request);
    }
}
```

This middleware slots into the `auth` route group in `routes/web.php` (the TODO is already there).

### 4. Redis Stream Isolation

Stream keys are already namespaced — extend the convention:

```
sentinel:transactions:{tenant_id}    ← per-tenant stream
sentinel:seen:{tenant_id}:{txn_id}   ← per-tenant idempotency keys
```

### 5. Vector Namespace Isolation

Upstash Vector namespaces per tenant for cache and policy isolation:

```
ns: cache-{tenant_id}     ← semantic cache
ns: policies-{tenant_id}  ← policy RAG
```

Or use metadata filtering if the tenant count is low enough to share namespaces.

## Package vs. Roll-Your-Own

**`stancl/tenancy`** — the standard Laravel multitenancy package. Supports single-database and multi-database strategies, automatic tenant resolution from subdomain/path, and queue/job scoping. Consider it if:
- You need subdomain-per-tenant routing
- You have complex queue isolation requirements
- You have 10+ tenants with diverging data isolation needs

**Roll your own** (the approach above) — sufficient if:
- Column-based scoping is enough (shared database, shared tables)
- Tenant resolution is simple (from `auth()->user()->tenant_id`)
- You want full control without a package's conventions

For the current scale, rolling lightweight is the right call.

## Migration Path

When the time comes:

1. Add `tenants` table + migration
2. Add `tenant_id` to `users` + backfill (1 tenant, all existing users)
3. Add `BelongsToTenant` trait to domain models
4. Add `SetTenantContext` middleware to the `auth` route group
5. Update stream key generation to include `tenant_id`
6. Update Vector namespace resolution
7. Seed one tenant record and associate the existing user(s)
