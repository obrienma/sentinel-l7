<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('txn_id')->index();
            $table->string('merchant');
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency', 10)->nullable();
            $table->boolean('is_threat')->default(false);
            $table->text('message');
            $table->string('source');  // cache_hit | cache_miss | fallback
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
