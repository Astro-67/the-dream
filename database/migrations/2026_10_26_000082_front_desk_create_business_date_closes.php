<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_date_closes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->date('business_date');
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['property_id', 'business_date']);
        });

        Schema::table('postings', function (Blueprint $table) {
            $table->foreignUlid('reservation_id')->nullable()->constrained();
            $table->foreignUlid('price_line_id')->nullable()->constrained('reservation_price_lines');
            $table->unique(['reservation_id', 'business_date', 'category'], 'postings_reservation_night_category_unique');
        });
    }

    public function down(): void
    {
        Schema::table('postings', function (Blueprint $table) {
            // MySQL backs the reservation_id foreign key with this unique index, so the key goes first.
            $table->dropForeign(['reservation_id']);
            $table->dropUnique('postings_reservation_night_category_unique');
            $table->dropConstrainedForeignId('price_line_id');
            $table->dropColumn('reservation_id');
        });
        Schema::dropIfExists('business_date_closes');
    }
};
