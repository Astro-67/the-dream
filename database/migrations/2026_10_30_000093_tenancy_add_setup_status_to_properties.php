<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('status', 10)->default('setup');
            $table->timestamp('went_live_at')->nullable();
            $table->foreignUlid('went_live_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taxes_reviewed_at')->nullable();
            $table->foreignUlid('taxes_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Properties operating before setup status existed are already live.
        DB::table('properties')->update(['status' => 'live']);

        Schema::table('reservation_lifecycle_events', function (Blueprint $table) {
            $table->boolean('test')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('reservation_lifecycle_events', function (Blueprint $table) {
            $table->dropColumn('test');
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['went_live_by']);
            $table->dropForeign(['taxes_reviewed_by']);
            $table->dropColumn(['status', 'went_live_at', 'went_live_by', 'taxes_reviewed_at', 'taxes_reviewed_by']);
        });
    }
};
