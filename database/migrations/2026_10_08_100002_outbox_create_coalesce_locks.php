<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_coalesce_locks', function (Blueprint $table) {
            $table->string('key_hash', 64)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_coalesce_locks');
    }
};
