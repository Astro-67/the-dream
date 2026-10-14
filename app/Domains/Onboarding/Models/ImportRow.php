<?php

namespace App\Domains\Onboarding\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, string>|list<string> $raw_data
 * @property array<string, mixed>|null $commit_result
 */
#[Fillable(['import_job_id', 'row_number', 'source_reference', 'status', 'raw_data', 'validated_data', 'errors', 'commit_result'])]
class ImportRow extends Model
{
    use BelongsToProperty, HasUlids;

    protected function casts(): array
    {
        return ['row_number' => 'integer', 'raw_data' => 'array', 'validated_data' => 'array', 'errors' => 'array', 'commit_result' => 'array'];
    }
}
