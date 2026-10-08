<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 16);
            $table->string('currency', 3);
            $table->timestamps();
            $table->unique(['property_id', 'code']);
        });
        Schema::create('rate_plan_room_types', function (Blueprint $table) {
            $table->foreignUlid('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_type_id')->constrained()->restrictOnDelete();
            $table->primary(['rate_plan_id', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plan_room_types');
        Schema::dropIfExists('rate_plans');
    }
};
