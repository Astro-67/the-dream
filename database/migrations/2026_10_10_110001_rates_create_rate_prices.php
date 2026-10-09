<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_prices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('rate_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_type_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->unsignedInteger('base_price');
            $table->unsignedInteger('extra_adult');
            $table->unsignedInteger('extra_child');
            $table->timestamps();
            $table->unique(['rate_plan_id', 'room_type_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_prices');
    }
};
