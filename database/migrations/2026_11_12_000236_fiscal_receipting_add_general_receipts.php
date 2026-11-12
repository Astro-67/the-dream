<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General receipts (spec #249): a line discount on postings, the claim of a held extra by the
 * general receipt's outbox message (same organization and property), and documents with no stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            $table->unsignedBigInteger('discount')->default(0);
            $table->foreignUlid('general_receipt_id')->nullable();
            $table->foreign(['organization_id', 'property_id', 'general_receipt_id'], 'posting_general_receipt_tenant_fk')
                ->references(['organization_id', 'property_id', 'id'])->on('outbox_messages');
        });
        Schema::table('issued_documents', function (Blueprint $table) {
            $table->string('reservation_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', function (Blueprint $table) {
            $table->string('reservation_id', 64)->nullable(false)->change();
        });
        Schema::table('postings', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'property_id', 'general_receipt_id']);
            $table->dropColumn(['discount', 'general_receipt_id']);
        });
    }
};
