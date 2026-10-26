<?php

namespace App\Domains\FiscalReceipting\Models;

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\BelongsToProperty;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A property's VFMS credentials and Sandbox/Live mode. The token is write-only:
 * encrypted at rest, hidden from serialisation, and read only when sending.
 *
 * @property string $id
 * @property string $property_id
 * @property string|null $integration_id
 * @property string|null $registration_id
 * @property string|null $token
 * @property VfmsMode $mode
 * @property CarbonImmutable|null $live_since
 * @property CarbonImmutable|null $credentials_refused_at
 * @property string|null $updated_by
 */
#[Fillable(['integration_id', 'registration_id', 'token', 'mode', 'live_since', 'credentials_refused_at'])]
#[Hidden(['token'])]
class VfmsCredential extends Model
{
    use BelongsToProperty, HasUlids;

    protected $attributes = ['mode' => 'sandbox'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'mode' => VfmsMode::class,
            'live_since' => 'immutable_datetime',
            'credentials_refused_at' => 'immutable_datetime',
        ];
    }

    /** The property's saved row (the tenant scope narrows the query to it), or an unsaved Sandbox one when nothing is saved yet. */
    public static function for(Property $property): self
    {
        return Tenancy::within(TenantContext::forProperty($property), fn () => self::query()->first() ?? (new self)->forceFill([
            'organization_id' => $property->organization_id,
            'property_id' => $property->id,
        ]));
    }

    public function isComplete(): bool
    {
        return filled($this->integration_id) && filled($this->registration_id) && filled($this->token);
    }
}
