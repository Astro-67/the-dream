<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exception_list_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('business_date_close_id')->constrained();
            $table->date('business_date');
            $table->string('kind', 20);
            $table->ulid('subject_id');
            $table->string('reference');
            $table->timestamps();
            $table->index(['property_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_list_items');
    }
};
