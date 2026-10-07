<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Domains\Tenancy\Policies\PropertyPolicy;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One hotel operated by an organization (GLOSSARY: property).
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
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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

    public function isZanzibar(): bool
    {
        return $this->jurisdiction_profile === JurisdictionProfile::Zanzibar;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
