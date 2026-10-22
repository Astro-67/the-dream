<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('location')->nullable();
            $table->string('title');
            $table->text('description');
            $table->string('category');
            $table->string('priority');
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['property_id', 'completed_at', 'created_at']);
        });

        Schema::create('maintenance_ticket_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('ticket_id')->constrained('maintenance_tickets')->restrictOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('recorded_at');
            $table->index(['ticket_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_ticket_events');
        Schema::dropIfExists('maintenance_tickets');
    }
};
