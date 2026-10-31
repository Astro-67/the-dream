<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thedream's own business and payment details: one platform-wide row, printed on every new SaaS invoice.
        Schema::create('billing_details', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('business_name');
            $table->string('address', 500);
            $table->string('phone', 50);
            $table->string('email');
            $table->string('tin', 50);
            $table->string('vrn', 50)->nullable();
            $table->json('accounts');
            $table->timestamps();
        });

        Schema::table('saas_invoices', function (Blueprint $table) {
            // Frozen at issue; null on invoices issued before billing details existed.
            $table->json('billing_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saas_invoices', function (Blueprint $table) {
            $table->dropColumn('billing_snapshot');
        });
        Schema::dropIfExists('billing_details');
    }
};
