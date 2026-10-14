<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Data\GoLiveGate;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a property from setup to live once every go-live gate passes (GLOSSARY: go-live).
 * Refused with the failing gates named, and always for a demo property. Going live again changes nothing.
 */
class GoLive
{
    public function handle(User $actor, Property $property): Property
    {
        return Audit::validationRefusal($actor, $property, 'Go-live refused', fn () => DB::transaction(function () use ($actor, $property) {
            $locked = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            if ($locked->isLive()) {
                return $locked;
            }
            if ($locked->isDemo()) {
                throw ValidationException::withMessages(['go_live' => __('A demo property can never go live.')]);
            }

            $failing = array_filter(JurisdictionRules::goLiveGates($locked), fn (GoLiveGate $gate) => ! $gate->passed);
            if ($failing !== []) {
                throw ValidationException::withMessages(['go_live' => __('Not ready to go live. Still to do: :gates.', [
                    'gates' => implode(', ', array_map(fn (GoLiveGate $gate) => $gate->label, $failing)),
                ])]);
            }

            $locked->forceFill(['status' => PropertyStatus::Live, 'went_live_at' => now(), 'went_live_by' => $actor->id])->save();
            Audit::record($actor, 'went_live', $locked, 'Property went live', [
                'property_id' => $locked->id,
                'attributes' => ['status' => PropertyStatus::Live->value, 'went_live_at' => $locked->went_live_at?->toIso8601String()],
            ]);

            return $locked;
        }));
    }
}
