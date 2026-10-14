<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_invoice_sequences', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_number');
        });

        DB::table('saas_invoice_sequences')->insert([
            'id' => 1,
            'last_number' => (int) DB::table('saas_invoices')->max('sequence'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('saas_invoice_sequences');
    }
};
