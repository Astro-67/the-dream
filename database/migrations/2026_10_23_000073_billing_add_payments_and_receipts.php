<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->string('method', 30)->nullable();
            $table->string('reference', 255)->nullable();
            // ADR 0004: at most the masked last four digits and the brand, never a card number.
            $table->char('card_last_four', 4)->nullable();
            $table->string('card_brand', 20)->nullable();
        });

        Schema::table('reservations', function (Blueprint $table) {
            // Minor units of the rate plan's currency, when the channel is the payment collector.
            $table->unsignedBigInteger('channel_collected_amount')->nullable();
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->string('receipt_prefix', 10)->default('RCT-');
        });

        Schema::create('receipt_sequences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->unique()->constrained();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('posting_id')->unique()->constrained();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 30);
            // Issued through VFMS in M3; a Thedream receipt is always non-fiscal.
            $table->string('vfms_receipt_number', 100)->nullable();
            $table->timestamp('issued_at');
            $table->unique(['property_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('receipt_sequences');
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('receipt_prefix'));
        Schema::table('reservations', fn (Blueprint $table) => $table->dropColumn('channel_collected_amount'));
        Schema::table('postings', fn (Blueprint $table) => $table->dropColumn(['method', 'reference', 'card_last_four', 'card_brand']));
    }
};
