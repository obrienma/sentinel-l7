<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Partial unique index: enforce one row per txn_id among the
        // stream-driven outcomes (cache_hit/cache_miss/fallback), which are
        // the only paths at risk of XAUTOCLAIM redelivery duplicating a row
        // (ADR-0022). driver_override rows are excluded on purpose —
        // arbiter-l8's cross-provider disagreement scoring intentionally
        // calls process() once per provider for the same txn_id
        // (TransactionProcessorService::process(), driverOverride branch),
        // and each call must persist its own row.
        DB::statement(
            "CREATE UNIQUE INDEX transactions_txn_id_unique
             ON transactions (txn_id)
             WHERE source != 'driver_override'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_txn_id_unique');
    }
};
