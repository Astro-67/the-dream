<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Records a go-live request once every go-live gate passes (GLOSSARY: go-live request); the property stays in setup
 * until a platform administrator approves it. Refused with the failing gates named, and always for a demo property.
 * Requesting again while a request waits changes nothing.
 */
class RequestGoLive
{
    public function handle(User $actor, Property $property): Property
    {
        return Audit::validationRefusal($actor, $property, 'Go-live request refused', fn () => DB::transaction(function () use ($actor, $property) {
            $locked = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            if ($locked->isLive() || $locked->go_live_requested_at !== null) {
                return $locked;
            }
            GoLive::ensureReady($locked);

            $locked->forceFill(['go_live_requested_at' => now(), 'go_live_requested_by' => $actor->id])->save();
            Audit::record($actor, 'go_live_requested', $locked, 'Go-live requested', [
                'property_id' => $locked->id,
                'attributes' => ['go_live_requested_at' => $locked->go_live_requested_at?->toIso8601String()],
            ]);

            return $locked;
        }, attempts: 3));
    }
}
