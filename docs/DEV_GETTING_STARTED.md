# Dev Getting Started

## Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| PHP | 8.4+ | `php -v` |
| Composer | 2.x | `composer -V` |
| Node.js | 24 (via NVM) | `nvm use 24` |
| Laravel Artisan | via Composer | - |

## Environment Setup

```bash
cp .env.example .env
php artisan key:generate
```

Required `.env` values:

```env
VITE_APP_NAME="Sentinel-L7"

# Upstash Redis Streams
REDIS_URL=rediss://...

# Upstash Vector
UPSTASH_VECTOR_REST_URL=https://...
UPSTASH_VECTOR_REST_TOKEN=...

# Gemini AI
GEMINI_API_KEY=...

# Active AI driver: gemini | openrouter | ollama (ollama is the default, see ADR-0027)
SENTINEL_AI_DRIVER=ollama

# Optional — override the default 0.90 similarity threshold
# UPSTASH_VECTOR_THRESHOLD=0.90
```

> **Note:** `GEMINI_EMBEDDING_URL` and `GEMINI_FLASH_URL` both have sensible defaults
> (gemini-embedding-001 and gemini-2.0-flash). Override only if you need a different
> model or proxy endpoint.

## Database

```bash
php artisan migrate
php artisan db:seed    # optional demo data
```

## Running Locally

### All processes (recommended)

```bash
composer dev
# Starts: web server, queue worker, logs (pail), Vite, sentinel:watch-axioms
```

> `composer dev` does **not** include `sentinel:watch` (the transaction stream
> consumer). Run it manually in a separate terminal when testing the transaction
> pipeline — see Seeding Test Data below.

### Individually

```bash
php artisan serve                    # web only
php artisan queue:listen             # Laravel queue worker
php artisan sentinel:watch           # transaction stream consumer (XREADGROUP)
php artisan sentinel:watch-axioms    # Synapse-L4 axiom consumer (XREADGROUP)
npm run dev                          # Vite HMR (frontend)
```

## Seeding Test Data

```bash
# Create a login user
php artisan tinker
>>> \App\Models\User::factory()->create(['email' => 'you@example.com', 'password' => bcrypt('password')]);

# Index policy documents into Vector KB
php artisan sentinel:ingest

# Reset dashboard metrics counters
php artisan sentinel:reset-metrics
```

### Simulating the transaction pipeline

`sentinel:stream` writes to the Redis stream; `sentinel:watch` consumes and
processes them. Run both together in separate terminals:

```bash
# Terminal 1 — start the worker
php artisan sentinel:watch

# Terminal 2 — publish 100 transactions
php artisan sentinel:stream --limit=100

# For faster publishing (no inter-message delay):
php artisan sentinel:stream --limit=100 --speed=0
```

### Clearing dev state

```bash
# Reset dashboard counters
php artisan sentinel:reset-metrics

# Clear the recent transactions feed
php artisan tinker --execute="\Illuminate\Support\Facades\Redis::del('sentinel:recent_transactions');"

# Flush the Upstash Vector cache (default namespace)
php artisan tinker --execute="
\$url = config('services.upstash_vector.url');
\$token = config('services.upstash_vector.token');
\Illuminate\Support\Facades\Http::withToken(\$token)->post(\$url . '/reset');
echo 'done';
"
```

## Frontend

```bash
nvm use 24          # ensure Node 24
npm install
npm run dev         # Vite dev server with HMR
npm run build       # production build
```

> **Note:** Never open the Vite port (5173) directly. Access the app via Laravel's port (8000). Vite runs alongside and handles HMR automatically.

## Adding shadcn/ui Components

```bash
nvm use 24
npx shadcn@latest add <component>
# e.g. npx shadcn@latest add dialog select tooltip
```

Components land in `resources/js/components/ui/`. They're plain JSX — edit freely.

## Running Tests

```bash
composer test                               # full Pest suite
./vendor/bin/pest --filter=TestName        # single test
./vendor/bin/pest --group=architecture     # arch tests only
```

## Linting

```bash
./vendor/bin/pint                          # Laravel Pint (PHP)
```

## Key File Locations

| What | Where |
|------|-------|
| Pages (React) | `resources/js/Pages/` |
| UI components | `resources/js/components/ui/` |
| Shared components | `resources/js/components/` |
| CSS + Tailwind config | `resources/css/app.css` |
| Inertia entry | `resources/js/app.js` |
| Web routes | `routes/web.php` |
| Services | `app/Services/` |
| AI drivers | `app/Services/Compliance/` |
| Artisan commands | `app/Console/Commands/` |
| Pest tests | `tests/` |
