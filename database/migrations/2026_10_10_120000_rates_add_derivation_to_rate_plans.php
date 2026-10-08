<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            // A base plan can't be deleted while plans derive from it.
            $table->foreignUlid('base_rate_plan_id')->nullable()->constrained('rate_plans')->restrictOnDelete();
            $table->string('adjustment_kind', 16)->nullable();
            $table->integer('adjustment')->nullable();
            $table->timestamp('deactivated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('base_rate_plan_id');
            $table->dropColumn(['adjustment_kind', 'adjustment', 'deactivated_at']);
        });
    }
};
