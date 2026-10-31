<?php

namespace App\Domains\Tenancy;

use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Data\GoLiveGate;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\Route;

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
            // A step links only where the member may go; the page asks them to get an owner for the rest.
            'items' => array_map(fn (array $item) => [...array_diff_key($item, ['allowed' => 0]), 'href' => $item['allowed'] ? $item['href'] : null], [
                ['key' => 'verify_email', 'label' => __('Verify email'), 'completed' => $actor->hasVerifiedEmail(), 'href' => route('profile.edit'), 'allowed' => true],
                ['key' => 'property_profile', 'label' => __('Complete the property profile'), 'completed' => $profileComplete, 'href' => route('properties.edit', $property), 'allowed' => $actor->can('update', $property)],
                [
                    'key' => 'rooms',
                    'label' => __('Configure rooms'),
                    'completed' => $passed['room_type']->passed && $passed['room']->passed,
                    'href' => route('rooms.index'),
                    'allowed' => $actor->can('viewAny', Room::class),
                    'items' => [
                        ['key' => 'room_type', 'label' => __('Add a room type'), 'completed' => $passed['room_type']->passed, 'href' => $actor->can('create', RoomType::class) ? route('room-types.create') : null],
                        ['key' => 'room', 'label' => __('Add rooms'), 'completed' => $passed['room']->passed, 'href' => $actor->can('create', Room::class) ? route('rooms.create') : null],
                    ],
                ],
                ['key' => 'rates', 'label' => __('Configure rates'), 'completed' => $passed['rate_plan']->passed && RatePrice::query()->where('property_id', $property->id)->exists(), 'href' => route($passed['rate_plan']->passed ? 'rate-calendar' : 'rate-plans.index'), 'allowed' => $actor->can('viewAny', RatePlan::class)],
                ['key' => 'taxes_reviewed', 'label' => __('Review taxes'), 'completed' => $property->taxes_reviewed_at !== null, 'href' => route('tax-rules.index'), 'allowed' => $actor->can('viewAny', TaxRule::class)],
                ['key' => 'invite_staff', 'label' => __('Invite staff'), 'completed' => $staffInvited, 'href' => route('invitations.create'), 'allowed' => $actor->can('create', Invitation::class)],
                ['key' => 'import_reservations', 'label' => __('Import future reservations'), 'completed' => $this->hasImportedReservations($property), 'href' => Route::has('imports.index') ? route('imports.index') : null, 'allowed' => $actor->can('create', ImportJob::class)],
                ['key' => 'sample_reservation', 'label' => __('Make a sample reservation'), 'completed' => ReservationLifecycleEvent::query()->where('property_id', $property->id)->where('test', true)->where('type', 'created')->exists(), 'href' => route('reservations.create'), 'allowed' => $actor->can('create', Reservation::class)],
            ]),
            'gates' => array_map(fn (GoLiveGate $gate) => [
                'key' => $gate->key,
                'label' => $gate->label,
                'passed' => $gate->passed,
                'href' => $gate->onPropertyForm && $actor->can('update', $property) ? route('properties.edit', $property).'#'.$gate->key : null,
            ], $gates),
            'goLiveRequested' => $property->go_live_requested_at !== null,
            'canGoLive' => $property->go_live_requested_at === null && $actor->can('goLive', $property) && collect($gates)->every(fn (GoLiveGate $gate) => $gate->passed),
            'canReviewTaxes' => $actor->can('reviewTaxes', $property),
            'receiptNotice' => JurisdictionRules::onboardingReceiptNotice($property),
        ];
    }

    private function hasImportedReservations(Property $property): bool
    {
        return Reservation::query()->where('property_id', $property->id)->where('imported', true)->exists();
    }
}
