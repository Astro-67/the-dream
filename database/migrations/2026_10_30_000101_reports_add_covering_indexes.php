<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_lifecycle_events', function (Blueprint $table): void {
            $table->index(['organization_id', 'property_id', 'reservation_id', 'type', 'business_date'], 'report_lifecycle_cover');
        });
        Schema::table('reservations', function (Blueprint $table): void {
            $table->index(['organization_id', 'property_id', 'status', 'arrival', 'departure'], 'report_reservations_cover');
        });
        Schema::table('reservation_price_lines', function (Blueprint $table): void {
            $table->index(['organization_id', 'property_id', 'date', 'reservation_id', 'currency', 'amount'], 'report_booked_value_cover');
            $table->index(['reservation_id', 'date', 'organization_id', 'property_id', 'currency', 'amount'], 'report_reservation_prices_cover');
        });
        Schema::table('folios', function (Blueprint $table): void {
            $table->index(['organization_id', 'property_id', 'reservation_id', 'currency'], 'report_folios_cover');
        });
        Schema::table('postings', function (Blueprint $table): void {
            $table->index(['organization_id', 'property_id', 'folio_id', 'amount'], 'report_folio_balance_cover');
            $table->index(['organization_id', 'property_id', 'type', 'business_date', 'method', 'currency', 'cashier_shift_id', 'amount'], 'report_payments_cover');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            foreach (['postings', 'folios', 'reservation_price_lines', 'reservation_lifecycle_events'] as $tableName) {
                if (! Schema::hasIndex($tableName, ['organization_id'])) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->index('organization_id', $tableName.'_organization_id_foreign'));
                }
            }
        }
        Schema::table('postings', function (Blueprint $table): void {
            $table->dropIndex('report_payments_cover');
            $table->dropIndex('report_folio_balance_cover');
        });
        Schema::table('reservation_price_lines', function (Blueprint $table): void {
            $table->dropIndex('report_reservation_prices_cover');
            $table->dropIndex('report_booked_value_cover');
        });
        Schema::table('folios', fn (Blueprint $table) => $table->dropIndex('report_folios_cover'));
        Schema::table('reservations', fn (Blueprint $table) => $table->dropIndex('report_reservations_cover'));
        Schema::table('reservation_lifecycle_events', fn (Blueprint $table) => $table->dropIndex('report_lifecycle_cover'));
    }
};
