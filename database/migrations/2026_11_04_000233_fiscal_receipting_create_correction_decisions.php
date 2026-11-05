<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 2.13 reads (one per on-demand refresh, with encrypted raw evidence) and the
 * append-only ZRA decisions they observed for issued-document corrections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correction_decision_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('request_body');
            $table->text('response_body')->nullable();
            // observed | unresolved | failed; null while the read is in progress.
            $table->string('outcome', 16)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['property_id', 'finished_at']);
        });

        Schema::create('correction_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('property_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('document_correction_id')->constrained()->restrictOnDelete();
            $table->foreignId('correction_decision_read_id')->constrained()->restrictOnDelete();
            $table->string('status', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_decisions');
        Schema::dropIfExists('correction_decision_reads');
    }
};
