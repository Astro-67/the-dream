<?php

use App\Domains\Onboarding\Enums\ImportJobStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('status', 30)->default(ImportJobStatus::DryRun->value);
            $table->json('commit_results')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'property_id']);
        });
        Schema::create('import_rows', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained();
            $table->foreignUlid('property_id')->constrained();
            $table->foreignUlid('import_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_reference')->nullable();
            $table->string('status', 30);
            $table->json('raw_data');
            $table->json('validated_data')->nullable();
            $table->json('errors');
            $table->json('commit_result')->nullable();
            $table->timestamps();
            $table->unique(['import_job_id', 'row_number']);
            $table->index(['organization_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_jobs');
    }
};
