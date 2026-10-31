<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Data\GoLiveGate;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
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
            self::ensureReady($locked);

            $deferredImports = JurisdictionRules::defersImportedReservationCreated($locked);
            $locked->forceFill(['status' => PropertyStatus::Live, 'went_live_at' => now(), 'went_live_by' => $actor->id])->save();
            if ($deferredImports) {
                Tenancy::within(TenantContext::forProperty($locked), function () use ($locked, $actor) {
                    Reservation::query()->where('property_id', $locked->id)->where('imported', true)
                        ->whereDoesntHave('lifecycleEvents', fn ($query) => $query->where('type', 'created'))
                        ->eachById(function (Reservation $reservation) use ($actor) {
                            app(RecordLifecycleEvent::class)->handle($reservation, 'created', $actor);
                        });
                });
            }
            Audit::record($actor, 'went_live', $locked, 'Property went live', [
                'property_id' => $locked->id,
                'attributes' => ['status' => PropertyStatus::Live->value, 'went_live_at' => $locked->went_live_at?->toIso8601String()],
            ]);

            return $locked;
        }, attempts: 3));
    }

    /** Refuses a demo property, and a property with failing go-live gates, naming them. */
    public static function ensureReady(Property $property): void
    {
        if ($property->isDemo()) {
            throw ValidationException::withMessages(['go_live' => __('A demo property can never go live.')]);
        }

        $failing = array_filter(JurisdictionRules::goLiveGates($property), fn (GoLiveGate $gate) => ! $gate->passed);
        if ($failing !== []) {
            throw ValidationException::withMessages(['go_live' => __('Not ready to go live. Still to do: :gates.', [
                'gates' => implode(', ', array_map(fn (GoLiveGate $gate) => $gate->label, $failing)),
            ])]);
        }
    }
}
