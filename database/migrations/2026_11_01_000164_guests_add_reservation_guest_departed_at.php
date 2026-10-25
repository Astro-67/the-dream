<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            $table->timestamp('departed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_guests', function (Blueprint $table) {
            $table->dropColumn('departed_at');
        });
    }
};
