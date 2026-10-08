<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->string('coalesce_key_hash', 64)->nullable();
            $table->index(['organization_id', 'property_id', 'integration', 'coalesce_key_hash', 'status'], 'outbox_coalesce_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropIndex('outbox_coalesce_lookup_index');
            $table->dropColumn('coalesce_key_hash');
        });
    }
};
