---
id: sentinel-l7-2026-03-09T1317-react-shadcn-migration
repo: sentinel-l7
title: "Vue 3 → React 19 + shadcn/ui Migration"
date: 2026-03-09
phase: 5
tags: [react, inertia, shadcn, tailwind-v4, vite]
files: [.claude/settings.json, README.md, components.json, jsconfig.json, package-lock.json, package.json, resources/css/app.css, resources/js/Pages/Home.jsx, resources/js/Pages/Home.vue, resources/js/app.js, resources/js/components/ui/badge.jsx, resources/js/components/ui/button.jsx, resources/js/components/ui/card.jsx, resources/js/components/ui/table.jsx, resources/js/lib/utils.js, resources/views/app.blade.php, vite.config.js]
---

# Vue 3 → React 19 + shadcn/ui Migration

Commit: `4b3a1cd`

Replaced Vue 3 + `@inertiajs/vue3` with React 19 + `@inertiajs/react` +
shadcn/ui (New York style, slate base). Migrated `Home.vue` → `Home.jsx`.
Configured Vite, `jsconfig.json`, Tailwind v4, and dark-palette defaults.

### Pattern: `React.createElement` in `app.js` to Avoid the `.jsx` Extension Requirement
The Inertia entry point uses `React.createElement(App, props)` instead of JSX.
Blade's `@vite()` directive references `app.js` by its literal filename, and
`.js` files aren't processed as JSX by Vite without extra config. Using
`createElement` in the entry point avoids renaming it to `app.jsx` and
reconfiguring the Blade template. All page components use JSX normally.

### Pattern: `@viteReactRefresh` Before `@vite()` in Blade
React Fast Refresh (HMR) requires its runtime injected before the application
bundle. If `@vite(...)` appears first in `app.blade.php`, Fast Refresh stops
working — the refresh runtime must load before the app bundle, not after.

### Pattern: `jsconfig.json` for shadcn CLI Path Resolution
The shadcn CLI reads `jsconfig.json` (or `tsconfig.json`) to resolve the `@`
path alias when scaffolding component files. Without it, `npx shadcn@latest
add button` can't determine where `@/components/ui/` maps to on disk and
fails silently or scaffolds to the wrong path — required even though this is a
non-TypeScript project.

### Pattern: Tailwind v4 — Config-in-CSS, No `tailwind.config.js`
Tailwind v4 reads theme configuration from an `@theme inline { ... }` block in
`app.css`; there is no `tailwind.config.js`. The dark palette is set directly
on `:root` (not behind a `.dark` class), so dark mode is always active with no
JS toggle.

### Anti-Pattern Avoided: Duplicate `createInertiaApp` Call in `app.js`
A second `createInertiaApp` block was left in `app.js` as a copy-paste
remnant from the Vue version. The symptom: the Inertia app mounts twice on the
same DOM node, producing React reconciliation errors and double-rendering in
development. Removed before merging.

### Challenge: shadcn Components Are Vendored, Not `node_modules` Dependencies
`npx shadcn@latest add <component>` copies source files into
`resources/js/components/ui/` as first-party files committed to the repo —
not into `node_modules`. This is vendoring: a `git status` after adding a
component shows new tracked source files, not just a `package.json` bump,
which is surprising the first time. The trade-off is full in-repo ownership
(customize freely) against upstream shadcn updates becoming manual, opt-in
copies rather than a version bump.

### Decision: New York Style, Slate Base, Dark-Always Palette
New York style uses tighter padding and a border-radius closer to a product
aesthetic than shadcn's default style. Slate was chosen over zinc/gray for its
cooler tone. Dark-always (palette on `:root`, not gated behind `.dark`) avoids
building a theme-toggle mechanism that wasn't planned.
