<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_identity_floors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->string('integration', 100);
            $table->string('subject_type', 191);
            $table->string('subject_id', 191);
            $table->unsignedBigInteger('highest_sequence');
            $table->char('evidence_hash', 64);
            $table->string('actor_id', 26);
            $table->string('reason', 512);
            $table->timestamps();
            $table->unique(['organization_id', 'property_id', 'integration', 'subject_type', 'subject_id'], 'outbox_identity_floor_stream_unique');
            $table->index(['organization_id', 'property_id'], 'outbox_identity_floor_property_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_identity_floors');
    }
};
