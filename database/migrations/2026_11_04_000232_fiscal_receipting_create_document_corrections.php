<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_corrections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('original_document_id')->unique()->constrained('issued_documents')->restrictOnDelete();
            $table->foreignUlid('replacement_document_id')->unique()->constrained('issued_documents')->restrictOnDelete();
            $table->string('original_number', 100);
            $table->string('replacement_number', 100);
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('mode', 16);
            $table->bigInteger('unit_id');
            $table->foreignUlid('outbox_message_id')->nullable()->unique()->constrained('outbox_messages')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_corrections');
    }
};
