<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('paying_institution_type')->nullable();
            $table->string('paying_institution_name')->nullable();
            $table->string('paying_institution_zrb_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn(['paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number']);
        });
    }
};
