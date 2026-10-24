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
        $line = $reservation->nightPrice($date) ?? throw ValidationException::withMessages(['reservation' => __('This reservation has no price line for the business date.')]);
        $currency = Currency::from($line->getAttribute('currency'));
        try {
            $tax = TaxCalculator::forProperty($property, $line->getAttribute('amount'), $currency, $date, $reservation->getAttribute('adults') + $reservation->getAttribute('children'), ChargeKind::Room);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reservation' => __(':reservation: :reason', ['reservation' => $reservation->reservation_number, 'reason' => $e->getMessage()])]);
        }
        // Tax-inclusive, but VFMS assesses the infrastructure levy itself.
        $price = $tax->net + array_sum(array_column(array_filter($tax->lines, fn (array $taxLine) => ($taxLine['category'] ?? null) !== 'hotel_levy'), 'amount'));
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
            'accommodationPrice' => $this->amount($price, $currency),
            'accommodationDiscount' => 0.0,
            'referenceNumber' => $this->referenceNumber($event, $reservation),
            ...($tourOperator ? ['tourRegNo' => $reservation->getAttribute('tour_operator_registration'), 'costOptions' => $this->costOptions($paysFor)] : []),
            'currency' => $this->currency($currency),
        ];
    }

    /**
     * §2.8 check-out, sent when the stay checks out: every guest still on the stay.
     *
     * @return array{documentNumberList: list<array{documentNumber: string|null}>, currency: string}
     */
    public function checkout(ReservationLifecycleEvent $event, Reservation $reservation, Property $property): array
    {
        return [
            'documentNumberList' => array_map(fn (array $guest) => ['documentNumber' => $guest['documentNo']], $this->guests($reservation)),
            'currency' => $this->currency(Currency::from($reservation->priceLines()->firstOrFail()->getAttribute('currency'))),
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

        return $ids->map(function (string $id) use ($guests): array {
            $guest = $guests[$id];
            /** @var IdentityDocument|null $document */
            $document = $guest->documents->sortBy('id')->first();
            $country = $document?->getAttribute('nationality') ?? $guest->getAttribute('nationality');

            return [
                'documentName' => $document?->getAttribute('type'),
                'documentNo' => $document?->getAttribute('number'),
                'country' => Countries::NAMES[$country] ?? $country,
                'fullName' => $guest->name,
            ];
        })->values()->all();
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
