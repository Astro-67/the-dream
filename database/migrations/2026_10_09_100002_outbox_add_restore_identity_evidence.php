<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_restore_gates', function (Blueprint $table): void {
            $table->char('identity_evidence_hash', 64)->nullable();
            $table->string('identity_reason', 512)->nullable();
            $table->string('identity_actor_id', 26)->nullable();
            $table->string('identity_method', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outbox_restore_gates', function (Blueprint $table): void {
            $table->dropColumn(['identity_evidence_hash', 'identity_reason', 'identity_actor_id', 'identity_method']);
        });
    }
};
