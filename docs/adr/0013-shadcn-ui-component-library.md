# ADR-0013: shadcn/ui as Component Library

**Date:** 2026-03-09
**Status:** Accepted

## Context

The dashboard needs a set of accessible, styled UI components (cards, tables, badges, buttons). Options included traditional npm packages (MUI, Chakra UI, Mantine) and shadcn/ui, which takes a different approach: components are copied into the codebase rather than installed as a package dependency.

## Decision

Use shadcn/ui with the "New York" style and slate base color. Components are owned in `resources/js/components/ui/` and added individually via `npx shadcn@latest add <component>`. The dark palette is set as the `:root` default in `app.css` (not behind a `.dark` class) since the app is dark-themed throughout.

Tailwind CSS v4 is used, which shadcn explicitly supports. Configuration lives in CSS (`@theme inline`) rather than `tailwind.config.js`. A `jsconfig.json` is required for the shadcn CLI to resolve the `@` path alias in a non-TypeScript project.

## Consequences

**Positive:**
- Full ownership of component code — any component can be modified without fighting a library API or waiting for an upstream fix.
- Radix UI primitives (used under the hood) handle accessibility correctly: keyboard navigation, ARIA attributes, focus management.
- No version conflicts: the component code is in the repo and doesn't drift unless deliberately updated.

**Negative:**
- More files in the repo compared to a traditional npm dependency. Accepted — control outweighs the overhead for a dashboard.
- shadcn component updates are manual. For a dashboard with a small component set this is low-risk.
- Requires `jsconfig.json` for the shadcn CLI's `@` alias resolution — this is a non-obvious setup step for new contributors.
