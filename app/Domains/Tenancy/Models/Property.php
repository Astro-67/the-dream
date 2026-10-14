<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyStatus;
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
use LogicException;

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
 * @property bool $housekeeping_inspection_required
 * @property bool $stayover_service_on_close
 * @property list<string>|null $registration_fields
 * @property string $receipt_prefix
 * @property CarbonImmutable $business_date
 * @property string $close_time
 * @property PropertyStatus $status
 * @property CarbonImmutable|null $went_live_at
 * @property string|null $went_live_by
 * @property CarbonImmutable|null $taxes_reviewed_at
 * @property string|null $taxes_reviewed_by
 * @property bool $demo
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'organization_id', 'name', 'property_type', 'address_line', 'city', 'region', 'postal_code',
    'phone', 'email', 'timezone', 'currency', 'jurisdiction_profile', 'zra_hotel_code', 'housekeeping_inspection_required', 'stayover_service_on_close', 'registration_fields', 'receipt_prefix', 'business_date', 'close_time',
])]
#[UseFactory(PropertyFactory::class)]
#[UsePolicy(PropertyPolicy::class)]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const DEFAULT_TIMEZONE = 'Africa/Dar_es_Salaam';

    /** New properties start in setup; only GoLive moves them to live. */
    protected $attributes = ['status' => 'setup', 'demo' => false];

    protected static function booted(): void
    {
        // A demo property is one from creation to deletion (GLOSSARY: demo property).
        static::updating(function (self $property): void {
            if ($property->isDirty('demo')) {
                throw new LogicException('A property is a demo property only from creation.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'currency' => Currency::class,
            'jurisdiction_profile' => JurisdictionProfile::class,
            'status' => PropertyStatus::class,
            'went_live_at' => 'immutable_datetime',
            'taxes_reviewed_at' => 'immutable_datetime',
            'archived_at' => 'datetime',
            'business_date' => 'immutable_date',
            'housekeeping_inspection_required' => 'boolean',
            'stayover_service_on_close' => 'boolean',
            'registration_fields' => 'array',
            'demo' => 'boolean',
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
     * Real properties only: plan limits, reports and integration translators leave demo properties out.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function real(Builder $query): void
    {
        $query->where($this->qualifyColumn('demo'), false);
    }

    public function isDemo(): bool
    {
        return $this->demo;
    }

    public function isLive(): bool
    {
        return $this->status === PropertyStatus::Live;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
