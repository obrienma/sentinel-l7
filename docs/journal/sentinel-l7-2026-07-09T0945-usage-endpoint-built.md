---
id: sentinel-l7-2026-07-09T0945-usage-endpoint-built
repo: sentinel-l7
title: "GET /usage Endpoint Built (ADR-0029)"
date: 2026-07-09
phase: 22
tags: [usage-endpoint, api-key-auth, cursor-pagination, safety-lag-window]
files: [app/Http/Controllers/UsageController.php, app/Http/Middleware/VerifyLedgerApiKey.php, bootstrap/app.php, routes/web.php, config/sentinel.php, config/services.php, .env.example, tests/Feature/UsageEndpointTest.php, README.md]
---

# GET /usage Endpoint Built (ADR-0029)

Implements ADR-0029: dual per-pipeline cursor pull, config-backed page
size and safety-lag window, exact row shape per the ADR. Two real gaps in
the ADR itself — it specified the data contract but not how the endpoint
would be secured or bounded — were resolved before writing any code,
not discovered afterward: no machine-to-machine auth pattern exists
anywhere in this app (every route relies on session `auth`; the one
other externally-reachable endpoint, `/mcp`, has zero auth and its own
"add auth:sanctum when needed" TODO comment), and the one existing
chunked/paginated endpoint (`/compliance/export`, 500-row chunks)
hardcodes its limit rather than sourcing it from config, so it wasn't a
compliant precedent to copy either.

### Decision: Shared API-Key Header, Not Sanctum
Chose a lightweight `X-Ledger-Api-Key` header checked against a
config-backed secret (`services.ledger_l5.api_key`) over wiring up
Sanctum, which is a dependency-present-but-fully-unconfigured option
(no migration, no `HasApiTokens`, nothing). Proportionate to one known
external consumer; revisit toward Sanctum if a second external consumer
or per-caller token revocation is ever needed.

### Decision: Server-Side Page Size, No New Query Param
`sentinel.usage.page_size` (default 500, config-backed per this
project's no-hardcoded-thresholds rule) caps each pipeline's rows per
call without changing ADR-0029's response shape — `next_cursor` simply
reflects whatever was actually returned, so Ledger-L5 naturally
paginates across multiple calls if there's more than a page's worth of
new rows. Closes the unbounded-first-pull risk ADR-0029 didn't address,
without adding a `limit` parameter to the contract.

### Pattern: Loud Failure Over Silent Success for Caller Misconfiguration
Per explicit direction: HTTPS enforced outside `local`/`testing`
(`trustProxies(at: '*')` already in `bootstrap/app.php` means
`isSecure()` correctly reflects `X-Forwarded-Proto` behind Railway's
load balancer), 401 (not an empty 200) on a missing or wrong key, and
the presented key is never logged — only whether it matched. A caller
misconfiguration reads as "unauthenticated," not "no new usage yet."

### Challenges
`withServerVariables(['HTTPS' => 'on'])` on a relative-path test request
did **not** make `$request->isSecure()` true — Symfony's
`Request::create()` derives the `HTTPS` server var from the request
URL's own scheme, and for a relative URI (`'/usage'`, resolved against
the test client's default `http://localhost` base) that URL-derived
value won over the manually-set server variable. Fixed by passing an
absolute `https://localhost/usage` URL directly instead of faking the
server variable — the same class of "first instinct doesn't match the
framework's actual precedence rules" gotcha as Phase 19's
`firstOrCreate()`-can't-trigger-its-own-catch-block finding, diagnosed
the same way: read what the underlying method actually does before
concluding the test technique should work.

Also: `created_at` isn't `$fillable` on either `Transaction` or
`ComplianceEvent` (Eloquent-managed timestamp), so passing it through
`Model::create()`'s `$overrides` array — the obvious way to backdate a
row past the safety-lag window in a test fixture — silently no-ops;
Eloquent stamps "now" regardless. Fixed by setting the attribute via
direct property assignment (`$model->created_at = ...; $model->save();`)
after creation, which bypasses mass-assignment protection as intended.
Centralized into the `usageTxn()`/`usageEvent()` test helpers (default
120s backdated, override via `secondsAgo: 0` to test the lag window
itself) rather than repeating the fix at every call site.

Verified: 16 new `UsageEndpointTest` cases (auth: missing/wrong/correct
key, key never echoed back, HTTPS enforced/allowed in production;
response shape for both row types, `updated_at` correctly absent;
cursor filtering per-pipeline and independently; safety-lag window
excludes/includes correctly; page-size cap; `next_cursor` reflects max
id returned or stays unchanged when a pipeline has no new rows). Full
suite: 348/348 passing. Pint clean on all new files (one pre-existing
`concat_space` fix applied to the new test file itself; the many other
files Pint flags repo-wide are pre-existing and out of scope here).
