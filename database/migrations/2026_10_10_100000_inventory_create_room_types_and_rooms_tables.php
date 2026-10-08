<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 16);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('base_occupancy');
            $table->unsignedTinyInteger('max_adults');
            $table->unsignedTinyInteger('max_children');
            $table->unsignedTinyInteger('max_occupancy');
            $table->timestamps();

            // Codes are unique within a property only.
            $table->unique(['property_id', 'code']);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_type_id')->constrained()->restrictOnDelete();
            $table->string('number', 16);
            $table->string('floor', 16)->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            // Numbers are unique within a property only: two properties can both have room 101.
            $table->unique(['property_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_types');
    }
};
