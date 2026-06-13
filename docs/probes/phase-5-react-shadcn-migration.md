---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-5, vite, inertia]
---
`app.js` uses {{c1::React.createElement}} instead of JSX because Blade's
`@vite()` references the file by its literal filename and `.js` files aren't
processed as JSX by Vite without extra config — this avoids renaming the entry
to {{c2::app.jsx}} and reconfiguring the Blade template.

Extra: sentinel-l7 · Phase 5 · Pattern: React.createElement in app.js to Avoid the .jsx Extension Requirement
See: docs/journal.md#phase-5

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-5, vite, inertia]
---
If `@vite(...)` appears before {{c1::@viteReactRefresh}} in `app.blade.php`,
{{c2::React Fast Refresh (HMR)}} stops working — the refresh runtime must be
injected before the app bundle loads, not after.

Extra: sentinel-l7 · Phase 5 · Pattern: @viteReactRefresh Before @vite() in Blade
See: docs/journal.md#phase-5

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-5, shadcn]
---
{{c1::jsconfig.json}} is required even in a non-TypeScript project so the
shadcn CLI can resolve the {{c2::@}} path alias when scaffolding — without it,
`npx shadcn@latest add button` can't determine where `@/components/ui/` maps
to on disk.

Extra: sentinel-l7 · Phase 5 · Pattern: jsconfig.json for shadcn CLI Path Resolution
See: docs/journal.md#phase-5

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-5, tailwind]
---
In Tailwind v4, theme configuration lives in CSS inside an {{c1::@theme
inline}} block in `app.css` — there is no {{c2::tailwind.config.js}}.

Extra: sentinel-l7 · Phase 5 · Pattern: Tailwind v4 — Config-in-CSS, No tailwind.config.js
See: docs/journal.md#phase-5

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-5, inertia]
---
Calling {{c1::createInertiaApp}} twice in `app.js` causes the Inertia app to
{{c2::mount twice}} on the same DOM node — React reconciliation errors and
double-rendering in development.

Extra: sentinel-l7 · Phase 5 · Anti-Pattern Avoided: Duplicate createInertiaApp Call in app.js
See: docs/journal.md#phase-5

---
type: cloze
deck: Rhizome::sentinel-l7
tags: [sentinel-l7, phase-5, shadcn]
---
`npx shadcn@latest add <component>` {{c1::vendors}} source files into
{{c2::resources/js/components/ui/}} — committed to the repo, not
`node_modules` — giving full ownership at the cost of upstream updates
becoming {{c3::manual, opt-in copies}}.

Extra: sentinel-l7 · Phase 5 · Challenge: shadcn Components Are Vendored, Not node_modules Dependencies
See: docs/journal.md#phase-5
