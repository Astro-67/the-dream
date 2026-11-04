<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vfms_credentials', function (Blueprint $table) {
            $table->bigInteger('unit_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vfms_credentials', function (Blueprint $table) {
            $table->dropColumn('unit_id');
        });
    }
};
