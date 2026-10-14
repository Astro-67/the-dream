<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thedream's own invoices to organizations (SUB-01): never folios, postings or receipts.
        Schema::create('saas_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('subscription_id')->constrained();
            // One platform-wide sequence, separate from every property's receipt sequence.
            $table->unsignedBigInteger('sequence')->unique();
            $table->string('number', 30)->unique();
            $table->timestamp('period_starts_at');
            $table->timestamp('period_ends_at');
            $table->timestamp('due_at')->index();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 10)->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'period_starts_at']);
        });

        Schema::create('saas_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('saas_invoice_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->string('method', 20);
            $table->string('reference', 100);
            $table->date('received_on');
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_payments');
        Schema::dropIfExists('saas_invoices');
    }
};
