<?php

namespace App\Domains\Subscriptions\Models;

use App\Domains\Subscriptions\Enums\SaasPaymentMethod;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment an organization made to Thedream against a SaaS invoice, recorded
 * by a platform administrator. Never a folio posting.
 *
 * @property string $id
 * @property string $saas_invoice_id
 * @property int $amount minor units of the invoice's currency
 * @property SaasPaymentMethod $method
 * @property string $reference the bank or mobile money transaction reference
 * @property CarbonImmutable $received_on
 * @property string|null $recorded_by
 */
#[Fillable(['saas_invoice_id', 'amount', 'method', 'reference', 'received_on', 'recorded_by'])]
class SaasPayment extends Model
{
    use BelongsToTenant, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'method' => SaasPaymentMethod::class, 'received_on' => 'immutable_date'];
    }
}
