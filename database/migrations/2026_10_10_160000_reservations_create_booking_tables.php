<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_number_sequences', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->unique()->constrained();
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
        });
        Schema::create('reservations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->string('reservation_number');
            $table->foreignUlid('room_type_id')->constrained();
            $table->foreignUlid('rate_plan_id')->constrained();
            $table->foreignUlid('booker_id')->constrained();
            $table->date('arrival');
            $table->date('departure');
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children');
            $table->string('source', 30);
            $table->string('payment_collector', 30)->default('property');
            $table->string('status', 30);
            $table->timestamps();
            $table->unique(['property_id', 'reservation_number']);
            $table->index(['organization_id', 'property_id', 'arrival']);
        });
        Schema::create('reservation_price_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('reservation_id')->constrained();
            $table->foreignUlid('rate_plan_id')->constrained();
            $table->date('date');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->timestamps();
            $table->unique(['reservation_id', 'date']);
        });
        Schema::create('reservation_lifecycle_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('reservation_id')->constrained();
            $table->string('type', 40);
            $table->unsignedBigInteger('sequence');
            $table->timestamp('occurred_at');
            $table->date('business_date');
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['reservation_id', 'sequence'], 'reservation_event_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_lifecycle_events');
        Schema::dropIfExists('reservation_price_lines');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('reservation_number_sequences');
    }
};
