<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived property back into operation: it returns to the switcher
 * and operational lists for the members who may act in it.
 */
class RestoreProperty
{
    public function handle(?User $actor, Property $property): Property
    {
        if ($property->archived_at === null) {
            return $property;
        }

        return DB::transaction(function () use ($actor, $property) {
            $property->archived_at = null;
            $property->save();

            Audit::record($actor, 'restored', $property, 'Property restored', [
                'property_id' => $property->getKey(),
            ]);

            return $property;
        });
    }
}
