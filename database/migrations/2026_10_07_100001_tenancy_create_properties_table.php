<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('property_type', 32);
            $table->string('address_line');
            $table->string('city');
            $table->string('region');
            $table->string('postal_code', 16)->nullable();
            $table->string('phone', 32);
            $table->string('email');
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->string('jurisdiction_profile', 32);
            $table->string('zra_hotel_code', 64)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
