# ADR-0011: Frontend Framework — Vue 3 → React 19

**Date:** 2026-03-09
**Status:** Accepted

## Context

The initial frontend was built with Vue 3 and `@inertiajs/vue3`. Inertia.js is framework-agnostic on the frontend — it connects to the Laravel backend via the Inertia protocol regardless of which frontend framework renders the pages. The migration cost was therefore low: only one Vue file existed at the time.

## Decision

Migrate to React 19 with `@inertiajs/react`. JSX is used throughout, except the Vite entry point `app.js` which uses `React.createElement` to avoid needing a `.jsx` extension on the file referenced by `@vite()` in the blade template (see ADR-0012).

## Consequences

**Positive:**
- React's component model and JSX fit the team's existing workflow better.
- React 19's improvements (concurrent features, improved server component support) are available for future use.
- The broader React ecosystem (tooling, libraries, community resources) is more relevant to the team's context.

**Negative:**
- Vue 3's Composition API and `<script setup>` are excellent; this was a preference decision, not a quality one.
- Inertia's `useForm` adapter differs between frameworks: Vue exposes fields directly on the form object (`form.fieldName`); React wraps them under `form.data.fieldName`. Small but worth knowing when porting form patterns.
