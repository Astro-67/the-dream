<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationPriceLine;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * The one owner of a folio's posted totals (glossary: Folio): sums stored postings
 * and their frozen tax lines, never today's rates. A reversal or transfer keeps the
 * category of what it cancels, so it nets against it instead of counting again.
 *
 * @phpstan-type Summary array{room: int, extras: int, vat: int, levy: int, other_taxes: int, unclassified_taxes: int, charges: int, paid: int, refunded: int, written_off: int, net_payments: int, balance: int, levy_unavailable: bool}
 */
final class FolioSummary
{
    private const array MONEY = ['payment' => 'paid', 'deposit' => 'paid', 'refund' => 'refunded', 'write_off' => 'written_off'];

    /**
     * Minor units of the postings' one currency. charges = room + extras + every tax;
     * balance = charges - net_payments - written_off, which is the sum of the postings.
     * levy_unavailable: a charge left the levy out because it is not available in this currency.
     *
     * @param  iterable<Posting>  $postings
     * @return Summary
     */
    public static function of(iterable $postings): array
    {
        $levyUnavailable = false;
        $sum = ['room' => 0, 'extras' => 0, 'vat' => 0, 'levy' => 0, 'other_taxes' => 0, 'unclassified_taxes' => 0, 'charges' => 0, 'paid' => 0, 'refunded' => 0, 'written_off' => 0, 'balance' => 0];
        foreach ($postings as $posting) {
            $sum['balance'] += $posting->amount;
            // Payments are stored negative, refunds positive; both read as money moved.
            $money = self::MONEY[$posting->category] ?? self::MONEY[$posting->type] ?? null;
            if ($money !== null) {
                $sum[$money] += $money === 'refunded' ? $posting->amount : -$posting->amount;

                continue;
            }
            $sum['charges'] += $posting->amount;
            $sum[$posting->category === 'room' ? 'room' : 'extras'] += $posting->net;
            foreach ($posting->tax_lines as $line) {
                $levyUnavailable = $levyUnavailable || ($line['unavailable'] ?? false);
                // Lines stored before categories have no key: kept apart rather than guessed from a translated name.
                $sum[match (true) {
                    ! array_key_exists('category', $line) => 'unclassified_taxes',
                    $line['category'] === 'vat' => 'vat',
                    $line['category'] === 'hotel_levy' => 'levy',
                    default => 'other_taxes',
                }] += $line['amount'];
            }
        }

        $balance = array_pop($sum);

        return [...$sum, 'net_payments' => $sum['paid'] - $sum['refunded'], 'balance' => $balance, 'levy_unavailable' => $levyUnavailable];
    }

    /**
     * Each folio of the reservation with its summary, oldest first.
     *
     * @return Collection<int, array{folio: Folio, postings: EloquentCollection<int, Posting>, summary: Summary}>
     */
    public static function folios(Reservation $reservation): Collection
    {
        $postings = Posting::query()->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $reservation->id))
            ->orderBy('posted_at')->orderBy('id')->get();

        return Folio::query()->where('reservation_id', $reservation->id)->orderBy('created_at')->orderBy('id')->get()
            ->map(function (Folio $folio) use ($postings) {
                $own = $postings->where('folio_id', $folio->id)->values();

                return ['folio' => $folio, 'postings' => $own, 'summary' => self::of($own)];
            });
    }

    /**
     * Per currency: the posted summary across the reservation's folios, plus the scheduled
     * nights not yet charged as a separate estimate at their booked price, before added taxes.
     * due = balance + estimate; status is paid once nothing is due (a credit included).
     *
     * @param  Collection<int, array{folio: Folio, postings: EloquentCollection<int, Posting>, summary: Summary}>|null  $folios  already loaded by folios(), so totals match their itemisation
     * @return list<array<string, mixed>>
     */
    public static function totals(Reservation $reservation, ?Collection $folios = null): array
    {
        $byCurrency = ($folios ?? self::folios($reservation))->groupBy(fn (array $folio) => $folio['folio']->currency->value)
            ->map(fn (Collection $folios) => self::of($folios->flatMap(fn (array $folio) => $folio['postings'])));
        $estimates = self::unposted(collect([$reservation]))->get($reservation->id, collect());

        return $byCurrency->keys()->merge($estimates->keys())->unique()->sort()->values()
            ->map(function (string $currency) use ($byCurrency, $estimates) {
                $summary = $byCurrency->get($currency) ?? self::of([]);
                $estimate = $estimates->get($currency);
                $due = $summary['balance'] + ($estimate['amount'] ?? 0);

                return ['currency' => $currency, ...$summary, 'estimate' => $estimate, 'due' => $due,
                    'status' => match (true) {
                        $due <= 0 => 'paid',
                        $summary['net_payments'] > 0 => 'partially_paid',
                        default => 'unpaid',
                    }];
            })->all();
    }

    /**
     * Whose tax the folio shows (ADR 0015): null where Karafuu's tax rules are the tax,
     * 'estimated' at a VFMS property until VFMS's check-out figures are posted, then 'vfms'.
     */
    public static function taxSource(Reservation $reservation, Property $property): ?string
    {
        if (! JurisdictionRules::usesVfms($property)) {
            return null;
        }

        return Posting::query()->where('reservation_id', $reservation->id)->where('category', 'vfms_adjustment')->exists() ? 'vfms' : 'estimated';
    }

    /**
     * First and last business date with money received on the reservation, today when there is none,
     * so the payments report link covers every payment.
     *
     * @return array{from: string, to: string}
     */
    public static function paymentDates(Reservation $reservation): array
    {
        $range = Posting::query()->where('property_id', $reservation->property_id)
            ->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $reservation->id))->moneyMovements()
            ->selectRaw('MIN(business_date) as first_date, MAX(business_date) as last_date')->toBase()->first();

        return [
            'from' => CarbonImmutable::parse($range->first_date ?? BusinessDate::current())->toDateString(),
            'to' => CarbonImmutable::parse($range->last_date ?? BusinessDate::current())->toDateString(),
        ];
    }

    /**
     * Each folio's Payments received list, newest first, paged per folio, from one query for the whole page.
     * Row one also carries the total on empty/out-of-range pages; at most 51 rows are loaded per folio.
     *
     * @param  EloquentCollection<int, Folio>  $folios
     * @return Collection<string, LengthAwarePaginator<int, Posting>> keyed by folio id
     */
    public static function payments(EloquentCollection $folios): Collection
    {
        $ranked = Posting::query()->whereIn('folio_id', $folios->modelKeys())->moneyMovements()->select('postings.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY folio_id ORDER BY posted_at DESC, id DESC) as payment_row, COUNT(*) OVER (PARTITION BY folio_id) as payment_count');
        $payments = Posting::query()->fromSub($ranked, 'postings')->where(function ($query) use ($folios) {
            $query->where('payment_row', 1);
            foreach ($folios as $folio) {
                $offset = (Paginator::resolveCurrentPage('payments_page_'.$folio->id) - 1) * 50;
                $query->orWhere(fn ($query) => $query->where('folio_id', $folio->id)->whereBetween('payment_row', [$offset + 1, $offset + 50]));
            }
        })->with(['actor:id,name', 'receipt:id,posting_id,number'])->orderByDesc('posted_at')->orderByDesc('id')->get();

        return $folios->toBase()->mapWithKeys(function (Folio $folio) use ($payments) {
            $own = $payments->where('folio_id', $folio->id);
            $pageName = 'payments_page_'.$folio->id;
            $page = Paginator::resolveCurrentPage($pageName);
            $offset = ($page - 1) * 50;
            $rows = $own->whereBetween('payment_row', [$offset + 1, $offset + 50])->values();
            $paginator = new LengthAwarePaginator($rows, (int) ($own->first()?->getAttribute('payment_count') ?? 0), 50, $page, ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]);

            return [$folio->id => $paginator->withQueryString()];
        });
    }

    /**
     * For a page of reservations, in two queries: each one's posted balance per currency,
     * and whether nights are still to be charged in it, so a zero balance is not read as paid.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @return array<string, list<array{currency: string, balance: int, unposted: bool}>> keyed by reservation id
     */
    public static function balances(Collection $reservations): array
    {
        // withSum goes through the postings relation, so the tenant scope applies to the postings too.
        $posted = Folio::query()->whereIn('reservation_id', $reservations->pluck('id'))->withSum('postings as balance', 'amount')->get()
            ->groupBy('reservation_id')->map(fn (Collection $folios) => $folios->groupBy(fn (Folio $folio) => $folio->currency->value)
            ->map(fn (Collection $same) => (int) $same->sum('balance')));
        $unposted = self::unposted($reservations);

        return $reservations->mapWithKeys(function (Reservation $reservation) use ($posted, $unposted) {
            $balances = $posted->get($reservation->id, collect());
            $estimates = $unposted->get($reservation->id, collect());

            return [$reservation->id => $balances->keys()->merge($estimates->keys())->unique()->sort()->values()
                ->map(fn (string $currency) => ['currency' => $currency, 'balance' => $balances->get($currency, 0), 'unposted' => $estimates->has($currency)])->all()];
        })->all();
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return array<string, list<array{id: string, currency: string, balance: int}>>
     */
    public static function checkoutBalances(Collection $reservations): array
    {
        return Folio::query()->whereIn('reservation_id', $reservations->pluck('id'))
            ->withSum('postings as balance_amount', 'amount')->orderBy('created_at')->orderBy('id')->get()
            ->groupBy('reservation_id')->map(fn (Collection $folios) => $folios->map(fn (Folio $folio) => [
                'id' => $folio->id, 'currency' => $folio->currency->value, 'balance' => (int) $folio->getAttribute('balance_amount'),
            ])->values()->all())->all();
    }

    /**
     * Booked nights before departure with no room charge yet, per reservation and currency.
     * Only stays still to come or in house have nights left to charge.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @return Collection<int|string, Collection<int|string, array{nights: int<0, max>, amount: int}>> by reservation id, then currency
     */
    private static function unposted(Collection $reservations): Collection
    {
        $open = $reservations->filter(fn (Reservation $reservation) => in_array($reservation->status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true))->keyBy('id');
        $posted = Posting::query()->whereIn('reservation_id', $open->keys())->where('category', 'room')->where('type', 'charge')->whereNotNull('price_line_id')->select('price_line_id');

        return ReservationPriceLine::query()->whereIn('reservation_id', $open->keys())->whereNotIn('id', $posted)->get(['reservation_id', 'date', 'amount', 'currency'])
            ->filter(fn (ReservationPriceLine $line) => $line->getAttribute('date')->toDateString() < $open->get($line->getAttribute('reservation_id'))->departure->toDateString())
            ->groupBy('reservation_id')->map(fn (Collection $lines) => $lines->groupBy('currency')
            ->map(fn (Collection $nights) => ['nights' => $nights->count(), 'amount' => (int) $nights->sum('amount')]));
    }
}
