<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('housekeeper_id')->constrained('users')->cascadeOnDelete();
            $table->date('business_date');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'business_date', 'housekeeper_id', 'room_id'], 'housekeeping_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_assignments');
    }
};
