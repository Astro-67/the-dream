<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->foreignUlid('room_id')->nullable()->constrained();
        });
        Schema::create('room_assignment_nights', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('reservation_id')->constrained();
            $table->foreignUlid('room_id')->constrained();
            $table->date('date');
            $table->timestamps();
            $table->unique(['room_id', 'date']);
            $table->unique(['reservation_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_assignment_nights');
        Schema::table('reservations', fn (Blueprint $table) => $table->dropConstrainedForeignId('room_id'));
    }
};
