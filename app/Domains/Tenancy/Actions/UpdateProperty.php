<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Changes a property's details and records the changed fields, before and
 * after, in the audit trail.
 */
class UpdateProperty
{
    public function handle(?User $actor, Property $property, PropertyDetails $details): Property
    {
        return DB::transaction(function () use ($actor, $property, $details) {
            $property->fill($details->toAttributes());
            $changed = array_keys($property->getDirty());

            if ($changed === []) {
                return $property;
            }

            $old = array_map(fn (string $key) => $property->getOriginal($key), array_combine($changed, $changed));
            $property->save();

            Audit::record($actor, 'updated', $property, 'Property updated', [
                'property_id' => $property->getKey(),
                'old' => $old,
                'attributes' => $property->only($changed),
            ]);

            return $property;
        });
    }
}
