<?php

namespace App\Domains\Guests\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @property string $id */
#[Fillable(['type', 'number', 'nationality', 'issuing_country', 'expiry'])]
class IdentityDocument extends Model
{
    use BelongsToProperty, HasUlids;

    public const FIELDS = ['type', 'number', 'nationality', 'issuing_country', 'expiry'];

    protected $hidden = [...self::FIELDS, 'number_hash'];

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            $document->setAttribute('number_hash', self::numberHash($document->getAttribute('number')));
        });
    }

    public static function numberHash(?string $number): ?string
    {
        $normalized = Str::upper(preg_replace('/\s+/u', '', $number ?? '') ?? '');

        return $normalized === '' ? null : hash_hmac('sha256', $normalized, app('encrypter')->getKey());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return array_fill_keys(self::FIELDS, 'encrypted');
    }
}
