<?php

namespace App\Domains\Tenancy\Jurisdiction;

use App\Domains\Reservations\FutureReservations;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Models\Property;
use App\Support\Money\Currency;
use Illuminate\Validation\ValidationException;

/**
 * Answers named jurisdiction questions about a property (ADR 0014). This is
 * the only place that branches on a JurisdictionProfile case; domains ask a
 * question here instead of comparing profiles. Add a question when a domain
 * first needs one.
 */
final class JurisdictionRules
{
    /**
     * Currencies the property may sell and keep folios in.
     *
     * @return non-empty-list<Currency>
     */
    public static function allowedCurrencies(Property $property): array
    {
        return self::of($property->jurisdiction_profile)->allowedCurrencies();
    }

    /**
     * Identity document fields (IdentityDocument::FIELDS) every guest must
     * have before check-in.
     *
     * @return list<string>
     */
    public static function requiredRegistrationFields(Property $property): array
    {
        return self::of($property->jurisdiction_profile)->requiredRegistrationFields($property->registration_fields ?? []);
    }

    /**
     * Whether a property under this profile chooses its own registration
     * fields. Takes the profile so a property form can ask before saving.
     */
    public static function configuresRegistrationFields(JurisdictionProfile $profile): bool
    {
        return self::of($profile)->configuresRegistrationFields();
    }

    /**
     * Whether a posted cancellation or no-show fee is a fiscal exception
     * candidate: a charge with no path into the fiscal system (M3).
     */
    public static function marksFeesAsFiscalExceptions(Property $property): bool
    {
        return self::of($property->jurisdiction_profile)->marksFeesAsFiscalExceptions();
    }

    /**
     * Whether a property under this profile records a ZRA hotel code. Takes
     * the profile so a property form can ask before the property is saved.
     */
    public static function hasZraHotelCode(JurisdictionProfile $profile): bool
    {
        return self::of($profile)->hasZraHotelCode();
    }

    /**
     * Refuses a jurisdiction profile change that would break what depends on
     * the current profile (PRD §6: not while the property has future
     * reservations or open folios). UpdateProperty calls it before saving.
     *
     * ponytail: open folios are not checked until Spec 6 adds that guard.
     */
    public static function ensureProfileCanChange(Property $property, JurisdictionProfile $to): void
    {
        if ($to !== $property->jurisdiction_profile && FutureReservations::existFor($property)) {
            throw ValidationException::withMessages([
                'jurisdiction_profile' => __('This property has future confirmed reservations. Cancel or complete them before changing its jurisdiction profile.'),
            ]);
        }
    }

    private static function of(JurisdictionProfile $profile): ProfileRules
    {
        return match ($profile) {
            JurisdictionProfile::Zanzibar => new ZanzibarRules,
            JurisdictionProfile::MainlandTanzania => new MainlandTanzaniaRules,
        };
    }
}
