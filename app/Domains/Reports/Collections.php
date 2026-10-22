<?php

namespace App\Domains\Reports;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @phpstan-type PaymentRow array{id: string, type: string, category: string, business_date: string, posted_at: string, receipt: array{id: string, number: string}|null, guest: string|null, reservation_number: string|null, room: string|null, method: string|null, reference: string|null, amount: int, currency: string, processed_by: string|null}
 */
final class Collections
{
    /** @return array{from: string, to: string} */
    public function range(Property $property, string $period): array
    {
        $date = CarbonImmutable::parse(BusinessDate::of($property));
        [$from, $to] = match ($period) {
            'this-month' => [$date->startOfMonth(), $date->endOfMonth()],
            'last-month' => [$date->subMonthNoOverflow()->startOfMonth(), $date->subMonthNoOverflow()->endOfMonth()],
            'this-year' => [$date->startOfYear(), $date->endOfYear()],
            default => [$date, $date],
        };

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }

    /** @return array<string, mixed> */
    public function received(Property $property, string $from, string $to): array
    {
        $payments = app(PropertyFinance::class)->payments(collect([$property]), $from, $to)[$property->id] ?? ['rows' => [], 'totals' => []];
        $payments['rows'] = collect($payments['rows'])->groupBy(fn (array $row): string => $row['method'].'/'.$row['currency'])
            ->map(fn ($rows): array => [
                'method' => $rows->first()['method'],
                'currency' => $rows->first()['currency'],
                ...collect(['payments', 'refunds', 'reversals', 'net'])->mapWithKeys(fn (string $key): array => [$key => (int) $rows->sum($key)])->all(),
            ])->values()->all();

        return $payments;
    }

    /**
     * Each payment, deposit and correction on the business dates, newest first, for the Received list.
     * Filtered by business date like the method totals above it, so rows and totals always agree;
     * a payment taken after local midnight but before the night audit belongs to the open business date.
     *
     * @return LengthAwarePaginator<int, PaymentRow>
     */
    public function payments(Property $property, string $from, string $to): LengthAwarePaginator
    {
        $query = Posting::query()->where('property_id', $property->id)->whereNotNull('method')->whereIn('type', ['payment', 'refund', 'reversal'])
            ->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)->orderByDesc('posted_at')->orderByDesc('id');
        $rows = $query->clone()->paginate(50, ['*'], 'payments_page')->withQueryString();
        // Lookups for this page's rows only, in bulk, so the list costs the same queries at any size.
        $folios = Folio::query()->whereIn('id', $rows->pluck('folio_id'))->pluck('reservation_id', 'id');
        $reservations = Reservation::query()->whereIn('id', $folios->values())->get();
        $guests = Reservation::guestNames($reservations);
        $rooms = Room::query()->whereIn('id', $reservations->pluck('room_id')->filter())->get(['id', 'number'])->keyBy('id');
        $reservations = $reservations->keyBy('id');
        $receipts = Receipt::query()->whereIn('posting_id', $rows->pluck('id'))->get(['id', 'posting_id', 'number'])->keyBy('posting_id');
        $actors = User::query()->whereIn('id', $rows->pluck('actor_id')->filter())->get(['id', 'name'])->keyBy('id');

        return $rows->through(function (Posting $posting) use ($folios, $reservations, $guests, $rooms, $receipts, $actors): array {
            $reservation = $reservations->get($folios->get($posting->folio_id));
            $receipt = $receipts->get($posting->id);

            return [
                'id' => $posting->id, 'type' => $posting->type, 'category' => $posting->category, 'business_date' => $posting->business_date->toDateString(),
                'posted_at' => $posting->posted_at->toIso8601String(), 'receipt' => $receipt === null ? null : ['id' => $receipt->id, 'number' => $receipt->number],
                'guest' => $reservation === null ? null : $guests->get($reservation->id), 'reservation_number' => $reservation?->reservation_number,
                'room' => $reservation?->room_id === null ? null : $rooms->get($reservation->room_id)?->number, 'method' => $posting->method, 'reference' => $posting->reference,
                // Money in is positive; a refund or a reversed payment is money back out.
                'amount' => -$posting->amount, 'currency' => $posting->currency->value, 'processed_by' => $posting->actor_id === null ? null : $actors->get($posting->actor_id)?->name,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function card(Property $property, string $period): array
    {
        $range = $this->range($property, $period);

        return [
            'period' => $period,
            ...$range,
            'received' => $this->received($property, $range['from'], $range['to']),
            'href' => route('payments.index', ['period' => $period]),
        ];
    }
}
