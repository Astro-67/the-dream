<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_thresholds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'currency']);
        });
        Schema::create('posting_approvals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('folio_id')->constrained();
            $table->foreignUlid('requested_by')->constrained('users');
            $table->foreignUlid('resolved_by')->nullable()->constrained('users');
            $table->foreignUlid('original_posting_id')->nullable()->constrained('postings');
            $table->foreignUlid('posting_id')->nullable()->unique()->constrained();
            $table->string('idempotency_key', 100);
            $table->string('status', 10);
            $table->json('posting');
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['folio_id', 'idempotency_key']);
            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_approvals');
        Schema::dropIfExists('approval_thresholds');
    }
};
