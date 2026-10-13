<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\TaxCalculator;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Posts a proposed cancellation or no-show fee to its folio, or waives it, with
 * a reason either way. A fee posted where JurisdictionRules says so is marked a
 * fiscal exception candidate for M3.
 */
class ResolveFee
{
    /** @param 'post'|'waive' $decision */
    public function handle(?User $actor, FeeProposal $proposal, string $decision, string $reason): FeeProposal
    {
        return Audit::validationRefusal($actor, $proposal, 'Fee decision refused', fn () => DB::transaction(function () use ($actor, $proposal, $decision, $reason) {
            $folio = Folio::query()->whereKey($proposal->folio_id)->lockForUpdate()->firstOrFail();
            $proposal = FeeProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($proposal->status !== 'proposed') {
                throw ValidationException::withMessages(['fee' => __('This fee has already been posted or waived.')]);
            }
            if (trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => __('Give a reason.')]);
            }
            $resolution = ['status' => $decision === 'post' ? 'posted' : 'waived', 'reason' => $reason, 'resolved_by' => $actor?->id, 'resolved_at' => now()];
            if ($decision === 'post') {
                $property = Property::query()->findOrFail($folio->property_id);
                // ponytail: before Spec 7 owns the open business date, use the property's local date.
                $date = now($property->timezone)->toDateString();
                $reservation = Reservation::query()->findOrFail($folio->reservation_id);
                try {
                    $breakdown = TaxCalculator::forProperty($property, $proposal->amount, $folio->currency, $date, $reservation->getAttribute('adults') + $reservation->getAttribute('children'), ChargeKind::Extra);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['fee' => $exception->getMessage()]);
                }
                $posting = Posting::query()->create([
                    'folio_id' => $folio->id, 'type' => 'charge', 'category' => $proposal->kind.'_fee', 'description' => $reason,
                    'quantity' => 1, 'unit_price' => $proposal->amount, 'net' => $breakdown->net, 'amount' => $breakdown->gross(), 'currency' => $folio->currency,
                    'tax_lines' => $breakdown->lines, 'tax_rule_ids' => $breakdown->ruleIds, 'idempotency_key' => 'fee:'.$proposal->id,
                    'actor_id' => $actor?->id, 'business_date' => $date, 'posted_at' => now(),
                ]);
                $resolution += ['posting_id' => $posting->id, 'fiscal_exception_candidate' => JurisdictionRules::marksFeesAsFiscalExceptions($property)];
            }
            $proposal->update($resolution);
            Audit::record($actor, $resolution['status'], $proposal, $decision === 'post' ? 'Fee posted' : 'Fee waived', [
                'kind' => $proposal->kind, 'amount' => $proposal->amount, 'currency' => $proposal->currency->value, 'reason' => $reason,
                'posting_id' => $proposal->posting_id, 'fiscal_exception_candidate' => $proposal->fiscal_exception_candidate,
            ]);

            return $proposal;
        }));
    }
}
