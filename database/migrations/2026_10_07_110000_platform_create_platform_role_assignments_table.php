<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Platform roles live in their own table, not in spatie's organization teams,
 * so no organization membership, role or invitation can ever grant one (ADR 0007).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_role_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 64);
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_role_assignments');
    }
};
