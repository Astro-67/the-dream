<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The whole-number room ID VFMS asks for (guide §2.1 roomId): unique within the
 * property, numbered 1, 2, 3... for existing rooms in creation order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('vfms_room_id')->nullable();
        });
        $next = [];
        DB::table('rooms')->orderBy('created_at')->orderBy('id')->select(['id', 'property_id'])->each(function (object $room) use (&$next) {
            $next[$room->property_id] = ($next[$room->property_id] ?? 0) + 1;
            DB::table('rooms')->where('id', $room->id)->update(['vfms_room_id' => $next[$room->property_id]]);
        });
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedInteger('vfms_room_id')->nullable(false)->change();
            $table->unique(['property_id', 'vfms_room_id']);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['property_id', 'vfms_room_id']);
            $table->dropColumn('vfms_room_id');
        });
    }
};
