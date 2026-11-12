<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Actions\IssueGeneralReceipt;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Reservations\Contracts\LifecycleTranslator;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/**
 * Files a Zanzibar stay's lifecycle with VFMS: one ordered outbox message per call,
 * one stream per stay. The payload records the property's mode, never its credentials.
 */
final class VfmsTranslator implements LifecycleTranslator
{
    /** Lifecycle event => [VFMS request type, gateway path, payload builder]. Later calls add rows. */
    public const CALLS = [
        'advance_payment_recorded' => ['RESERVATION_BOOKING', 'reservation/booking/', 'advanceBooking'],
        'checked_in' => ['ACCOMMODATION_REQUEST', 'accommodation/', 'accommodation'],
        'checked_out' => ['ACCOMMODATION_CHECK_OUT', 'guest/checkout/', 'checkout'],
        'extra_posted' => ['ACCOMMODATION_ADD_SERVICE', 'service/', 'services'],
        'room_moved' => ['ACCOMMODATION_CHANGE_ROOM', 'change/room/', 'changeRoom'],
        'nightly_price_changed' => ['CHANGEROOM_PRICE', 'changeRoomPrice/', 'changeRoomPrice'],
        'guest_added' => ['ACCOMMODATION_ADD_GUEST', 'guest/accommodation/', 'addGuest'],
        'guest_profile_changed' => ['GUEST_PROFILE', 'guest/changeProfile/', 'changeProfile'],
        'guest_departed' => ['ACCOMMODATION_CHECK_OUT', 'guest/checkout/', 'guestDeparture'],
    ];

    public const CORRECTION_CALL = ['ERROR_SAVE', 'new', 'errorSave'];

    public function __construct(private readonly VfmsPayloads $payloads, private readonly Outbox $outbox) {}

    /** The action holds the stay lock and transaction; a correction is not a stay lifecycle event.
     * @param  array<string, mixed>  $business
     */
    public function recordCorrection(string $id, Reservation $stay, Property $property, IssuedDocument $old, IssuedDocument $new, string $reason, array $business): OutboxMessage
    {
        [$requestType, $path, $builder] = self::CORRECTION_CALL;
        $body = $this->payloads->errorSave($property, $old, $new, $reason, $business);

        return $this->outbox->record(VfmsAdapter::INTEGRATION, $stay, $builder, [
            'mode' => VfmsCredential::for($property)->mode->value, 'unit_id' => $body['unitId'], 'request_type' => $requestType, 'path' => $path,
            'reservation_id' => $stay->id, 'correction_id' => $id,
            'body' => $body,
        ], "vfms-correction:{$id}");
    }

    /** @return array{string, string, string}|null */
    public static function callFor(ReservationLifecycleEvent $event): ?array
    {
        return $event->type === 'checked_in' && isset($event->snapshot['advance_booking'])
            ? ['ACCOMMODATION_BOOKING_REQUEST', 'accommodation/booking/', 'advanceAccommodation']
            : (self::CALLS[$event->type] ?? null);
    }

    public function translate(ReservationLifecycleEvent $event): void
    {
        [$requestType, $path, $builder] = self::callFor($event) ?? [null, null, null];
        if ($builder === null) {
            return;
        }
        // Spec #249: an institution's extras are held for its general receipt; a departed guest's get one now.
        $departed = ($event->snapshot['status'] ?? null) === 'checked_out';
        if ($event->type === 'extra_posted' && (! in_array($event->snapshot['status'] ?? null, ['checked_in', 'checked_out'], true)
            || ($event->snapshot['paying_institution_type'] ?? null) !== null
            || ! in_array($event->snapshot['category'] ?? null, PostExtraCharge::CATEGORIES, true))) {
            return;
        }
        // §2.7 is keyed on the document VFMS holds; with none, VFMS can never accept the change.
        if ($event->type === 'guest_profile_changed' && ($event->snapshot['old_document_number'] ?? null) === null) {
            return;
        }
        $property = Property::query()->real()->find($event->property_id);
        if ($property === null || ! JurisdictionRules::usesVfms($property)) {
            return;
        }
        Tenancy::within(TenantContext::forProperty($property), function () use ($event, $property, $requestType, $path, $builder, $departed) {
            $reservation = Reservation::query()->findOrFail($event->getAttribute('reservation_id'));
            if ($event->type === 'extra_posted' && $departed) {
                $posting = Posting::query()->where('property_id', $property->id)->whereKey($event->snapshot['posting_id']);
                // The lead guest's ZRA name, else the booker; ZRA refuses an empty customer.
                $customer = $this->payloads->guests($reservation)[0]['fullName'] ?? Reservation::guestNames(collect([$reservation]))->get($reservation->id) ?? 'Cash customer';
                app(IssueGeneralReceipt::class)->issue($event->actor, $reservation, $customer, $posting);

                return;
            }
            $this->outbox->record(VfmsAdapter::INTEGRATION, $reservation, $builder, [
                'mode' => VfmsCredential::for($property)->mode->value,
                'unit_id' => VfmsCredential::for($property)->unit_id,
                'request_type' => $requestType,
                'path' => $path,
                'reservation_id' => $reservation->id,
                ...(in_array($event->type, ['extra_posted', 'advance_payment_recorded'], true) ? ['posting_id' => $event->snapshot['posting_id']] : []),
                'body' => $this->payloads->{$builder}($event, $reservation, $property),
            ], "vfms:{$event->id}");
        });
    }
}
