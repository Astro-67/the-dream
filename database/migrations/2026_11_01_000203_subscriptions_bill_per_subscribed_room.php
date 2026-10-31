<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ADR 0018: plans price each subscribed room; the subscription holds how many rooms the organization pays for.
    public function up(): void
    {
        // Dropped and re-added rather than changed: SQLite rebuilds a changed table, which breaks subscriptions' foreign key.
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['room_limit', 'property_limit']);
        });
        // Properties are unlimited for now (null); a platform administrator can set a limit per plan.
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('property_limit')->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('rooms')->default(1);
        });
        // Existing organizations start on the rooms they already run, so no active room goes over.
        foreach (DB::table('subscriptions')->get(['id', 'organization_id']) as $subscription) {
            $active = DB::table('rooms')->join('properties', 'properties.id', '=', 'rooms.property_id')
                ->where('rooms.organization_id', $subscription->organization_id)->whereNull('rooms.deactivated_at')
                ->whereNull('properties.archived_at')->where('properties.demo', false)->count();
            DB::table('subscriptions')->where('id', $subscription->id)->update(['rooms' => max(1, $active)]);
        }

        // Null on invoices issued before per-room billing: their amount was the plan's flat price.
        Schema::table('saas_invoices', function (Blueprint $table) {
            $table->unsignedInteger('rooms')->nullable();
            $table->unsignedBigInteger('unit_price')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('saas_invoices', function (Blueprint $table) {
            $table->dropColumn(['rooms', 'unit_price']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('rooms');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('property_limit');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('property_limit')->default(10000);
            $table->unsignedInteger('room_limit')->default(100000);
        });
    }
};
