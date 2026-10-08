<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->string('integration', 64);
            $table->string('subject_type', 128);
            $table->string('subject_id', 64);
            $table->unsignedBigInteger('sequence');
            $table->string('stream_key', 64);
            $table->string('idempotency_key', 128);
            $table->string('event', 128);
            $table->text('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->json('provider_references')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'property_id', 'id'], 'outbox_message_tenant_unique');
            $table->unique(['organization_id', 'property_id', 'integration', 'subject_type', 'subject_id', 'sequence'], 'outbox_stream_sequence_unique');
            $table->unique(['organization_id', 'property_id', 'integration', 'subject_type', 'subject_id', 'idempotency_key'], 'outbox_stream_idempotency_unique');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['stream_key', 'sequence', 'status']);
        });

        Schema::create('outbox_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('outbox_message_id');
            $table->foreign(['organization_id', 'property_id', 'outbox_message_id'], 'outbox_attempt_message_tenant_fk')
                ->references(['organization_id', 'property_id', 'id'])
                ->on('outbox_messages')->cascadeOnDelete();

            $table->index(['organization_id', 'property_id', 'outbox_message_id']);

            $table->unsignedInteger('attempt_number');
            $table->string('outcome', 32);
            $table->text('request_body');
            $table->text('response_body')->nullable();
            $table->json('provider_references')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();

            $table->unique(['outbox_message_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_attempts');
        Schema::dropIfExists('outbox_messages');
    }
};
