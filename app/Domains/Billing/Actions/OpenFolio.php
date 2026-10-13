<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Money\Currency;
use Illuminate\Validation\ValidationException;

/**
 * Opens another folio on a reservation, in any currency the property's
 * jurisdiction allows, to bill a second payer or currency separately.
 */
class OpenFolio
{
    public function handle(?User $actor, Reservation $reservation, string $currency): Folio
    {
        return Audit::validationRefusal($actor, $reservation, 'Folio refused', function () use ($actor, $reservation, $currency) {
            $allowed = JurisdictionRules::allowedCurrencies(Property::query()->findOrFail($reservation->property_id));
            $currency = Currency::tryFrom($currency);
            if ($currency === null || ! in_array($currency, $allowed, true)) {
                throw ValidationException::withMessages(['currency' => __('This property cannot keep folios in that currency.')]);
            }
            $folio = Folio::query()->create(['reservation_id' => $reservation->id, 'currency' => $currency]);
            Audit::record($actor, 'created', $folio, 'Folio opened', ['reservation_id' => $reservation->id, 'currency' => $currency->value]);

            return $folio;
        });
    }
}
