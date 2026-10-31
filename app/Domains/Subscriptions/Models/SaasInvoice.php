<?php

namespace App\Domains\Subscriptions\Models;

use App\Domains\Subscriptions\Enums\SaasInvoiceStatus;
use App\Domains\Subscriptions\Policies\SaasInvoicePolicy;
use App\Domains\Tenancy\Models\Organization;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Thedream's invoice to an organization for one billing period (SUB-01).
 * Kept apart from guest folios: its own table, its own platform-wide numbering.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $subscription_id
 * @property int $sequence
 * @property string $number
 * @property CarbonImmutable $period_starts_at
 * @property CarbonImmutable $period_ends_at
 * @property CarbonImmutable $due_at
 * @property int $amount minor units of $currency
 * @property Currency $currency
 * @property SaasInvoiceStatus $status
 * @property CarbonImmutable|null $paid_at
 * @property array<string, mixed>|null $billing_snapshot BillingDetails fields and organization_name at issue; null before billing details existed
 * @property Subscription $subscription
 */
#[Fillable(['subscription_id', 'sequence', 'number', 'period_starts_at', 'period_ends_at', 'due_at', 'amount', 'currency', 'status', 'paid_at', 'billing_snapshot'])]
#[UsePolicy(SaasInvoicePolicy::class)]
class SaasInvoice extends Model
{
    use BelongsToTenant, HasUlids;

    public const string PREFIX = 'SAAS-';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'amount' => 'integer', 'currency' => Currency::class, 'status' => SaasInvoiceStatus::class,
            'period_starts_at' => 'immutable_datetime', 'period_ends_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime',
            'billing_snapshot' => 'array',
        ];
    }

    public function balance(): int
    {
        return $this->amount - (int) $this->payments()->sum('amount');
    }

    /**
     * What the platform list and the owner's subscription page show. Load
     * withSum('payments', 'amount') first.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'number' => $this->number, 'status' => $this->status->value, 'statusLabel' => $this->status->label(),
            'overdue' => $this->status === SaasInvoiceStatus::Open && $this->due_at->isPast(),
            'periodStartsAt' => $this->period_starts_at->toIso8601String(), 'periodEndsAt' => $this->period_ends_at->toIso8601String(),
            'dueAt' => $this->due_at->toIso8601String(), 'amount' => $this->amount,
            'balance' => $this->amount - (int) ($this->getAttribute('payments_sum_amount') ?? 0), 'currency' => $this->currency->value,
        ];
    }

    /**
     * The printable invoice page: Thedream's details come only from the
     * snapshot taken at issue, null on invoices issued before it existed.
     *
     * @return array<string, mixed>
     */
    public function view(): array
    {
        $this->loadSum('payments', 'amount')->load('payments', 'subscription.plan');

        return [
            'invoice' => [...$this->summary(), 'plan' => $this->subscription->plan->name, 'issuedAt' => $this->created_at?->toIso8601String()],
            'billing' => $this->billing_snapshot,
            'payments' => $this->payments->sortBy('received_on')->values()->map(fn (SaasPayment $payment) => [
                'id' => $payment->id, 'method' => $payment->method->label(), 'amount' => $payment->amount,
                'reference' => $payment->reference, 'receivedOn' => $payment->received_on->toDateString(),
            ]),
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<SaasPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SaasPayment::class);
    }

    /** @return HasMany<SaasPaymentSlip, $this> */
    public function slips(): HasMany
    {
        return $this->hasMany(SaasPaymentSlip::class);
    }
}
