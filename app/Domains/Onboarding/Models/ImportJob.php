<?php

namespace App\Domains\Onboarding\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $file_path
 * @property string $status
 */
#[Fillable(['uploaded_by', 'file_path', 'original_name', 'status', 'commit_results'])]
class ImportJob extends Model
{
    use BelongsToProperty, HasUlids;

    protected function casts(): array
    {
        return ['commit_results' => 'array'];
    }

    /** @return HasMany<ImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class)->orderBy('row_number');
    }
}
