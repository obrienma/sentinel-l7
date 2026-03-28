# ADR-0014: Custom Minimal Auth Instead of Laravel Breeze

**Date:** 2026-03-09
**Status:** Accepted

## Context

Laravel Breeze is the standard starter kit for authentication scaffolding. It generates login, registration, email verification, password reset, and profile management — a complete auth system. However, Sentinel-L7 is a controlled internal dashboard with a known, small user set seeded via `tinker` or migrations.

Breeze's React scaffolding also generates its own component set that does not use shadcn/ui, which would introduce a second parallel component system alongside the intentionally chosen shadcn components.

## Decision

Hand-roll a minimal auth layer:
- `AuthController` — `login()` and `logout()` only.
- `Login.jsx` — single page using shadcn `Card` and `Button`.
- Standard `Auth::attempt()` + `session()->regenerate()` on login, `invalidate()` + `regenerateToken()` on logout.
- `redirect()->intended()` to preserve the originally-requested URL through the login redirect.

Registration, email verification, password reset, and profile management are intentionally absent.

## Consequences

**Positive:**
- No component system conflict — everything uses shadcn/ui.
- Minimal surface area: the auth system does exactly what is needed and nothing else.
- Easy to understand: two controller methods, one page component.

**Negative:**
- No self-service password reset. Users must be managed via `php artisan tinker` or a future admin panel.
- If the app ever needs public user registration or email verification, Breeze remains an option but reconciling its output with the existing shadcn component set would require manual work.
