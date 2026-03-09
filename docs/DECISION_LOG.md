# Sentinel-L7 — Decision Log

Architectural and tooling decisions, with rationale and trade-offs.
Maintained as we build so future contributors (and future us) understand the *why*.

---

## 2026-03-09

### Frontend Framework: Vue 3 → React 19
**Decision:** Migrate from Vue 3 + `@inertiajs/vue3` to React 19 + `@inertiajs/react`.

**Rationale:** Developer preference. The Inertia protocol is framework-agnostic on the frontend, so the migration cost was low (one Vue file existed at the time of migration). React's ecosystem, component model, and JSX fit the team's workflow better going forward.

**Trade-offs:**
- Vue 3's Composition API and `<script setup>` are excellent; this was not a quality decision.
- React's `useForm` adapter wraps values under `form.data.fieldName` vs Vue's direct `form.fieldName` — minor API difference to remember.

**Files changed:** `vite.config.js`, `resources/js/app.js`, `resources/js/Pages/Home.vue` → `Home.jsx`, `resources/views/app.blade.php`.

---

### UI Component Library: shadcn/ui
**Decision:** Use shadcn/ui (Radix UI primitives + Tailwind CSS) as the component library.

**Rationale:** shadcn/ui components are copied into the codebase rather than installed as a black-box package. This means full ownership — any component can be modified without fighting a library API. Radix UI handles accessibility (keyboard nav, ARIA, focus management) correctly out of the box. The "New York" style with slate base color fits the existing dark aesthetic.

**Trade-offs:**
- More files in the repo vs a traditional npm package. Accepted — the control is worth it.
- Components must be manually updated when shadcn releases changes. Low risk for a dashboard.
- Requires `jsconfig.json` for the shadcn CLI to resolve the `@` path alias in a non-TypeScript project.

**Components installed:** `card`, `badge`, `button`, `table` (initial set — add with `npx shadcn@latest add <component>`).

---

### CSS: Tailwind v4 (kept)
**Decision:** Keep Tailwind CSS v4 rather than downgrading to v3.

**Rationale:** Already installed and working. v4 has better performance, native CSS cascade layers, and first-class Vite plugin support. shadcn/ui has explicit v4 support.

**Key v4 differences to remember:**
- No `tailwind.config.js` — configuration lives in CSS via `@theme` and `@theme inline`.
- `@import 'tailwindcss'` replaces the three `@tailwind` directives.
- `@source` directives replace the `content[]` array.
- Opacity utilities: `bg-blue-500/50` instead of `bg-opacity-50`.
- shadcn CSS variables are mapped to Tailwind color utilities via `@theme inline` in `app.css`.

**Dark palette:** Set as the `:root` default (not behind a `.dark` class) since the entire app is dark-themed.

---

### Authentication: Custom minimal auth (not Laravel Breeze)
**Decision:** Hand-rolled auth (AuthController + Login.jsx) instead of Laravel Breeze.

**Rationale:** Breeze is well-designed for public multi-user applications, but generates significant scaffolding that this project doesn't need: user registration, email verification, password reset flow, profile management page, and its own component set. More importantly, Breeze's React scaffolding generates components that don't use shadcn/ui, which would create two parallel component systems. For a controlled internal dashboard with a known user set, none of that is necessary.

**What we have instead:**
- `AuthController` — login, logout. That's it.
- `Login.jsx` — single page using shadcn Card + Button.
- Standard Laravel `Auth::attempt()` + session regeneration on login, invalidation on logout.
- `redirect()->intended()` — preserves the originally-requested URL through the login redirect.

**What we don't have (intentionally, for now):**
- User registration (users are seeded/created via tinker or a future admin panel)
- Password reset (add when needed)
- Email verification (add when needed)

**If this changes:** `composer require laravel/breeze` + `php artisan breeze:install react` remains an option, but would require reconciling its component output with shadcn/ui.

---

### Multitenancy: Not implemented — design intent documented
**Decision:** No multitenancy yet. Architecture must not foreclose it.

**Current state:**
- `users` table exists with standard Laravel columns.
- Stream consumer already uses tenant-scoped `XADD` (groundwork is laid in the backend).

**Future approach (when needed):**
1. Add `tenants` table + `tenant_id` FK on `users`.
2. Add a `BelongsToTenant` trait with a global scope that automatically filters queries.
3. Add a `SetTenantContext` middleware to the `auth` route group — this is where the TODO comment in `routes/web.php` points.
4. Consider `stancl/tenancy` only if single-database multi-tenant routing becomes complex. For now, column-based scoping is sufficient.

**Hard rule:** No hardcoded assumption that one user = one tenant. All future data models should include `tenant_id` from the start.

---

### Inertia.js: Entry file uses `React.createElement` not JSX
**Decision:** `resources/js/app.js` uses `React.createElement(App, props)` instead of `<App {...props} />`.

**Rationale:** The Vite React plugin (`@vitejs/plugin-react`) only applies JSX transformation to files with `.jsx` extension by default. The Laravel Vite plugin expects the entry point to be `app.js` (referenced in `app.blade.php` via `@vite`). Rather than rename the entry file and update the blade template, `React.createElement` is used for the single JSX expression in `app.js`. All other files use `.jsx` and JSX syntax normally.

---
