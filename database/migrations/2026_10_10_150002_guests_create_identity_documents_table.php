<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('identity_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('guest_id')->constrained()->cascadeOnDelete();
            foreach (['type', 'number', 'nationality', 'issuing_country', 'expiry'] as $field) $table->text($field)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'property_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('identity_documents'); }
};
