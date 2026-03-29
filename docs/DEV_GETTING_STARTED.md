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
GEMINI_MODEL=gemini-2.0-flash
GEMINI_EMBEDDING_MODEL=gemini-embedding-001

# Active AI driver: gemini | openrouter
SENTINEL_AI_DRIVER=gemini
```

## Database

```bash
php artisan migrate
php artisan db:seed    # optional demo data
```

## Running Locally

### All processes (recommended)

```bash
composer dev-full
# Starts: web server + worker + reclaimer (via concurrently)
```

### Individually

```bash
composer dev                         # web + queue + logs + vite
php artisan serve                    # web only
php artisan sentinel:consume         # stream worker
php artisan sentinel:reclaim         # PEL reclaimer
npm run dev                          # Vite HMR (frontend)
```

## Seeding Test Data

```bash
# Create a login user
php artisan tinker
>>> \App\Models\User::factory()->create(['email' => 'you@example.com', 'password' => bcrypt('password')]);

# Simulate transaction stream (100 events)
php artisan sentinel:stream --limit=100

# Index policy documents into Vector KB
php artisan sentinel:ingest

# Reset dashboard metrics counters
php artisan sentinel:reset-metrics
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
| Domain logic | `app/Services/Sentinel/Logic/` |
| AI drivers | `app/Services/Sentinel/Drivers/` |
| Artisan commands | `app/Console/Commands/` |
| Pest tests | `tests/` |
