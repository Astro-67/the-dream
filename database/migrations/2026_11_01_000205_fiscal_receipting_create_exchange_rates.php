<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ZRA's daily exchange rates (FSC-09), one row per date. National, so not tenant-owned. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->date('date')->unique();
            $table->decimal('usd_tzs', 14, 6);
            $table->decimal('eur_tzs', 14, 6);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
