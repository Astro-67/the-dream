<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('last_advanced_at')->nullable();
        });
        Schema::table('saas_invoices', function (Blueprint $table) {
            $table->timestamp('before_reminded_at')->nullable();
            $table->timestamp('after_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('last_advanced_at');
        });
        Schema::table('saas_invoices', function (Blueprint $table) {
            $table->dropColumn(['before_reminded_at', 'after_reminded_at']);
        });
    }
};
