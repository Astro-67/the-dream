<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\FolioSummary;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Audit\Audit;
use App\Support\Money\Currency;
use Illuminate\Support\Facades\DB;

/**
 * Brings a checked-out stay's folio to the figures VFMS returned (ADR 0015): one
 * VFMS adjustment line whose tax lines move VAT and the infrastructure levy from
 * Karafuu's estimate to VFMS's, and whose net moves the total to the report total.
 * Posted once per stay and currency, even at zero, so the folio records the switch.
 */
class PostVfmsAdjustment
{
    /** @param array{report_number: string, total: int, tax: int, levy: int} $vfms minor units of $currency */
    public function handle(Reservation $reservation, Currency $currency, array $vfms): Posting
    {
        return DB::transaction(function () use ($reservation, $currency, $vfms) {
            $folios = Folio::query()->where('reservation_id', $reservation->id)->where('currency', $currency->value)
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            // shortcut: the whole adjustment lands on the stay's first folio in the currency; split it per folio if guest and company folios need VFMS figures each.
            $folio = $folios->firstOrFail();
            $existing = Posting::query()->where('folio_id', $folio->id)->where('idempotency_key', "vfms:{$reservation->id}")->first();
            if ($existing !== null) {
                return $existing;
            }
            $posted = FolioSummary::of(Posting::query()->whereIn('folio_id', $folios->modelKeys())->get());
            $lines = [
                ['name' => 'VAT', 'category' => 'vat', 'kind' => 'vfms', 'inclusive' => false, 'rate' => null, 'per_person_night' => null, 'amount' => $vfms['tax'] - $posted['vat']],
                ['name' => 'Infrastructure levy', 'category' => 'hotel_levy', 'kind' => 'vfms', 'inclusive' => false, 'rate' => null, 'per_person_night' => null, 'amount' => $vfms['levy'] - $posted['levy']],
            ];
            $amount = $vfms['total'] - $posted['charges'];
            $posting = Posting::query()->create([
                'folio_id' => $folio->id, 'reservation_id' => $reservation->id, 'type' => 'charge', 'category' => 'vfms_adjustment',
                'description' => __('VFMS adjustment'), 'reference' => $vfms['report_number'],
                'quantity' => 1, 'unit_price' => 0, 'net' => $amount - $lines[0]['amount'] - $lines[1]['amount'], 'amount' => $amount, 'currency' => $currency,
                'tax_lines' => $lines, 'tax_rule_ids' => [], 'idempotency_key' => "vfms:{$reservation->id}",
                'actor_id' => null, 'business_date' => BusinessDate::of(Property::query()->findOrFail($reservation->property_id)), 'posted_at' => now(),
            ]);
            Audit::record(null, 'created', $posting, 'VFMS adjustment posted', ['folio_id' => $folio->id, 'amount' => $amount, 'report_number' => $vfms['report_number']]);

            return $posting;
        });
    }
}
