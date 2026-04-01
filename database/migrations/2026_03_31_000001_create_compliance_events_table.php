<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_events', function (Blueprint $table) {
            $table->id();

            // Axiom payload — stored verbatim for audit trail
            $table->string('source_id')->index();
            $table->string('status')->nullable();
            $table->float('metric_value')->nullable();
            $table->float('anomaly_score')->nullable();
            $table->timestamp('emitted_at')->nullable();

            // Routing outcome
            $table->boolean('routed_to_ai')->default(false)->index();
            $table->text('audit_narrative')->nullable();
            $table->string('driver_used', 64)->nullable();

            $table->timestamps();

            $table->index(['source_id', 'anomaly_score']);
            $table->index(['routed_to_ai', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_events');
    }
};
