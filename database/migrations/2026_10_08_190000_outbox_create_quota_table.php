<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_quotas', function (Blueprint $table): void {
            $table->string('identity_hash', 64)->primary();
            $table->unsignedInteger('allowance');
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('window_started_at');
            $table->timestamp('window_ends_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_quotas');
    }
};
