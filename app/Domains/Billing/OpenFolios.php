<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Actions\CloseCustomerAccount;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/**
 * Asks whether a property still has folios that aren't settled (glossary: Folio).
 */
final class OpenFolios
{
    /** Whether any folio of the property has a non-zero balance. */
    public static function withBalanceExistFor(Property $property): bool
    {
        if (! $property->exists) {
            return false;
        }

        return Tenancy::within(TenantContext::forOrganization($property->organization_id), fn () => Posting::query()
            ->select('folio_id')
            ->where('property_id', $property->id)
            ->groupBy('folio_id')
            ->havingRaw('SUM(amount) <> 0')
            ->exists());
    }

    /**
     * Whether a folio has an in-house guest, an open customer account, or an unsettled balance.
     * Folios of future reservations are left to the future-reservation guard.
     */
    public static function existFor(Property $property): bool
    {
        if (! $property->exists) {
            return false;
        }

        return self::withBalanceExistFor($property) || self::heldAccountExtrasExistFor($property) || Tenancy::within(TenantContext::forOrganization($property->organization_id), fn () => Folio::query()
            ->where('property_id', $property->id)
            ->where(fn ($query) => $query
                ->whereIn('reservation_id', Reservation::query()->select('id')->where('property_id', $property->id)->where('status', ReservationStatus::CheckedIn))
                ->orWhereIn('customer_account_id', CustomerAccount::query()->select('id')->where('property_id', $property->id)->where('status', 'open')))
            ->exists());
    }

    public static function heldAccountExtrasExistFor(Property $property): bool
    {
        if ($property->isDemo() || ! $property->isLive() || ! JurisdictionRules::usesVfms($property)) {
            return false;
        }

        return $property->exists && Tenancy::within(TenantContext::forOrganization($property->organization_id), function () use ($property) {
            foreach (CustomerAccount::query()->where('property_id', $property->id)->cursor() as $account) {
                if (CloseCustomerAccount::held($account)->exists()) {
                    return true;
                }
            }

            return false;
        });
    }
}
