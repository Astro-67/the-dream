<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_payload_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('outbox_message_id');
            $table->foreign(['organization_id', 'property_id', 'outbox_message_id'], 'outbox_revision_message_tenant_fk')
                ->references(['organization_id', 'property_id', 'id'])->on('outbox_messages')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->text('payload');
            $table->string('reason', 512);
            $table->timestamps();
            $table->unique(['outbox_message_id', 'revision'], 'outbox_revision_number_unique');
            $table->index(['organization_id', 'property_id', 'outbox_message_id'], 'outbox_revision_tenant_message_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_payload_revisions');
    }
};
