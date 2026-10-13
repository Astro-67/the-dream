<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('cancellation_free_days')->nullable();
            $table->string('cancellation_charge', 20)->nullable();
            $table->unsignedTinyInteger('cancellation_percent')->nullable();
            $table->string('no_show_charge', 20)->nullable();
            $table->unsignedTinyInteger('no_show_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn(['cancellation_free_days', 'cancellation_charge', 'cancellation_percent', 'no_show_charge', 'no_show_percent']);
        });
    }
};
