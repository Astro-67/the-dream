<?php

namespace App\Http\Controllers\Web\Subscriptions;

use App\Domains\Subscriptions\Actions\IssueSaasInvoice;
use App\Domains\Subscriptions\Actions\RecordSaasPayment;
use App\Domains\Subscriptions\Enums\SaasPaymentMethod;
use App\Domains\Subscriptions\Models\SaasInvoice;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Tenancy\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\SaasInvoiceRequest;
use App\Http\Requests\Subscriptions\SaasPaymentRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform administrators' SaaS invoices and the payments organizations made
 * against them (SUB-01). Platform pages: invoices are read across organizations
 * in the platform context; writes run in the invoice's own organization.
 */
class SaasInvoiceController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage', SaasInvoice::class);

        return Tenancy::within(TenantContext::platform('Platform administrator listed SaaS invoices'), fn () => Inertia::render('platform/invoices/index', [
            'invoices' => SaasInvoice::query()->with('organization:id,name')->withSum('payments', 'amount')
                ->orderByDesc('sequence')->paginate(50)->withQueryString()
                ->through(fn (SaasInvoice $invoice) => ['id' => $invoice->id, 'organization' => $invoice->organization->name, ...$invoice->summary()]),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name'])->map->only('id', 'name')->values(),
            'methods' => array_map(fn (SaasPaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], SaasPaymentMethod::cases()),
        ]));
    }

    public function store(SaasInvoiceRequest $request, IssueSaasInvoice $issue): RedirectResponse
    {
        $organizationId = $request->validated('organization_id');

        /** @var User $actor */
        $actor = $request->user();
        $invoice = Tenancy::within(TenantContext::forOrganization($organizationId), fn () => $issue->handle(Subscription::current(), $actor));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice :number issued.', ['number' => $invoice->number])]);

        return to_route('platform.invoices.index');
    }

    public function recordPayment(SaasPaymentRequest $request, string $invoice, RecordSaasPayment $record): RedirectResponse
    {
        $found = Tenancy::within(TenantContext::platform('Platform administrator recorded a SaaS payment'), fn () => SaasInvoice::query()->findOrFail($invoice));

        /** @var User $actor */
        $actor = $request->user();
        /** @var array{amount: int, method: string, reference: string, received_on: string} $details */
        $details = $request->validated();
        Tenancy::within(TenantContext::forOrganization($found->organization_id), fn () => $record->handle($actor, $found, $details));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded against :number.', ['number' => $found->number])]);

        return to_route('platform.invoices.index');
    }
}
