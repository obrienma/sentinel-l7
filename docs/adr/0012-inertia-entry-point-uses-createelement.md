# ADR-0012: Inertia Entry Point Uses `React.createElement` Instead of JSX

**Date:** 2026-03-09
**Status:** Accepted

## Context

The Vite React plugin (`@vitejs/plugin-react`) applies JSX transformation only to files with a `.jsx` extension by default. The Laravel Vite plugin expects the entry point to be `app.js` and references it as `@vite(['resources/js/app.js', ...])` in `app.blade.php`. Renaming it to `app.jsx` would require updating the blade template and potentially the Vite config.

The entry file contains a single JSX expression: `<App {...props} />` inside the `createInertiaApp` resolver.

## Decision

Keep the entry file as `app.js` and replace the one JSX expression with `React.createElement(App, props)`. All other files use `.jsx` extension and JSX syntax normally.

## Consequences

**Positive:**
- No changes to `app.blade.php` or Vite config required.
- The `React.createElement` call is in one place and unlikely to be modified.

**Negative:**
- A future developer unfamiliar with this context may be confused by the inconsistency — one file using `createElement` while all others use JSX. The reason is non-obvious without this ADR.
- If the entry file ever needs more complex JSX (unlikely), it would need to be renamed to `.jsx` at that point.
