<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Reservations\Contracts\LifecycleTranslator;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/**
 * Files a Zanzibar stay's lifecycle with VFMS: one ordered outbox message per call,
 * one stream per stay. The payload records the property's mode, never its credentials.
 */
final class VfmsTranslator implements LifecycleTranslator
{
    /** Lifecycle event => [VFMS request type, gateway path, payload builder]. Later calls add rows. */
    private const CALLS = [
        'checked_in' => ['ACCOMMODATION_REQUEST', 'accommodation/', 'accommodation'],
        'checked_out' => ['ACCOMMODATION_CHECK_OUT', 'guest/checkout/', 'checkout'],
        'extra_posted' => ['ACCOMMODATION_ADD_SERVICE', 'service/', 'services'],
        'room_moved' => ['ACCOMMODATION_CHANGE_ROOM', 'change/room/', 'changeRoom'],
        'nightly_price_changed' => ['CHANGEROOM_PRICE', 'changeRoomPrice/', 'changeRoomPrice'],
        'guest_added' => ['ACCOMMODATION_ADD_GUEST', 'guest/accommodation/', 'addGuest'],
        'guest_profile_changed' => ['GUEST_PROFILE', 'guest/changeProfile/', 'changeProfile'],
    ];

    public function __construct(private readonly VfmsPayloads $payloads, private readonly Outbox $outbox) {}

    public function translate(ReservationLifecycleEvent $event): void
    {
        [$requestType, $path, $builder] = self::CALLS[$event->type] ?? [null, null, null];
        if ($builder === null) {
            return;
        }
        if ($event->type === 'extra_posted' && (($event->snapshot['status'] ?? null) !== 'checked_in' || ! in_array($event->snapshot['category'] ?? null, PostExtraCharge::CATEGORIES, true))) {
            return;
        }
        $property = Property::query()->findOrFail($event->property_id);
        if ($property->demo || ! JurisdictionRules::usesVfms($property)) {
            return;
        }
        Tenancy::within(TenantContext::forProperty($property), function () use ($event, $property, $requestType, $path, $builder) {
            $reservation = Reservation::query()->findOrFail($event->getAttribute('reservation_id'));
            $this->outbox->record(VfmsAdapter::INTEGRATION, $reservation, $builder, [
                'mode' => VfmsCredential::for($property)->mode->value,
                'request_type' => $requestType,
                'path' => $path,
                'reservation_id' => $reservation->id,
                ...($event->type === 'extra_posted' ? ['posting_id' => $event->snapshot['posting_id']] : []),
                'body' => $this->payloads->{$builder}($event, $reservation, $property),
            ], "vfms:{$event->id}");
        });
    }
}
