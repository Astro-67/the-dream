<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Billing\Models\Folio;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Proposes the cancellation or no-show fee the reservation's rate plan policy
 * gives on the property's local date. Nothing is proposed when the policy
 * charges nothing; proposing the same kind again returns the first proposal.
 * Cancelling calls it; Spec 7's no-show confirmation calls it with 'no_show'.
 */
class ProposeFee
{
    /** @param 'cancellation'|'no_show' $kind */
    public function handle(?User $actor, Reservation $reservation, string $kind): ?FeeProposal
    {
        return DB::transaction(function () use ($actor, $reservation, $kind) {
            $existing = FeeProposal::query()->where('reservation_id', $reservation->id)->where('kind', $kind)->first();
            if ($existing !== null) {
                return $existing;
            }
            $plan = RatePlan::query()->findOrFail($reservation->getAttribute('rate_plan_id'));
            $charge = $kind === 'no_show' ? $plan->no_show_charge : $plan->cancellation_charge;
            if ($charge === null) {
                return null;
            }
            if ($kind === 'cancellation' && $plan->cancellation_free_days !== null) {
                $property = Property::query()->findOrFail($reservation->property_id);
                // ponytail: before Spec 7 owns the open business date, use the property's local date.
                $today = now($property->timezone)->startOfDay();
                if ((int) $today->diffInDays($reservation->arrival, false) >= $plan->cancellation_free_days) {
                    return null;
                }
            }
            $lines = $reservation->priceLines()->get(['amount', 'currency']);
            $amount = $charge->amount($lines->pluck('amount')->all(), $kind === 'no_show' ? $plan->no_show_percent : $plan->cancellation_percent);
            if ($amount <= 0) {
                return null;
            }
            $folio = Folio::query()->where('reservation_id', $reservation->id)->orderByRaw('currency = ? desc', [$lines->first()->currency ?? $plan->currency->value])->orderBy('created_at')->firstOrFail();
            $proposal = FeeProposal::query()->create([
                'reservation_id' => $reservation->id, 'folio_id' => $folio->id, 'kind' => $kind,
                'amount' => $amount, 'currency' => $folio->currency, 'status' => 'proposed',
            ]);
            Audit::record($actor, 'created', $proposal, 'Fee proposed', ['kind' => $kind, 'amount' => $amount, 'currency' => $folio->currency->value, 'reservation_id' => $reservation->id]);

            return $proposal;
        });
    }
}
