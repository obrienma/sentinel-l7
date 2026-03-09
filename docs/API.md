# API

> **Status: Not implemented — intent documented.**
> Currently all routes use the Inertia protocol (server-driven SPA). A public-facing REST API does not exist yet.

## Intent

If Sentinel-L7 exposes compliance analysis to external clients (webhooks, third-party integrations, mobile apps), a proper REST or GraphQL API will be needed alongside the Inertia web routes.

## Planned Approach

### Route Separation

Laravel supports coexisting Inertia and API routes cleanly:

```
routes/web.php     ← Inertia routes (current)
routes/api.php     ← REST API (future)
```

API routes are automatically prefixed with `/api` and use the `api` middleware group (stateless, no session/cookie auth).

### Authentication

API clients should use **Laravel Sanctum** token authentication — not session cookies. Sanctum is the standard for Laravel SPAs and API tokens.

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
```

Inertia (web) routes continue using session auth. API routes use `auth:sanctum` middleware.

### Versioning

Prefix routes with a version:

```
/api/v1/transactions
/api/v1/flags
/api/v1/metrics
```

This preserves backward compatibility when breaking changes are needed.

### Planned Endpoints (Speculative)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/v1/transactions` | Submit a transaction for compliance analysis |
| `GET` | `/api/v1/transactions/{id}` | Retrieve analysis result |
| `GET` | `/api/v1/metrics` | Stream processing metrics |
| `GET` | `/api/v1/flags` | List flagged transactions |
| `GET` | `/api/v1/policies` | List active compliance policies |
| `POST` | `/api/v1/policies` | Create/update a policy |

### Rate Limiting

Use Laravel's built-in rate limiting on API routes:

```php
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // API routes
});
```

Configure limits in `app/Providers/RouteServiceProvider.php`.

### Response Format

Standard JSON envelope:

```json
{
  "data": { ... },
  "meta": { "timestamp": "...", "version": "v1" }
}
```

Errors follow Laravel's default validation response shape (RFC 7807 compatible).

## Multitenancy Note

API clients will be tenant-scoped via the API token. The token → user → tenant_id chain applies. See [MULTITENANCY.md](MULTITENANCY.md).
