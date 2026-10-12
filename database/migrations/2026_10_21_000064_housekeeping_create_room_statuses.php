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
        Schema::create('housekeeping_room_statuses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('dirty');
            $table->timestamps();
        });

        // Migrations run without a tenant context; copy ownership from Inventory.
        DB::table('rooms')->orderBy('id')->chunkById(500, function ($rooms) {
            foreach ($rooms as $room) {
                DB::table('housekeeping_room_statuses')->insert([
                    'id' => (string) Str::ulid(),
                    'organization_id' => $room->organization_id,
                    'property_id' => $room->property_id,
                    'room_id' => $room->id,
                    'status' => 'dirty',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_room_statuses');
    }
};
