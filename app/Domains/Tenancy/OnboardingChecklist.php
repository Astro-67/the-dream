<?php

namespace App\Domains\Tenancy;

use App\Domains\Rates\Models\RatePrice;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Data\GoLiveGate;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class OnboardingChecklist
{
    /** @return array<string, mixed>|null */
    public function forProperty(Property $property, User $actor): ?array
    {
        if ($property->isLive()) {
            return null;
        }

        $gates = JurisdictionRules::goLiveGates($property);
        $passed = collect($gates)->keyBy('key');
        $staffInvited = Invitation::query()
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNotNull('accepted_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query->where('all_properties', true)->orWhereJsonContains('property_ids', $property->id))
            ->exists();
        $profileComplete = collect(['name', 'address_line', 'city', 'region', 'phone', 'email', 'timezone', 'currency'])
            ->every(fn (string $field) => filled($property->getAttribute($field)));

        return [
            'propertyId' => $property->id,
            'propertyName' => $property->name,
            'items' => [
                ['key' => 'verify_email', 'label' => __('Verify email'), 'completed' => $actor->hasVerifiedEmail(), 'href' => route('profile.edit')],
                ['key' => 'property_profile', 'label' => __('Complete the property profile'), 'completed' => $profileComplete, 'href' => route('properties.edit', $property)],
                ['key' => 'rooms', 'label' => __('Configure rooms'), 'completed' => $passed['room_type']->passed && $passed['room']->passed, 'href' => route('rooms.index')],
                ['key' => 'rates', 'label' => __('Configure rates'), 'completed' => $passed['rate_plan']->passed && RatePrice::query()->where('property_id', $property->id)->exists(), 'href' => route($passed['rate_plan']->passed ? 'rate-calendar' : 'rate-plans.index')],
                ['key' => 'taxes_reviewed', 'label' => __('Review taxes'), 'completed' => $property->taxes_reviewed_at !== null, 'href' => route('tax-rules.index')],
                ['key' => 'invite_staff', 'label' => __('Invite staff'), 'completed' => $staffInvited, 'href' => route('invitations.create')],
                ['key' => 'import_reservations', 'label' => __('Import future reservations'), 'completed' => $this->hasImportedReservations($property), 'href' => Route::has('imports.index') ? route('imports.index') : null],
                ['key' => 'sample_reservation', 'label' => __('Make a sample reservation'), 'completed' => ReservationLifecycleEvent::query()->where('property_id', $property->id)->where('test', true)->where('type', 'created')->exists(), 'href' => route('reservations.create')],
            ],
            'gates' => $gates,
            'canGoLive' => $actor->can('goLive', $property) && collect($gates)->every(fn (GoLiveGate $gate) => $gate->passed),
            'canReviewTaxes' => $actor->can('reviewTaxes', $property),
            'receiptNotice' => JurisdictionRules::onboardingReceiptNotice($property),
        ];
    }

    private function hasImportedReservations(Property $property): bool
    {
        // ponytail: #98 owns the imported flag; remove this guard once its migration ships.
        return Schema::hasColumn('reservations', 'imported')
            && Reservation::query()->where('property_id', $property->id)->where('imported', true)->exists();
    }
}
