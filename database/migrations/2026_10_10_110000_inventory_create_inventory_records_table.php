<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_type_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->unsignedInteger('sellable_count')->default(0);
            $table->unsignedInteger('sold_count')->default(0);
            $table->timestamps();

            $table->unique(['property_id', 'room_type_id', 'date'], 'inventory_records_type_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_records');
    }
};
