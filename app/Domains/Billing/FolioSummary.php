<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationPriceLine;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
            ->map(fn (string $currency) => ['currency' => $currency, ...($byCurrency->get($currency) ?? self::of([])), 'estimate' => $estimates->get($currency)])->all();
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
