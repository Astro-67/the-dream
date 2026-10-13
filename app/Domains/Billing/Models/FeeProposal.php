<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Policies\FeeProposalPolicy;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A cancellation or no-show fee the rate plan's cancellation policy proposes.
 * Staff post it to the folio or waive it, with a reason either way.
 *
 * @property string $id
 * @property string $property_id
 * @property string $reservation_id
 * @property string $folio_id
 * @property string $kind 'cancellation' or 'no_show'
 * @property int $amount minor units of $currency
 * @property Currency $currency
 * @property string $status 'proposed', 'posted' or 'waived'
 * @property string|null $reason
 * @property string|null $posting_id
 * @property bool $fiscal_exception_candidate a posted fee with no path into VFMS, listed for M3
 * @property string|null $resolved_by
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable(['reservation_id', 'folio_id', 'kind', 'amount', 'currency', 'status', 'reason', 'posting_id', 'fiscal_exception_candidate', 'resolved_by', 'resolved_at'])]
#[UsePolicy(FeeProposalPolicy::class)]
class FeeProposal extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'currency' => Currency::class, 'fiscal_exception_candidate' => 'boolean', 'resolved_at' => 'immutable_datetime'];
    }

    public function money(): Money
    {
        return new Money($this->amount, $this->currency);
    }
}
