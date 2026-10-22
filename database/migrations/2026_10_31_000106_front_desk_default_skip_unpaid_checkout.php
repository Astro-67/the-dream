<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('properties')->where(fn ($query) => $query->whereNull('front_desk_settings')->orWhere('front_desk_settings', DB::raw('JSON_ARRAY()')))->update([
                'front_desk_settings' => json_encode(['automatic_checkout_include_unpaid' => false], JSON_THROW_ON_ERROR),
            ]);
            DB::table('properties')->whereJsonDoesntContainKey('front_desk_settings->automatic_checkout_include_unpaid')
                ->update(['front_desk_settings->automatic_checkout_include_unpaid' => false]);
        });
    }

    public function down(): void {}
};
