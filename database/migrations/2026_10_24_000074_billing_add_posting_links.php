<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            // A reversal or transfer cancels its original, so its net goes negative like its amount.
            // A posting is cancelled at most once: the unique link refuses a second reversal or transfer.
            $table->bigInteger('net')->change();
            $table->foreignUlid('related_posting_id')->nullable()->unique()->after('folio_id')->constrained('postings');
            $table->string('reason', 255)->nullable()->after('description');
        });

        // A reservation may hold several folios in one currency (guest and company), each opened explicitly.
        Schema::table('folios', function (Blueprint $table) {
            $table->index('reservation_id');
            $table->dropUnique(['reservation_id', 'currency']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('folios', ['reservation_id', 'currency'], 'unique')) {
            Schema::table('folios', function (Blueprint $table) {
                $table->unique(['reservation_id', 'currency']);
                $table->dropIndex(['reservation_id']);
            });
        }
        Schema::table('postings', function (Blueprint $table) {
            $table->dropForeign(['related_posting_id']);
            $table->dropUnique(['related_posting_id']);
            $table->dropColumn(['related_posting_id', 'reason']);
            $table->unsignedBigInteger('net')->change();
        });
    }
};
