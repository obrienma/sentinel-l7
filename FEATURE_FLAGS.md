# Sentinel-L7 Feature Flags

This document describes the feature flag system, how flags are defined, how to use them in PHP and Vue, and how to add new ones.

---

## How it works

Flags are defined in [`config/features.php`](config/features.php). Each flag:

- **Defaults to `false` on `production`** — no change to `.env` needed to keep new features hidden in prod
- **Defaults to `true` on `local` and `staging`** — visible by default during development
- **Can be explicitly overridden** in any `.env` file regardless of environment

Flags are shared globally to every Inertia page via `HandleInertiaRequests::share()`, under the `features` key.

---

## Current Flags

| Flag | `.env` key | Default (non-prod) | Default (prod) | Description |
|---|---|---|---|---|
| `env_badge` | `FEATURE_ENV_BADGE` | `true` | `false` | Floating pill in top-right corner showing current environment name |
| `dashboard_access` | `FEATURE_DASHBOARD_ACCESS` | `true` | `false` | Shows "Access Dashboard" CTA on Home page; enables `/dashboard` route |

---

## Overriding flags in `.env`

```dotenv
# Force a flag ON in production (e.g., for a canary deploy or stakeholder demo)
FEATURE_DASHBOARD_ACCESS=true

# Force a flag OFF in local/staging (e.g., to test the production view)
FEATURE_ENV_BADGE=false
FEATURE_DASHBOARD_ACCESS=false
```

After changing `.env`, run:
```bash
php artisan config:clear
```

---

## Using flags in Vue / Inertia

### Option A — `usePage()` directly

```vue
<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const features = computed(() => usePage().props.features ?? {});
</script>

<template>
    <SomeComponent v-if="features.dashboard_access" />
</template>
```

### Option B — create a composable (recommended for repeated use)

Create `resources/js/composables/useFeature.js`:

```js
import { usePage } from '@inertiajs/vue3';

export function useFeature(flag) {
    return usePage().props.features?.[flag] ?? false;
}
```

Then use it anywhere:

```vue
<script setup>
import { useFeature } from '@/composables/useFeature';

const canSeeDebugPanel = useFeature('ai_debug_panel');
</script>

<template>
    <DebugPanel v-if="canSeeDebugPanel" />
</template>
```

---

## Using flags in PHP (controllers / middleware)

```php
// In a controller
if (!config('features.dashboard_access')) {
    abort(404);
}

// In a Blade template (if not using Inertia)
@if(config('features.dashboard_access'))
    <a href="/dashboard">Dashboard</a>
@endif
```

---

## Adding a new flag

**1. Add to `config/features.php`:**

```php
'ai_debug_panel' => (bool) env('FEATURE_AI_DEBUG_PANEL', $nonProduction),
```

**2. Share it in `HandleInertiaRequests`** (if it needs to be available in Vue):

```php
'features' => [
    'env_badge'        => config('features.env_badge'),
    'dashboard_access' => config('features.dashboard_access'),
    'ai_debug_panel'   => config('features.ai_debug_panel'),   // ← add here
    'app_env'          => app()->environment(),
],
```

**3. Use it in Vue or PHP as shown above.**

**4. Document it in this file** — add a row to the Current Flags table.

---

## Environment-aware badge

The `env_badge` flag enables a floating pill in the top-right corner of every page. Its colour is environment-aware:

| Environment | Colour | Signal |
|---|---|---|
| `local` | Amber | Local development |
| `staging` | Orange | Staging / pre-production |
| anything else (non-prod) | Blue | Other non-production env |
| `production` | Hidden | Never shown |

---

## Production safety

The `/dashboard` route serves a `404` unless `FEATURE_DASHBOARD_ACCESS=true` is explicitly set — the feature flag check is enforced in `DashboardController::index()`, not just in the Vue template. This means even a direct URL visit is blocked on production.
