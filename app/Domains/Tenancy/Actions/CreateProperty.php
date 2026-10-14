<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Adds a property to the organization in the current tenant context.
 */
class CreateProperty
{
    public function handle(?User $actor, PropertyDetails $details): Property
    {
        return DB::transaction(function () use ($actor, $details) {
            $property = Property::query()->create([...$details->toAttributes(), 'business_date' => now($details->timezone)->toDateString()]);

            Audit::record($actor, 'created', $property, 'Property created', [
                'result' => 'success',
                'property_id' => $property->getKey(),
                'attributes' => $property->only([
                    'name', 'property_type', 'address_line', 'city', 'region', 'postal_code',
                    'phone', 'email', 'timezone', 'currency', 'jurisdiction_profile', 'zra_hotel_code',
                ]),
            ]);

            return $property;
        });
    }
}
