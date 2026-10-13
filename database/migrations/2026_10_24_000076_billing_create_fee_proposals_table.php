<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('reservation_id')->constrained();
            $table->foreignUlid('folio_id')->constrained();
            $table->string('kind', 20);
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->string('reason', 500)->nullable();
            $table->foreignUlid('posting_id')->nullable()->constrained();
            $table->boolean('fiscal_exception_candidate')->default(false);
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['reservation_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_proposals');
    }
};
