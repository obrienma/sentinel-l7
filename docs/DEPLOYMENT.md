# Deployment

Sentinel-L7 is deployed on [Render](https://render.com) using Blueprint (Infrastructure as Code via `render.yaml`).

## Services

Three Render services map to the three local processes:

| Render Service | Type | Command |
|---------------|------|---------|
| `sentinel-web` | Web Service | `php artisan serve --host=0.0.0.0 --port=$PORT` |
| `sentinel-worker` | Background Worker | `php artisan sentinel:consume` |
| `sentinel-reclaimer` | Background Worker | `php artisan sentinel:reclaim` |

## Required Environment Variables (Render Dashboard)

```
APP_KEY=base64:...         # php artisan key:generate --show
APP_ENV=production
APP_URL=https://your-domain.onrender.com

DB_CONNECTION=...
DATABASE_URL=...

REDIS_URL=rediss://...     # Upstash Redis (TLS)

UPSTASH_VECTOR_REST_URL=https://...
UPSTASH_VECTOR_REST_TOKEN=...

GEMINI_API_KEY=...
GEMINI_MODEL=gemini-2.0-flash
GEMINI_EMBEDDING_MODEL=gemini-embedding-001

SENTINEL_AI_DRIVER=gemini
```

## Deploy

```bash
git push origin master
# Render auto-deploys on push to master (configured in render.yaml)
```

## One-Off Jobs (Render)

Render supports one-off commands via the dashboard or CLI:

```bash
# Simulate transactions (useful for demo/testing on staging)
php artisan sentinel:stream --limit=100

# Re-index policy documents
php artisan sentinel:ingest

# Run migrations
php artisan migrate --force
```

## Production Checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` set and not committed to repo
- [ ] Redis using TLS (`rediss://`)
- [ ] `php artisan config:cache` runs on deploy
- [ ] `php artisan route:cache` runs on deploy
- [ ] `npm run build` output (`public/build/`) committed or built in CI
- [ ] Database migrations run (`php artisan migrate --force`)

## Logs

```bash
# Via Render dashboard → service → Logs tab
# Or Render CLI:
render logs sentinel-worker --tail
```
