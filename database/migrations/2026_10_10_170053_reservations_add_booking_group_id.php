<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->ulid('booking_group_id')->nullable();
            $table->index(['property_id', 'booking_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex(['property_id', 'booking_group_id']);
            $table->dropColumn('booking_group_id');
        });
    }
};
