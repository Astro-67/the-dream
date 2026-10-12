<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('housekeeping_status_changes', function (Blueprint $table) {
            $table->string('kind')->default('status_change');
            $table->date('business_date')->nullable();
            $table->index(['room_id', 'kind', 'business_date'], 'housekeeping_ready_override_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('housekeeping_status_changes', function (Blueprint $table) {
            $table->dropIndex('housekeeping_ready_override_lookup');
            $table->dropColumn(['kind', 'business_date']);
        });
    }
};
