<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('out_of_service_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            // The first date the room is back in service: the period covers starts_on up to the day before.
            $table->date('returns_on');
            $table->string('reason');
            $table->timestamps();

            $table->index(['room_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('out_of_service_periods');
    }
};
