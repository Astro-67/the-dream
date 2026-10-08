<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_restore_evidence', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->string('integration', 100);
            $table->string('message_id', 26)->nullable();
            $table->string('outcome', 32);
            $table->char('evidence_hash', 64);
            $table->json('provider_references')->nullable();
            $table->string('actor_id', 26);
            $table->string('reason', 512);
            $table->timestamps();
            $table->index(['organization_id', 'property_id', 'integration'], 'outbox_restore_evidence_scope_index');
            $table->index(['property_id', 'message_id'], 'outbox_restore_evidence_message_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_restore_evidence');
    }
};
