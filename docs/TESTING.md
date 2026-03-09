# Testing

## Running Tests

```bash
composer test                               # full suite
./vendor/bin/pest --filter=TestName        # single test by name
./vendor/bin/pest --group=architecture     # arch tests only
./vendor/bin/pest --group=unit             # unit tests only
```

## Test Philosophy

- **Architecture tests** (Pest `arch()`) are the most critical — they enforce domain boundaries that human review can miss.
- Unit tests cover service logic in isolation using mocks for external dependencies (Gemini, Upstash).
- Feature tests cover HTTP routes end-to-end via Laravel's test helpers.

## Architecture Tests

The domain logic layer (`App\Services\Sentinel\Logic`) must not directly use infrastructure:

```php
arch('sentinel logic does not use Http facade')
    ->expect('App\Services\Sentinel\Logic')
    ->not->toUse('Illuminate\Support\Facades\Http');

arch('sentinel logic does not use Redis facade')
    ->expect('App\Services\Sentinel\Logic')
    ->not->toUse('Illuminate\Support\Facades\Redis');
```

These tests run fast and catch accidental coupling before it becomes a problem. If you're adding to `App\Services\Sentinel\Logic`, ensure any external I/O goes through an injected interface, not a facade.

## Mocking External Services

Tests should never hit real external APIs. Mock at the service interface boundary:

```php
// In tests, bind a fake ComplianceDriver
$this->mock(ComplianceDriver::class)
     ->shouldReceive('analyze')
     ->andReturn(['risk_level' => 'low', 'flags' => []]);
```

## Frontend Testing

No frontend tests currently. When added, use **Vitest** + **React Testing Library** (consistent with the Kotlin variant's approach).

```bash
npm test        # once tests are added
```

## Test Coverage Areas

| Area | Test Type | File |
|------|-----------|------|
| Domain boundary isolation | Architecture | `tests/Architecture/` |
| ComplianceEngine pipeline | Unit | `tests/Unit/Services/` |
| Stream consumer logic | Unit | `tests/Unit/Services/` |
| Vector cache hit/miss | Unit | `tests/Unit/Services/` |
| Auth routes | Feature | `tests/Feature/Auth/` |
| Dashboard access control | Feature | `tests/Feature/Dashboard/` |
