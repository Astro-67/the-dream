<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\IdentityDocument;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\TaxCalculator;
use App\Domains\Reservations\Enums\TourOperatorPaysFor;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Models\Property;
use App\Support\Countries;
use App\Support\Money\Currency;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Builds VFMS request bodies with the guide's field names and types (VFMS Hotel API
 * Guide v1.6). One method per call; later calls reuse guests(), amount() and currency().
 */
final class VfmsPayloads
{
    /**
     * §2.1 accommodation, sent at check-in.
     *
     * @return array<string, mixed>
     */
    public function accommodation(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $date = $event->business_date->toDateString();
        $room = Room::query()->findOrFail($reservation->room_id);
        $paysFor = $reservation->getAttribute('tour_operator_pays_for');
        $tourOperator = $reservation->getAttribute('booked_through') === 'tour-operator' && $paysFor instanceof TourOperatorPaysFor;

        return [
            'paymentPackage' => $reservation->getAttribute('meal_plan')?->value,
            'checkInDate' => $date,
            'guest' => $this->guests($reservation),
            'roomId' => $room->vfms_room_id,
            'roomName' => $room->number,
            // OTA stays are DIRECT (ADR 0005).
            'accommodationSource' => $tourOperator ? 'TOUR OPERATOR' : 'DIRECT',
            ...$this->roomPrice($reservation, $property, $date),
            'accommodationDiscount' => 0.0,
            'referenceNumber' => $this->referenceNumber($event, $reservation),
            ...($tourOperator ? ['tourRegNo' => $reservation->getAttribute('tour_operator_registration'), 'costOptions' => $this->costOptions($paysFor)] : []),
        ];
    }

    /** @return array<string, mixed> */
    public function changeRoom(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $room = Room::query()->findOrFail($reservation->room_id);

        return [
            'roomName' => $room->number,
            'roomId' => $room->vfms_room_id,
            'documentNumber' => $this->guests($reservation)[0]['documentNo'],
            'referenceNumber' => $this->referenceNumber($event, $reservation),
            ...$this->roomPrice($reservation, $property, $event->business_date->toDateString()),
            'accommodationDiscount' => 0.0,
        ];
    }

    /** @return array<string, mixed> */
    public function changeRoomPrice(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $room = Room::query()->findOrFail($reservation->room_id);
        $price = $this->roomPrice($reservation, $property, $event->getAttribute('snapshot')['date']);

        return ['rooms' => [['roomPrice' => $price['accommodationPrice'], 'hotelRoomRoom' => $room->vfms_room_id]]];
    }

    /** @return array{accommodationPrice: float, currency: string} */
    private function roomPrice(Reservation $reservation, Property $property, string $date): array
    {
        $line = $reservation->nightPrice($date) ?? throw ValidationException::withMessages(['reservation' => __('This reservation has no price line for the business date.')]);
        $currency = Currency::from($line->getAttribute('currency'));
        try {
            $tax = TaxCalculator::forProperty($property, $line->getAttribute('amount'), $currency, $date, $reservation->getAttribute('adults') + $reservation->getAttribute('children'), ChargeKind::Room);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reservation' => __(':reservation: :reason', ['reservation' => $reservation->reservation_number, 'reason' => $e->getMessage()])]);
        }
        // Tax-inclusive, but VFMS assesses the infrastructure levy itself.
        $price = $tax->net + array_sum(array_column(array_filter($tax->lines, fn (array $taxLine) => ($taxLine['category'] ?? null) !== 'hotel_levy'), 'amount'));

        return ['accommodationPrice' => $this->amount($price, $currency), 'currency' => $this->currency($currency)];
    }

    /**
     * §2.6 add guest: the new guest, filed against the lead guest's document.
     *
     * @return array<string, mixed>
     */
    public function addGuest(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $guests = $this->guests($reservation);
        $added = $this->guest(Guest::query()->with('documents')->findOrFail($event->snapshot['guest_id']));

        return [
            'firstGuestDocument' => $guests[0]['documentNo'],
            'documentName' => $added['documentName'],
            'documentNo' => $added['documentNo'],
            'arrivalDate' => $event->business_date->toDateString(),
            'referenceNumber' => $this->referenceNumber($event, $reservation),
            'country' => $added['country'],
            'fullName' => $added['fullName'],
        ];
    }

    /**
     * §2.7 change profile: the guest's corrected details, keyed by the document VFMS holds.
     *
     * @return array<string, mixed>
     */
    public function changeProfile(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $guest = $this->guest(Guest::query()->with('documents')->findOrFail($event->snapshot['guest_id']));
        $old = $event->snapshot['old_document_number'];

        return [
            'oldDocumentNo' => $old === null ? null : Crypt::decryptString($old),
            'newDocumentNo' => $guest['documentNo'],
            'fullName' => $guest['fullName'],
            'documentName' => $guest['documentName'],
            'country' => $guest['country'],
            'referenceNumber' => $this->referenceNumber($event, $reservation),
        ];
    }

    /**
     * Every guest on the stay, lead guest first, each with their first identity document.
     *
     * @return list<array{documentName: string|null, documentNo: string|null, country: string|null, fullName: string}>
     */
    public function guests(Reservation $reservation): array
    {
        $ids = ReservationGuest::query()->where('reservation_id', $reservation->id)->orderBy('id')->pluck('guest_id');
        $guests = Guest::query()->with('documents')->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn (string $id) => $this->guest($guests[$id]))->values()->all();
    }

    /**
     * One guest as VFMS names them, with their first identity document.
     *
     * @return array{documentName: string|null, documentNo: string|null, country: string|null, fullName: string}
     */
    public function guest(Guest $guest): array
    {
        /** @var IdentityDocument|null $document */
        $document = $guest->documents->sortBy('id')->first();
        $country = $document?->getAttribute('nationality') ?? $guest->getAttribute('nationality');

        return [
            'documentName' => $document?->getAttribute('type'),
            'documentNo' => $document?->getAttribute('number'),
            'country' => Countries::NAMES[$country] ?? $country,
            'fullName' => $guest->name,
        ];
    }

    /** Stable across retries: the message keeps the event it was built from. */
    public function referenceNumber(ReservationLifecycleEvent $event, Reservation $reservation): string
    {
        return $reservation->reservation_number.'-'.$event->sequence;
    }

    /** Minor units as the guide's Double in major units: TZS has none, USD and EUR two. */
    public function amount(int $minor, Currency $currency): float
    {
        return $currency === Currency::TZS ? (float) $minor : $minor / 100;
    }

    /** VFMS spells euro EURO. */
    public function currency(Currency $currency): string
    {
        return $currency === Currency::EUR ? 'EURO' : $currency->value;
    }

    /** 5 accommodation, 6 infrastructure levy, 7 other services, ascending. */
    public function costOptions(TourOperatorPaysFor $paysFor): string
    {
        return match ($paysFor) {
            TourOperatorPaysFor::RoomOnly => '5',
            TourOperatorPaysFor::RoomAndLevy => '5,6',
            TourOperatorPaysFor::RoomAndOtherServices => '5,7',
            TourOperatorPaysFor::RoomLevyAndOtherServices => '5,6,7',
        };
    }
}
