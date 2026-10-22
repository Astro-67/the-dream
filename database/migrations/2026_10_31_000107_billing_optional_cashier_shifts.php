<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('properties')->where(fn ($query) => $query->whereNull('payment_settings')->orWhere('payment_settings', DB::raw('JSON_ARRAY()')))
                ->update(['payment_settings' => DB::raw('JSON_OBJECT()')]);
            DB::table('properties')->whereJsonDoesntContainKey('payment_settings->use_cashier_shifts')
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('cashier_shifts')->whereColumn('cashier_shifts.property_id', 'properties.id'))
                ->update(['payment_settings->use_cashier_shifts' => true]);
            DB::table('properties')->whereJsonDoesntContainKey('payment_settings->use_cashier_shifts')
                ->update(['payment_settings->use_cashier_shifts' => false]);
        });
    }

    public function down(): void {}
};
