<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('go_live_requested_at')->nullable();
            $table->foreignUlid('go_live_requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropForeign(['go_live_requested_by']);
            $table->dropColumn(['go_live_requested_at', 'go_live_requested_by']);
        });
    }
};
