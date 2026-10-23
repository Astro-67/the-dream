<?php

namespace App\Domains\Reports;

use App\Domains\Billing\FolioSummary;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Finance reports for one property: a snapshot of folio balances now, and payments
 * over a business-date range. Amounts stay in minor units of their own currency.
 */
final class PropertyFinance
{
    /**
     * @return array{
     *     folios: LengthAwarePaginator<int, array{id: string, propertyId: string, reservationNumber: string, status: string, departure: string, departed: bool, currency: string, balance: int, guest: string|null, room: string|null, charges: int, netPayments: int, notYetCharged: bool}>,
     *     openAfterDeparture: list<array{currency: string, folios: int, balance: int}>,
     *     payments: array{rows: list<array{method: string, currency: string, shiftId: string|null, cashier: string|null, shiftOpenedAt: string|null, payments: int, refunds: int, reversals: int, net: int}>, totals: list<array{currency: string, payments: int, refunds: int, reversals: int, net: int}>},
     * }
     */
    public function forProperty(Property $property, string $from, string $to, bool $settled = false): array
    {
        $property = Property::query()->real()->findOrFail($property->id);
        $properties = collect([$property]);
        $summary = $this->summariesForProperties($properties, $from, $to)[$property->id];

        return ['folios' => $this->folios($properties, $summary['unsettledFolios'], $settled, $from, $to), 'openAfterDeparture' => $summary['openAfterDeparture'], 'payments' => $summary['payments']];
    }

    /**
     * Every folio of the given real properties whose balance is not zero, 50 to a page, with its
     * guest, room and posted charges and net payments from the shared folio summary. With
     * $settled, folios whose stay departs from $from to $to are listed too, however settled.
     * A caller that already counted the unsettled folios passes $total, saving the count query;
     * a settled list counts afresh, since it holds rows that count doesn't.
     *
     * @param  Collection<int, Property>  $properties
     * @return LengthAwarePaginator<int, array{id: string, propertyId: string, reservationNumber: string, status: string, departure: string, departed: bool, currency: string, balance: int, guest: string|null, room: string|null, charges: int, netPayments: int, notYetCharged: bool}>
     */
    public function folios(Collection $properties, ?int $total = null, bool $settled = false, ?string $from = null, ?string $to = null, ?string $reservationId = null): LengthAwarePaginator
    {
        $settledDeparting = $settled && $from !== null && $to !== null ? ['from' => $from, 'to' => $to] : null;
        $ids = Property::query()->real()->whereKey($properties->pluck('id'))->pluck('id');
        $page = self::unsettled($ids, $settledDeparting)
            ->when($reservationId !== null, fn ($query) => $query->where('folios.reservation_id', $reservationId))
            ->select(['folios.id', 'folios.property_id', 'folios.currency', 'folios.reservation_id', 'reservations.reservation_number', 'reservations.status', 'reservations.departure'])
            ->selectRaw('COALESCE(balances.balance, 0) as balance')
            ->orderBy('reservations.departure')->orderBy('reservations.reservation_number')->orderBy('folios.currency')->orderBy('folios.id')
            ->paginate(50, pageName: 'folios', total: $settledDeparting === null ? $total : null)->withQueryString();
        // This page's lookups in bulk, so a page costs the same queries however many rows it has.
        $postings = Posting::query()->whereIn('folio_id', $page->pluck('id'))->get()->groupBy('folio_id');
        $reservations = Reservation::query()->whereIn('id', $page->pluck('reservation_id'))->get();
        $guests = Reservation::guestNames($reservations);
        $rooms = Room::query()->whereIn('id', $reservations->pluck('room_id')->filter())->get(['id', 'number'])->keyBy('id');
        $estimates = collect(FolioSummary::balances($reservations));
        $roomOf = $reservations->keyBy('id')->map(fn (Reservation $reservation) => $reservation->room_id === null ? null : $rooms->get($reservation->room_id)?->number);

        return $page->through(function (Folio $folio) use ($postings, $guests, $roomOf, $estimates): array {
            $summary = FolioSummary::of($postings->get($folio->id, []));

            return [
                'id' => $folio->id,
                'propertyId' => $folio->property_id,
                'reservationNumber' => (string) $folio->getAttribute('reservation_number'),
                'status' => (string) $folio->getAttribute('status'),
                'departure' => substr((string) $folio->getAttribute('departure'), 0, 10),
                'departed' => $folio->getAttribute('status') === ReservationStatus::CheckedOut->value,
                'currency' => $folio->currency->value,
                'balance' => (int) $folio->getAttribute('balance'),
                'guest' => $guests->get($folio->reservation_id),
                'room' => $roomOf->get($folio->reservation_id),
                'charges' => $summary['charges'],
                'netPayments' => $summary['net_payments'],
                // Nights still to charge are only flagged, never counted as charges here.
                'notYetCharged' => collect($estimates->get($folio->reservation_id, []))->contains(fn (array $row) => $row['currency'] === $folio->currency->value && $row['unposted']),
            ];
        });
    }

    /**
     * Folios of the property IDs joined to their posted balance and reservation: those whose
     * balance is not zero, plus, with $settledDeparting, every folio whose stay departs in that
     * range, settled or never posted to.
     *
     * @param  Collection<int, string>  $ids
     * @param  array{from: string, to: string}|null  $settledDeparting
     * @return Builder<Folio>
     */
    private static function unsettled(Collection $ids, ?array $settledDeparting = null): Builder
    {
        $balances = Posting::query()->whereIn('postings.property_id', $ids)
            ->selectRaw('property_id, folio_id, SUM(amount) as balance')->groupBy('property_id', 'folio_id');
        $on = function ($join): void {
            $join->on('balances.folio_id', '=', 'folios.id')->on('balances.property_id', '=', 'folios.property_id');
        };
        $query = Folio::query()->whereIn('folios.property_id', $ids)->join('reservations', 'reservations.id', '=', 'folios.reservation_id');

        return $settledDeparting === null
            ? $query->joinSub($balances->havingRaw('SUM(amount) <> 0'), 'balances', $on)
            : $query->leftJoinSub($balances, 'balances', $on)->where(fn (Builder $listed) => $listed->whereRaw('COALESCE(balances.balance, 0) <> 0')
                ->orWhere(fn (Builder $settled) => $settled->whereDate('reservations.departure', '>=', $settledDeparting['from'])->whereDate('reservations.departure', '<=', $settledDeparting['to'])));
    }

    /**
     * @param  Collection<int, Property>  $properties
     * @return array<string, array<string, mixed>>
     */
    public function summariesForProperties(Collection $properties, string $from, string $to): array
    {
        $properties = Property::query()->real()->whereKey($properties->pluck('id'))->get();
        $rows = self::unsettled($properties->pluck('id'))
            ->selectRaw('folios.property_id, folios.currency, COUNT(*) as unsettled')
            ->selectRaw('SUM(CASE WHEN reservations.status = ? THEN 1 ELSE 0 END) as departed_folios', [ReservationStatus::CheckedOut->value])
            ->selectRaw('SUM(CASE WHEN reservations.status = ? THEN balances.balance ELSE 0 END) as departed_balance', [ReservationStatus::CheckedOut->value])
            ->groupBy('folios.property_id', 'folios.currency')->orderBy('folios.currency')->toBase()->get()->groupBy('property_id');
        $payments = $this->payments($properties, $from, $to);

        return $properties->mapWithKeys(function (Property $property) use ($rows, $payments): array {
            $currencies = $rows->get($property->id, collect());

            return [$property->id => [
                'unsettledFolios' => (int) $currencies->sum('unsettled'),
                'openAfterDeparture' => $currencies->filter(fn (object $row): bool => (int) $row->departed_folios > 0)
                    ->map(fn (object $row): array => [
                        'currency' => (string) $row->currency,
                        'folios' => (int) $row->departed_folios,
                        'balance' => (int) $row->departed_balance,
                    ])->values()->all(),
                'payments' => $payments[$property->id],
            ]];
        })->all();
    }

    /**
     * Money received on the business dates from $from to $to, as Billing posts it: a payment
     * is a negative posting, a refund a positive one with its method, and a reversal the exact
     * negative of a payment or refund, counted on its own business date. Received amounts are
     * positive. Charges, transfers and write-offs carry no method and are not money received.
     *
     * @param  Collection<int, Property>  $properties
     * @return array<string, array{rows: list<array{method: string, currency: string, shiftId: string|null, cashier: string|null, shiftOpenedAt: string|null, payments: int, refunds: int, reversals: int, net: int}>, totals: list<array{currency: string, payments: int, refunds: int, reversals: int, net: int}>}>
     */
    public function payments(Collection $properties, string $from, string $to, ?string $reservationId = null): array
    {
        $properties = Property::query()->real()->whereKey($properties->pluck('id'))->get();
        $rows = Posting::query()->whereIn('postings.property_id', $properties->pluck('id'))
            ->when($reservationId !== null, fn ($query) => $query->whereIn('postings.folio_id', Folio::query()->select('id')->where('reservation_id', $reservationId)))
            ->whereNotNull('postings.method')->whereIn('postings.type', ['payment', 'refund', 'reversal'])
            ->where('postings.business_date', '>=', $from)->where('postings.business_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString())
            ->leftJoin('cashier_shifts', 'cashier_shifts.id', '=', 'postings.cashier_shift_id')
            ->leftJoin('users', 'users.id', '=', 'cashier_shifts.user_id')
            ->groupBy('postings.property_id', 'postings.method', 'postings.currency', 'postings.cashier_shift_id', 'users.name', 'cashier_shifts.opened_at')
            ->orderBy('postings.method')->orderBy('postings.currency')->orderBy('postings.cashier_shift_id')
            ->selectRaw('postings.property_id, postings.method, postings.currency, postings.cashier_shift_id, users.name as cashier, cashier_shifts.opened_at as shift_opened_at')
            ->selectRaw("-SUM(CASE WHEN postings.type = 'payment' THEN postings.amount ELSE 0 END) as payments")
            ->selectRaw("SUM(CASE WHEN postings.type = 'refund' THEN postings.amount ELSE 0 END) as refunds")
            ->selectRaw("-SUM(CASE WHEN postings.type = 'reversal' THEN postings.amount ELSE 0 END) as reversals")
            ->toBase()->get()->mapToGroups(fn (object $row): array => [$row->property_id => [
                'method' => $row->method,
                'currency' => $row->currency,
                'shiftId' => $row->cashier_shift_id,
                'cashier' => $row->cashier,
                'shiftOpenedAt' => $row->shift_opened_at === null ? null : CarbonImmutable::parse($row->shift_opened_at)->toIso8601String(),
                'payments' => (int) $row->payments,
                'refunds' => (int) $row->refunds,
                'reversals' => (int) $row->reversals,
                'net' => (int) $row->payments - (int) $row->refunds + (int) $row->reversals,
            ]]);

        return $properties->mapWithKeys(function (Property $property) use ($rows): array {
            $payments = $rows->get($property->id, collect());
            $totals = $payments->groupBy('currency')->sortKeys()->map(fn ($group, string $currency): array => [
                'currency' => $currency,
                ...collect(['payments', 'refunds', 'reversals', 'net'])->mapWithKeys(fn (string $key) => [$key => $group->sum($key)])->all(),
            ])->values()->all();

            return [$property->id => ['rows' => $payments->values()->all(), 'totals' => $totals]];
        })->all();
    }
}
