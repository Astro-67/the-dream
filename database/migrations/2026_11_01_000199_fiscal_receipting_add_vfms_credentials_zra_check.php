<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The last ZRA credential check: accepted, refused or unreachable, and when. Null until checked. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vfms_credentials', function (Blueprint $table) {
            $table->string('zra_check')->nullable();
            $table->timestamp('zra_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vfms_credentials', function (Blueprint $table) {
            $table->dropColumn(['zra_check', 'zra_checked_at']);
        });
    }
};
