<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Records that a manager has reviewed the property's tax rules: the one go-live
 * gate with no data of its own to derive it from.
 */
class MarkTaxesReviewed
{
    public function handle(User $actor, Property $property): Property
    {
        return DB::transaction(function () use ($actor, $property) {
            $locked = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            if ($locked->taxes_reviewed_at !== null) {
                return $locked;
            }

            $locked->forceFill(['taxes_reviewed_at' => now(), 'taxes_reviewed_by' => $actor->id])->save();
            Audit::record($actor, 'taxes_reviewed', $locked, 'Taxes reviewed', ['property_id' => $locked->id]);

            return $locked;
        });
    }
}
