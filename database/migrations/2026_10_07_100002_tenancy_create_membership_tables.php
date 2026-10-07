<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('all_properties')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('property_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'user_id']);
            $table->index(['user_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_memberships');
        Schema::dropIfExists('organization_memberships');
    }
};
