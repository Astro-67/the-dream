<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\TaxCalculator;
use App\Domains\Reservations\Enums\MealPlan;
use App\Domains\Reservations\Enums\PayingInstitutionType;
use App\Domains\Reservations\Enums\TourOperatorPaysFor;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Models\Property;
use App\Support\Countries;
use App\Support\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Builds VFMS request bodies with the guide's field names and types (VFMS Hotel API
 * Guide v1.6). One method per call; later calls reuse guests(), amount() and currency().
 */
final class VfmsPayloads
{
    /**
     * @param  array<string, mixed>|null  $acceptedBusiness
     * @return array<string, mixed>
     */
    public function correctionIdentity(Property $property, ?array $acceptedBusiness = null): array
    {
        $credential = VfmsCredential::for($property);

        return Validator::make([
            'b_unit_name' => filled($acceptedBusiness['businessName'] ?? null) ? $acceptedBusiness['businessName'] : $property->name,
            'email' => $property->email,
            'phone_no' => $property->phone,
            'zrb_number' => $property->z_number,
            // The unit ZRA reported for the accepted filing (Sandbox: its shared test unit); Live falls back to settings.
            'unitId' => ($credential->mode === VfmsMode::Live ? $credential->unit_id : null) ?? ($acceptedBusiness['businessUnitId'] ?? null),
        ], [
            'b_unit_name' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'phone_no' => ['required', 'string', 'max:32'],
            'zrb_number' => ['required', 'string', 'max:64'],
            'unitId' => ['required', 'integer', 'min:1', 'max:9223372036854775807'],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $business
     * @return array<string, mixed>
     */
    public function errorSave(Property $property, IssuedDocument $old, IssuedDocument $new, string $reason, array $business): array
    {
        return [...$this->correctionIdentity($property, $business),
            'old_receipt_number' => $old->number, 'new_receipt_number' => $new->number, 'reasons' => $reason,
        ];
    }

    /** @return array<string, mixed> */
    public function services(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $posting = Posting::query()->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $reservation->id))
            ->findOrFail($event->snapshot['posting_id']);

        return [
            'documentNumber' => $this->guests($reservation)[0]['documentNo'],
            'service' => [[
                'itemName' => $posting->description ?? $posting->category,
                'price' => $this->amount($posting->unit_price, $posting->currency),
                'quantity' => $posting->quantity,
                'discount' => $this->amount($posting->discount, $posting->currency),
            ]],
            'referenceNumber' => $this->referenceNumber($event, $reservation),
            'type' => 'CS',
            'currency' => $this->currency($posting->currency),
        ];
    }

    /**
     * A general receipt (`WS`): ZRA names the customer from `documentNumber`, so it carries the payer's
     * name, never a guest's document (ZRA would attach the sale to that guest's stay). One currency.
     *
     * @param  iterable<Posting>  $postings
     * @return array<string, mixed>
     */
    public function generalReceipt(string $customer, iterable $postings, string $referenceNumber): array
    {
        $service = [];
        foreach ($postings as $posting) {
            $currency = $posting->currency;
            $service[] = [
                'itemName' => $posting->description ?? $posting->category,
                'price' => $this->amount($posting->unit_price, $posting->currency),
                'quantity' => $posting->quantity,
                'discount' => $this->amount($posting->discount, $posting->currency),
            ];
        }

        return ['documentNumber' => $customer, 'service' => $service, 'referenceNumber' => $referenceNumber, 'type' => 'WS', 'currency' => $this->currency($currency ?? throw new InvalidArgumentException('A general receipt needs at least one item.'))];
    }

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
        $institution = $reservation->getAttribute('paying_institution_type');

        return [
            'paymentPackage' => match ($reservation->meal_plan) {
                MealPlan::RoomOnly => 'RO',
                MealPlan::BedAndBreakfast => 'BB',
                MealPlan::HalfBoard => 'HB',
                MealPlan::FullBoard => 'FB',
                MealPlan::AllInclusive => 'AI',
            },
            'checkInDate' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'guest' => array_map(fn (array $guest) => [...$guest, 'arrivalDate' => $date], $this->guests($reservation)),
            'roomId' => $room->vfms_room_id,
            'roomName' => $room->number,
            // OTA stays are DIRECT (ADR 0005).
            'accommodationSource' => $tourOperator ? 'TOUR OPERATOR' : 'DIRECT',
            ...$this->roomPrice($reservation, $property, $date),
            'accommodationDiscount' => 0.0,
            'referenceNumber' => $this->referenceNumber($event, $reservation),
            ...($tourOperator ? ['tourRegNo' => $reservation->getAttribute('tour_operator_registration'), 'costOptions' => $this->costOptions($paysFor)] : []),
            ...($institution instanceof PayingInstitutionType ? ['accommodationType' => $institution->vfmsValue(), 'zrbNumber' => $reservation->getAttribute('paying_institution_zrb_number')] : []),
        ];
    }

    /** @return array<string, mixed> */
    public function advanceBooking(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $payment = Posting::query()->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $reservation->id))
            ->findOrFail($event->snapshot['posting_id']);
        $room = Room::query()->findOrFail($reservation->room_id);
        $price = $this->roomPrice($reservation, $property, $reservation->arrival->toDateString());
        $paysFor = $reservation->getAttribute('tour_operator_pays_for');
        $options = $paysFor instanceof TourOperatorPaysFor ? $this->costOptions($paysFor) : '';
        $details = $event->snapshot['advance_payment'];
        // ZRA counts whole 24-hour days between the dates, so both use local midnight (test server, 3 Nov 2026).
        $day = fn (CarbonImmutable $date) => CarbonImmutable::parse($date->toDateString(), $property->timezone)->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'bookingCompany' => $details['name'],
            'email' => $details['email'] ?? '', 'phoneNumber' => $details['phone'] ?? '',
            'amountToBePaid' => $this->amount(-$payment->amount, $payment->currency),
            'noOfGuest' => $reservation->adults,
            'currency' => $this->currency($payment->currency),
            'startDate' => $day($reservation->arrival),
            'endDate' => $day($reservation->departure),
            'rooms' => [['roomId' => $room->vfms_room_id, 'roomPrice' => $price['accommodationPrice'], 'noOfGuestPerRoom' => $reservation->adults]],
            'customerType' => 2, 'salesCustomer' => $details['name'],
            'regNumber' => $details['reg_number'] ?? '',
            'referenceNumber' => $this->referenceNumber($event, $reservation),
            'isIncludeInfrastructure' => str_contains($options, '6'),
            'isIncludeAccommodation' => true,
            'isIncludeOtherService' => str_contains($options, '7'),
            'isZraRegistered' => $details['is_zra_registered'],
        ];
    }

    /** @return array<string, mixed> */
    public function advanceAccommodation(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $normal = $this->accommodation($event, $reservation, $property);
        $advance = ($event->snapshot['advance_booking']['source'] ?? null) === 'external'
            ? VfmsAdvance::checkInDetails($reservation, $property)['advance_booking']
            : $event->snapshot['advance_booking'];

        return [
            'referenceNumber' => $normal['referenceNumber'],
            'roomId' => $normal['roomId'], 'roomName' => $normal['roomName'],
            'accomodationPrice' => $normal['accommodationPrice'], 'accomodationDiscount' => 0.0,
            'salesCurrency' => $normal['currency'],
            'checkInDate' => $normal['checkInDate'],
            'checkOutDate' => $this->stayDate($reservation, $property, false),
            'bookingNumber' => $advance['bookingNumber'],
            'receiptno' => $advance['receiptNumber'],
            'guest' => $this->guests($reservation),
        ];
    }

    private function stayDate(Reservation $reservation, Property $property, bool $arrival): string
    {
        $date = $arrival ? $reservation->arrival : $reservation->departure;
        $time = $property->settings()['front_desk_settings'][$arrival ? 'default_check_in_time' : 'default_check_out_time'];

        return CarbonImmutable::parse($date->toDateString().' '.$time, $property->timezone)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @return array<string, mixed> */
    public function changeRoom(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $room = Room::query()->where('property_id', $property->id)->findOrFail($event->snapshot['room_id'] ?? null);

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
        $room = Room::query()->where('property_id', $property->id)->findOrFail($event->snapshot['room_id'] ?? null);
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
     * §2.8 check-out, sent when the stay checks out: every guest still on the stay.
     *
     * @return array{documentNumberList: list<array{documentNumber: string|null}>, currency: string}
     */
    public function checkout(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        return $this->checkoutOf($reservation, $this->guests($reservation, inHouseOnly: true));
    }

    /**
     * §2.8 check-out for one guest leaving an in-house stay (Guest departure).
     *
     * @return array{documentNumberList: list<array{documentNumber: string|null}>, currency: string}
     */
    public function guestDeparture(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        return $this->checkoutOf($reservation, [$this->guest(Guest::query()->findOrFail($event->snapshot['guest_id']))]);
    }

    /**
     * @param  list<array{documentNo: string|null}>  $guests
     * @return array{documentNumberList: list<array{documentNumber: string|null}>, currency: string}
     */
    private function checkoutOf(Reservation $reservation, array $guests): array
    {
        return [
            'documentNumberList' => array_map(fn (array $guest) => ['documentNumber' => $guest['documentNo']], $guests),
            // The stay's first folio: an arrival-day early departure has no price lines left.
            'currency' => $this->currency(Folio::query()->where('reservation_id', $reservation->id)->orderBy('created_at')->orderBy('id')->firstOrFail()->currency),
        ];
    }

    /**
     * §2.6 add guest: the new guest, filed against the lead guest's document.
     *
     * @return array<string, mixed>
     */
    public function addGuest(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        $guests = $this->guests($reservation);
        $added = $this->guest(Guest::query()->findOrFail($event->snapshot['guest_id']));

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
        $guest = $this->guest(Guest::query()->findOrFail($event->snapshot['guest_id']));
        $old = $event->snapshot['old_document_number'];

        return [
            'oldDocumentNo' => Crypt::decryptString($old),
            'newDocumentNo' => $guest['documentNo'],
            'fullName' => $guest['fullName'],
            'documentName' => $guest['documentName'],
            'country' => $guest['country'],
            'referenceNumber' => $this->referenceNumber($event, $reservation),
        ];
    }

    /**
     * Every guest on the stay, lead guest first, each with their first identity document;
     * $inHouseOnly leaves out guests who have already departed.
     *
     * @return list<array{documentName: string|null, documentNo: string|null, country: string|null, fullName: string}>
     */
    public function guests(Reservation $reservation, bool $inHouseOnly = false): array
    {
        $ids = ReservationGuest::query()->where('reservation_id', $reservation->id)->when($inHouseOnly, fn ($query) => $query->whereNull('departed_at'))->orderBy('id')->pluck('guest_id');
        $guests = Guest::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn (string $id) => $this->guest($guests[$id]))->values()->all();
    }

    /**
     * One guest as VFMS names them: the authority profile, country spelled out.
     *
     * @return array{documentName: string|null, documentNo: string|null, country: string|null, fullName: string}
     */
    public function guest(Guest $guest): array
    {
        $profile = $guest->authorityProfile();

        return [
            'documentName' => $profile['type'] === null ? null : strtoupper($profile['type']),
            'documentNo' => $profile['number'],
            'country' => $profile['country'] === null ? null : strtoupper(Countries::NAMES[$profile['country']] ?? $profile['country']),
            'fullName' => $profile['name'],
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

    /** The guide's Double in major units back to minor units, the inverse of amount(). */
    public function minor(float|int $major, Currency $currency): int
    {
        return (int) round($currency === Currency::TZS ? $major : $major * 100);
    }

    /** VFMS spells euro EURO. */
    public function currency(Currency $currency): string
    {
        return $currency === Currency::EUR ? 'EURO' : $currency->value;
    }

    /** VFMS's currency spelling back to ours, the inverse of currency(). */
    public function fromVfmsCurrency(string $currency): Currency
    {
        return $currency === 'EURO' ? Currency::EUR : Currency::from($currency);
    }

    /** 5 accommodation, 6 infrastructure levy, 7 other services, ascending. */
    public function costOptions(TourOperatorPaysFor $paysFor): string
    {
        return match ($paysFor) {
            TourOperatorPaysFor::RoomOnly => '5',
            TourOperatorPaysFor::RoomAndLevy => '56',
            TourOperatorPaysFor::RoomAndOtherServices => '57',
            TourOperatorPaysFor::RoomLevyAndOtherServices => '567',
        };
    }
}
