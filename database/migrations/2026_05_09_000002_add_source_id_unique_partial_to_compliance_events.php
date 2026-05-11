<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Partial unique index: enforce one row per source_id, but allow
        // multiple 'unknown' rows (malformed Axioms without a real identifier).
        DB::statement(
            "CREATE UNIQUE INDEX compliance_events_source_id_unique
             ON compliance_events (source_id)
             WHERE source_id != 'unknown'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS compliance_events_source_id_unique');
    }
};
