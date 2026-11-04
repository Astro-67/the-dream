<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per fiscal document VFMS issued (a services receipt or one guest's check-out report),
 * projected from delivered outbox evidence. Rebuildable from that evidence; never the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('outbox_message_id');
            $table->foreign(['organization_id', 'property_id', 'outbox_message_id'], 'issued_document_message_tenant_fk')
                ->references(['organization_id', 'property_id', 'id'])->on('outbox_messages')->cascadeOnDelete();
            $table->foreignUlid('outbox_attempt_id')->nullable();
            $table->string('reservation_id', 64);
            $table->string('posting_id', 64)->nullable();
            $table->string('type', 32);
            $table->string('number', 100);
            $table->string('mode', 16);
            $table->timestamp('issued_at');
            // Null when the issue-time evidence no longer holds it: never reconstructed from today's folio or settings.
            $table->bigInteger('amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('guest_name')->nullable();
            $table->string('viewer_url', 2048)->nullable();
            $table->timestamps();

            // An early departure's report number reappears in the final check-out's references: one row.
            $table->unique(['property_id', 'mode', 'type', 'number'], 'issued_document_number_unique');
            $table->index(['property_id', 'issued_at'], 'issued_document_issued_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_documents');
    }
};
