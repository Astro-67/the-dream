<?php

namespace App\Domains\Guests\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id */
#[Fillable(['type', 'number', 'nationality', 'issuing_country', 'expiry'])]
class IdentityDocument extends Model
{
    use BelongsToProperty, HasUlids;

    public const FIELDS = ['type', 'number', 'nationality', 'issuing_country', 'expiry'];

    protected $hidden = self::FIELDS;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return array_fill_keys(self::FIELDS, 'encrypted');
    }
}
