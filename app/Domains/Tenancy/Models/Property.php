<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Domains\Tenancy\Policies\PropertyPolicy;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One property operated by an organization.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property PropertyType $property_type
 * @property string $address_line
 * @property string $city
 * @property string $region
 * @property string|null $postal_code
 * @property string $phone
 * @property string $email
 * @property string $timezone
 * @property Currency $currency
 * @property JurisdictionProfile $jurisdiction_profile
 * @property string|null $zra_hotel_code
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'organization_id', 'name', 'property_type', 'address_line', 'city', 'region', 'postal_code',
    'phone', 'email', 'timezone', 'currency', 'jurisdiction_profile', 'zra_hotel_code',
])]
#[UseFactory(PropertyFactory::class)]
#[UsePolicy(PropertyPolicy::class)]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const DEFAULT_TIMEZONE = 'Africa/Dar_es_Salaam';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'currency' => Currency::class,
            'jurisdiction_profile' => JurisdictionProfile::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('archived_at'));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
