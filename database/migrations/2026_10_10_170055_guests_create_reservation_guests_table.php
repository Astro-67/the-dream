<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_guests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('reservation_id')->constrained();
            $table->foreignUlid('guest_id')->constrained();
            $table->timestamps();
            $table->unique(['reservation_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_guests');
    }
};
