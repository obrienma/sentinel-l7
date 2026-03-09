# Frontend

> **Status: In progress — dashboard being built incrementally.**
> This file will grow as the frontend matures. Document conventions here as they are established.

## Stack

| Tool | Version | Role |
|------|---------|------|
| React | 19 | UI layer |
| Inertia.js | 2.x | Server-driven SPA bridge (no separate API) |
| shadcn/ui | latest | Component library (owned in codebase) |
| Tailwind CSS | v4 | Utility styling |
| Vite | 7.x | Build tool + HMR |
| lucide-react | latest | Icons |

## How Inertia Works (Key Mental Model)

There is no separate REST API. Laravel controllers return `Inertia::render('PageName', $props)` — Inertia handles the SPA routing and passes `$props` directly as React component function arguments.

```php
// Controller
return Inertia::render('Dashboard', ['user' => [...], 'stats' => [...]]);
```

```jsx
// Dashboard.jsx — props arrive as function arguments
export default function Dashboard({ user, stats }) { ... }
```

Navigation uses Inertia's `<Link>` component (not `<a>`) or `router.visit()` / `router.post()` for programmatic navigation.

## Directory Structure

```
resources/js/
├── app.js                    ← Inertia bootstrap (no JSX — uses React.createElement)
├── bootstrap.js              ← Axios config
├── lib/
│   └── utils.js              ← cn() helper (clsx + tailwind-merge)
├── components/
│   ├── ui/                   ← shadcn/ui components (owned, editable)
│   │   ├── button.jsx
│   │   ├── card.jsx
│   │   ├── badge.jsx
│   │   └── table.jsx
│   └── ...                   ← shared app-level components (StatCard, etc.)
└── Pages/
    ├── Home.jsx              ← public landing page
    ├── Login.jsx             ← auth
    └── Dashboard.jsx         ← main dashboard (growing)
```

## Conventions

### Components

- **Co-locate small components** in the same file as the page that uses them. Extract to `components/` only when reused across 2+ pages or when the file gets unwieldy.
- **Name page components with PascalCase** matching the Inertia render string exactly: `Inertia::render('Dashboard')` → `Dashboard.jsx`.
- **No default exports from `components/ui/`** — shadcn exports named. Follow the same pattern for custom components.

### Styling

- Use Tailwind utility classes directly. No CSS modules, no styled-components.
- Use `cn()` from `@/lib/utils` to merge conditional classes: `cn('base-class', condition && 'conditional-class', className)`.
- shadcn CSS variables (`--card`, `--muted-foreground`, etc.) are available as Tailwind utilities (`bg-card`, `text-muted-foreground`) via `@theme inline` in `app.css`.
- The entire app is dark-themed. Dark values are set on `:root` — no `.dark` class toggling needed.

### Forms

Use Inertia's `useForm` hook — not `useState` for form fields:

```jsx
const form = useForm({ email: '', password: '' });

// Read:  form.data.email
// Write: form.setData('email', value)
// Submit: form.post('/route', { onSuccess: () => form.reset() })
// Errors: form.errors.email  (from Laravel validation)
// State:  form.processing  (true during submit)
```

### Navigation

```jsx
import { Link, router } from '@inertiajs/react';

// Declarative
<Link href="/dashboard">Dashboard</Link>

// Programmatic GET
router.visit('/dashboard');

// POST (logout, etc.)
router.post('/logout');
```

## Adding shadcn Components

```bash
nvm use 24
npx shadcn@latest add <component>
# Components land in resources/js/components/ui/
```

See [shadcn/ui docs](https://ui.shadcn.com/docs/components) for available components.

## Path Alias

`@` resolves to `resources/js/`. Use it for all internal imports:

```jsx
import { cn } from '@/lib/utils';
import { Card } from '@/components/ui/card';
```

## Shared Props (Available on Every Page)

Set in `HandleInertiaRequests` middleware — accessible via `usePage().props`:

```jsx
import { usePage } from '@inertiajs/react';

const { props } = usePage();
props.flash.success   // flash success message
props.flash.error     // flash error message
```

## TODO: Layout Shell

> A shared layout component (`AppLayout`) wrapping all authenticated pages is planned.
> It will include: sidebar navigation, top bar with user info/logout, flash message display.
> Once created, pages inside the auth group will use it instead of repeating the header markup.
