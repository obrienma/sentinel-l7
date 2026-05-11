<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_events', function (Blueprint $table) {
            $table->string('domain', 32)->nullable()->after('source_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('compliance_events', function (Blueprint $table) {
            $table->dropColumn('domain');
        });
    }
};
