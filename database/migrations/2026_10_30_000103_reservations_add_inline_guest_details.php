<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->ulid('booker_id')->nullable()->change();
            $table->string('meal_plan')->default('room-only');
            $table->text('special_requests')->nullable();
            $table->string('booked_through')->default('guest');
            $table->string('tour_operator_name')->nullable();
            $table->string('tour_operator_registration')->nullable();
            $table->string('tour_operator_pays_for')->nullable();
        });
    }
};
