<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('channel_reference')->nullable();
            $table->text('outside_integration_reason')->nullable();
            $table->boolean('requires_reconciliation')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn(['channel_reference', 'outside_integration_reason', 'requires_reconciliation']);
        });
    }
};
