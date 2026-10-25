<?php

namespace App\Domains\Guests\Models;

use App\Domains\Guests\Policies\GuestPolicy;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $name
 */
#[Fillable(['name', 'email', 'phone', 'nationality', 'preferences', 'notes'])]
#[UsePolicy(GuestPolicy::class)]
class Guest extends Model
{
    /** @return list<array<string, mixed>> */
    public function identityFields(bool $allowed): array
    {
        return $this->documents()->get()->map(fn (IdentityDocument $document) => $allowed ? $document->only(IdentityDocument::FIELDS) : array_fill_keys(IdentityDocument::FIELDS, '••••'))->all();
    }

    use BelongsToProperty, HasUlids;

    /**
     * What authorities (VFMS, ZRA) hold about a guest: name, and the first document with its country code.
     *
     * @return array{name: string, type: string|null, number: string|null, country: string|null}
     */
    public function authorityProfile(): array
    {
        /** @var IdentityDocument|null $document */
        $document = $this->documents()->orderBy('id')->first();

        return ['name' => $this->name, 'type' => $document?->getAttribute('type'), 'number' => $document?->getAttribute('number'), 'country' => $document?->getAttribute('nationality') ?? $this->getAttribute('nationality')];
    }

    /** @return HasMany<IdentityDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(IdentityDocument::class);
    }

    public const FIELDS = ['name', 'email', 'phone', 'nationality', 'preferences', 'notes'];
}
