<?php

namespace App\Domains\Reports;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Finance reports for one property: a snapshot of folio balances now, and payments
 * over a business-date range. Amounts stay in minor units of their own currency.
 */
final class PropertyFinance
{
    /** @return array{folios: LengthAwarePaginator<int, array<string, mixed>>, openAfterDeparture: list<array<string, mixed>>, payments: array{rows: list<array<string, mixed>>, totals: list<array<string, mixed>>}} */
    public function forProperty(Property $property, string $from, string $to): array
    {
        $property = Property::query()->real()->findOrFail($property->id);
        $balance = Posting::query()->selectRaw('coalesce(sum(amount), 0)')->whereColumn('postings.folio_id', 'folios.id');
        $unsettled = Folio::query()->where('folios.property_id', $property->id)
            ->join('reservations', 'reservations.id', '=', 'folios.reservation_id')
            ->whereRaw('('.$balance->toSql().') <> 0', $balance->getBindings());
        $folios = (clone $unsettled)
            ->select(['folios.id', 'folios.currency', 'reservations.reservation_number', 'reservations.status', 'reservations.departure'])
            ->selectSub($balance, 'balance')
            ->orderBy('reservations.departure')->orderBy('reservations.reservation_number')->orderBy('folios.currency')->orderBy('folios.id')
            ->paginate(50, pageName: 'folios')->withQueryString()
            ->through(fn (Folio $folio): array => [
                'id' => $folio->id,
                'reservationNumber' => $folio->getAttribute('reservation_number'),
                'status' => $folio->getAttribute('status'),
                'departure' => substr((string) $folio->getAttribute('departure'), 0, 10),
                'departed' => $folio->getAttribute('status') === ReservationStatus::CheckedOut->value,
                'currency' => $folio->currency->value,
                'balance' => (int) $folio->getAttribute('balance'),
            ]);
        $openAfterDeparture = DB::query()->fromSub((clone $unsettled)->where('reservations.status', ReservationStatus::CheckedOut->value)
            ->select('folios.currency')->selectSub($balance, 'balance'), 'departed')
            ->selectRaw('currency, COUNT(*) as folios, SUM(balance) as balance')->groupBy('currency')->orderBy('currency')->get()
            ->map(fn (object $row): array => ['currency' => $row->currency, 'folios' => (int) $row->folios, 'balance' => (int) $row->balance])->all();

        return ['folios' => $folios, 'openAfterDeparture' => $openAfterDeparture, 'payments' => $this->payments($property, $from, $to)];
    }

    /**
     * Money received on the business dates from $from to $to, as Billing posts it: a payment
     * is a negative posting, a refund a positive one with its method, and a reversal the exact
     * negative of a payment or refund, counted on its own business date. Received amounts are
     * positive. Charges, transfers and write-offs carry no method and are not money received.
     *
     * @return array{rows: list<array<string, mixed>>, totals: list<array<string, mixed>>}
     */
    private function payments(Property $property, string $from, string $to): array
    {
        $rows = Posting::query()->where('postings.property_id', $property->id)
            ->whereNotNull('postings.method')->whereIn('postings.type', ['payment', 'refund', 'reversal'])
            ->whereDate('postings.business_date', '>=', $from)->whereDate('postings.business_date', '<=', $to)
            ->leftJoin('cashier_shifts', 'cashier_shifts.id', '=', 'postings.cashier_shift_id')
            ->leftJoin('users', 'users.id', '=', 'cashier_shifts.user_id')
            ->groupBy('postings.method', 'postings.currency', 'postings.cashier_shift_id', 'users.name')
            ->orderBy('postings.method')->orderBy('postings.currency')->orderBy('postings.cashier_shift_id')
            ->selectRaw('postings.method, postings.currency, postings.cashier_shift_id, users.name as cashier')
            ->selectRaw("-SUM(CASE WHEN postings.type = 'payment' THEN postings.amount ELSE 0 END) as payments")
            ->selectRaw("SUM(CASE WHEN postings.type = 'refund' THEN postings.amount ELSE 0 END) as refunds")
            ->selectRaw("-SUM(CASE WHEN postings.type = 'reversal' THEN postings.amount ELSE 0 END) as reversals")
            ->toBase()->get()
            ->map(fn (object $row): array => [
                'method' => $row->method,
                'currency' => $row->currency,
                'shiftId' => $row->cashier_shift_id,
                'cashier' => $row->cashier,
                'payments' => (int) $row->payments,
                'refunds' => (int) $row->refunds,
                'reversals' => (int) $row->reversals,
                'net' => (int) $row->payments - (int) $row->refunds + (int) $row->reversals,
            ]);
        $totals = $rows->groupBy('currency')->sortKeys()->map(fn ($group, string $currency): array => [
            'currency' => $currency,
            ...collect(['payments', 'refunds', 'reversals', 'net'])->mapWithKeys(fn (string $key) => [$key => $group->sum($key)])->all(),
        ])->values()->all();

        return ['rows' => $rows->all(), 'totals' => $totals];
    }
}
