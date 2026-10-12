<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('folio_id')->constrained();
            $table->string('type', 30);
            $table->string('category', 30);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('net');
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->json('tax_lines');
            $table->json('tax_rule_ids');
            $table->string('idempotency_key', 100);
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('business_date');
            $table->timestamp('posted_at');
            $table->unique(['folio_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postings');
    }
};
