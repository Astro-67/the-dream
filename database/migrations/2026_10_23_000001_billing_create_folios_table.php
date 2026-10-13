<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folios', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('reservation_id')->constrained();
            $table->string('currency', 3);
            $table->timestamps();
            $table->unique(['reservation_id', 'currency']);
        });

        // Migrations run without a tenant context; every existing reservation gets a folio in its rate plan's currency.
        DB::table('reservations')->join('rate_plans', 'rate_plans.id', '=', 'reservations.rate_plan_id')
            ->select('reservations.id', 'reservations.organization_id', 'reservations.property_id', 'rate_plans.currency')
            ->orderBy('reservations.id')->chunkById(500, function ($reservations) {
                DB::table('folios')->insert($reservations->map(fn ($reservation) => [
                    'id' => (string) Str::ulid(),
                    'organization_id' => $reservation->organization_id,
                    'property_id' => $reservation->property_id,
                    'reservation_id' => $reservation->id,
                    'currency' => $reservation->currency,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            }, 'reservations.id', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('folios');
    }
};
