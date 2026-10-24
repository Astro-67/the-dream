<?php

namespace App\Domains\Tenancy\Jurisdiction;

use App\Domains\Billing\OpenFolios;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Enums\ZraTaxCategory;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\FutureReservations;
use App\Domains\Tenancy\Data\GoLiveGate;
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
    public static function usesVfms(Property $property): bool
    {
        return $property->jurisdiction_profile === JurisdictionProfile::Zanzibar;
    }

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
     */
    public static function ensureProfileCanChange(Property $property, JurisdictionProfile $to): void
    {
        if ($to === $property->jurisdiction_profile) {
            return;
        }

        if (FutureReservations::existFor($property)) {
            throw ValidationException::withMessages([
                'jurisdiction_profile' => __('This property has future confirmed reservations. Cancel or complete them before changing its jurisdiction profile.'),
            ]);
        }

        if (OpenFolios::existFor($property)) {
            throw ValidationException::withMessages([
                'jurisdiction_profile' => __('This property has open folios. Settle them and check out in-house guests before changing its jurisdiction profile.'),
            ]);
        }
    }

    /**
     * Common setup gates and the profile's required property registration.
     *
     * @return list<GoLiveGate>
     */
    public static function goLiveGates(Property $property): array
    {
        $id = $property->id;

        return [
            new GoLiveGate('room_type', __('At least one room type'), RoomType::query()->where('property_id', $id)->exists()),
            new GoLiveGate('room', __('At least one room'), Room::query()->where('property_id', $id)->active()->exists()),
            new GoLiveGate('rate_plan', __('At least one rate plan'), RatePlan::query()->where('property_id', $id)->whereNull('deactivated_at')->exists()),
            new GoLiveGate('taxes_reviewed', __('Taxes reviewed'), $property->taxes_reviewed_at !== null),
            ...($property->jurisdiction_profile === JurisdictionProfile::Zanzibar ? [
                new GoLiveGate('tin', __('TIN'), filled($property->tin), onPropertyForm: true),
                new GoLiveGate('z_number', __('Z-number'), filled($property->z_number), onPropertyForm: true),
                new GoLiveGate('zra_hotel_code', __('ZRA hotel code'), filled($property->zra_hotel_code), onPropertyForm: true),
                new GoLiveGate('star_rating', __('Star rating'), in_array($property->star_rating, [1, 2, 3, 4, 5], true), onPropertyForm: true),
                ...($property->settings()['tax_settings']['zra_tax_category'] === ZraTaxCategory::Vat->value ? [
                    new GoLiveGate('vrn', __('VRN'), filled($property->vrn), onPropertyForm: true),
                ] : []),
            ] : []),
        ];
    }

    public static function defaultInfrastructureLevy(Property $property): ?int
    {
        if ($property->jurisdiction_profile !== JurisdictionProfile::Zanzibar) {
            return null;
        }

        return match ($property->star_rating) {
            4, 5 => 500,
            2, 3 => 400,
            default => 200,
        };
    }

    public static function onboardingReceiptNotice(Property $property): ?string
    {
        return $property->jurisdiction_profile === JurisdictionProfile::MainlandTanzania
            ? __('Thedream issues non-fiscal receipts for mainland properties.')
            : null;
    }

    public static function defersImportedReservationCreated(Property $property): bool
    {
        return $property->jurisdiction_profile === JurisdictionProfile::Zanzibar && ! $property->isLive();
    }

    public static function requiresPassport(Property $property, ?string $country): bool
    {
        return $property->settings()['front_desk_settings']['passport_required_for_foreign_guests']
            && filled($country) && $country !== 'TZ';
    }

    private static function of(JurisdictionProfile $profile): ProfileRules
    {
        return match ($profile) {
            JurisdictionProfile::Zanzibar => new ZanzibarRules,
            JurisdictionProfile::MainlandTanzania => new MainlandTanzaniaRules,
        };
    }
}
