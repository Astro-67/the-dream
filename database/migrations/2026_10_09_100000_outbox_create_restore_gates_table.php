<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_restore_gates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_closed')->default(true);
            $table->timestamp('delivery_reconciled_at')->nullable();
            $table->timestamp('identity_safe_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->string('last_reason', 512);
            $table->timestamps();
            $table->unique('property_id', 'outbox_restore_gate_property_unique');
            $table->index(['organization_id', 'property_id', 'is_closed'], 'outbox_restore_gate_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_restore_gates');
    }
};
