<?php

namespace App\Domains\Platform\Actions;

use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\SaasPayment;
use App\Domains\Subscriptions\Models\SaasPaymentSlip;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Data\GoLiveGate;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

final class ShowOrganization
{
    /** @return array<string, mixed> */
    public function handle(string $id): array
    {
        return Tenancy::within(TenantContext::platform('Platform administrator viewed organization '.$id), function () use ($id): array {
            $organization = Organization::query()->findOrFail($id);
            $subscription = Subscription::query()->where('organization_id', $id)->with('plan')->first();

            return [
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'owners' => app(ListOrganizations::class)->ownersOf([$id])[$id] ?? [],
                ],
                'subscription' => $subscription === null ? null : [
                    'plan' => $subscription->plan->name,
                    'rooms' => $subscription->rooms,
                    'status' => $subscription->status->value,
                    'statusLabel' => $subscription->status->label(),
                    'currentPeriodEndsAt' => $subscription->current_period_ends_at?->toIso8601String(),
                ],
                'invoices' => SaasInvoice::query()->where('organization_id', $id)
                    ->withSum('payments', 'amount')->orderByDesc('sequence')->paginate(50)->withQueryString()
                    ->through(fn (SaasInvoice $invoice) => [
                        'id' => $invoice->id, ...$invoice->summary(),
                        'slips' => $invoice->slips()->orderBy('id')->paginate(10, ['*'], 'slips_'.$invoice->id)->withQueryString()
                            ->through(fn (SaasPaymentSlip $slip) => $slip->summary(forOwner: false)),
                        'payments' => $invoice->payments()->orderBy('received_on')->orderBy('id')->paginate(10, ['*'], 'payments_'.$invoice->id)->withQueryString()->through(fn (SaasPayment $payment) => [
                            'id' => $payment->id, 'amount' => $payment->amount, 'method' => $payment->method->label(),
                            'reference' => $payment->reference, 'receivedOn' => $payment->received_on->toDateString(),
                        ]),
                    ]),
                'properties' => $organization->properties()->orderBy('name')->orderBy('id')->get()
                    ->map(fn (Property $property) => Tenancy::within(TenantContext::forProperty($property), function () use ($property): array {
                        $credential = VfmsCredential::for($property);
                        $requested = ! $property->isLive() && $property->go_live_requested_at !== null;

                        return [
                            'id' => $property->id, 'name' => $property->name, 'email' => $property->email, 'phone' => $property->phone,
                            'status' => $requested ? 'go_live_requested' : $property->status->value,
                            'statusLabel' => $property->isLive() ? __('Live') : ($requested ? __('Go-live requested') : __('Setup')),
                            'archived' => $property->archived_at !== null,
                            'gates' => array_map(fn (GoLiveGate $gate) => [
                                'key' => $gate->key, 'label' => $gate->label, 'passed' => $gate->passed,
                            ], JurisdictionRules::goLiveGates($property)),
                            'vfmsMode' => $credential->mode->value,
                            'credentialsSaved' => $credential->isComplete(),
                            'zraCheck' => $credential->zra_check,
                            'zraCheckedAt' => $credential->zra_checked_at?->toIso8601String(),
                        ];
                    }))->all(),
            ];
        });
    }
}
