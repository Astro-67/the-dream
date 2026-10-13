<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('user_id')->constrained();
            // The user while the shift is open, null once closed: one open shift per user and property.
            $table->foreignUlid('open_user_id')->nullable()->constrained('users');
            $table->string('status', 10);
            // Minor units per currency code.
            $table->json('floats');
            $table->json('expected')->nullable();
            $table->json('counted')->nullable();
            $table->string('variance_reason', 500)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'open_user_id']);
        });

        Schema::table('postings', function (Blueprint $table) {
            $table->foreignUlid('cashier_shift_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->dropForeign(['cashier_shift_id']);
            $table->dropColumn('cashier_shift_id');
        });
        Schema::dropIfExists('cashier_shifts');
    }
};
