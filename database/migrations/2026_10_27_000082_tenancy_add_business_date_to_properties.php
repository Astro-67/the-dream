<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->date('business_date')->nullable();
            $table->time('close_time')->default('02:00:00');
        });

        // Existing properties open on their current local date.
        DB::table('properties')->orderBy('id')->chunkById(500, function ($properties) {
            foreach ($properties as $property) {
                DB::table('properties')->where('id', $property->id)
                    ->update(['business_date' => CarbonImmutable::now($property->timezone)->toDateString()]);
            }
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->date('business_date')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['business_date', 'close_time']);
        });
    }
};
