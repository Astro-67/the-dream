<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A property's VFMS credentials and mode. No row means Sandbox with nothing
 * saved, so every existing property starts on Sandbox; nothing here ever sets Live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vfms_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('property_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('integration_id')->nullable();
            $table->string('registration_id')->nullable();
            $table->text('token')->nullable();
            $table->string('mode')->default('sandbox');
            $table->timestamp('live_since')->nullable();
            // Set when VFMS answers 401, so the settings can say "credentials refused".
            $table->timestamp('credentials_refused_at')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vfms_credentials');
    }
};
