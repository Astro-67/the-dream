<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // VFMS numbers belong to service calls and check-out reports, never to a payment (PRD FSC-05, FSC-06).
        if (DB::table('receipts')->whereNotNull('vfms_receipt_number')->exists()) {
            throw new RuntimeException('receipts.vfms_receipt_number holds data; move it before dropping the column.');
        }

        Schema::table('receipts', fn (Blueprint $table) => $table->dropColumn('vfms_receipt_number'));
    }

    public function down(): void
    {
        Schema::table('receipts', fn (Blueprint $table) => $table->string('vfms_receipt_number', 100)->nullable());
    }
};
