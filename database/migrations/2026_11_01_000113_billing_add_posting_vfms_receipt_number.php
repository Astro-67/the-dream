<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->string('vfms_receipt_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->dropColumn('vfms_receipt_number');
        });
    }
};
