<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            // Null on receipts issued before snapshots: their then-balance is not reconstructed.
            $table->json('summary')->nullable();
        });
    }

    public function down(): void
    {
        // Snapshots are the only record of a receipt's then-balance, so a rollback keeps them (ticket #136).
    }
};
