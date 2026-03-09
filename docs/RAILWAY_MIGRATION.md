# Render → Railway Migration Guide

## 1. Port Binding

Render injects `$PORT` and `render.yaml` uses `php artisan serve --port $PORT`. Railway also injects `$PORT` but the **Dockerfile uses Apache on port 80**. Railway auto-detects the exposed port from `EXPOSE 80`, so the Dockerfile approach works out of the box. Don't mix the two — the Dockerfile is the safer bet on Railway.

## 2. `trustProxies(at: '*')` — Still Valid

The comment in `bootstrap/app.php` says "safe on Render because load balancer is the only way to access." Railway also reverse-proxies all traffic through its load balancer, so `trustProxies(at: '*')` remains correct. No change needed.

## 3. No `render.yaml` Equivalent — Use `railway.toml` or the Dashboard

Railway doesn't use Blueprint YAML. Two options:

- **Dashboard UI**: create separate services (web, worker, reclaimer) manually
- **`railway.toml`** at project root for build/start overrides

For multi-service (web + workers), Railway uses **service groups within a project**. Each service points to the same repo but with a different start command.

## 4. `CMD` Runs Migrations on Every Deploy

The Dockerfile `CMD` runs `php artisan migrate --force` on startup. This is fine on Railway since each deploy creates a fresh container.

## 5. Health Check Endpoint

Render auto-pings `/`. Railway doesn't have built-in health checks the same way, but `/up` is already configured in `bootstrap/app.php`. Set this as a healthcheck path in Railway's service settings.

## 6. Environment Variables

Manually copy all env vars — Railway has no automatic env import from Render. Key ones to transfer:

- `DB_*` (Neon Postgres — these stay the same)
- `REDIS_*` (Upstash Redis — these stay the same)
- `UPSTASH_VECTOR_*`
- `GEMINI_API_KEY`
- `APP_KEY`, `APP_URL`, `APP_ENV=production`

## 7. Custom Domain / `APP_URL`

Update `APP_URL` to the new Railway domain (e.g., `sentinel-l7.up.railway.app` or custom domain). Inertia/Vite asset URLs depend on this.

## 8. External Services — No Changes

Database (Neon), Redis (Upstash), vector DB (Upstash Vector), and AI (Gemini) are all external SaaS — they don't care which platform hosts the app. No changes needed.

## 9. Worker Services

The commented-out workers (`sentinel:consume`, `sentinel:recover`) map well to Railway — create separate services in the same project with start commands like `php artisan sentinel:consume`. Railway workers don't need an exposed port.

## Bottom Line

The migration is straightforward — the Dockerfile is self-contained and all data services are external. The main work is: create services in Railway dashboard, copy env vars, point DNS. No code changes required.
