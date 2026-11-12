<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\CloseCustomerAccount;
use App\Domains\Billing\Actions\OpenCustomerAccount;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\CustomerAccounts;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\FiscalReceipting\Actions\RecoverVfmsFiling;
use App\Domains\FiscalReceipting\PrintedDocuments;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CloseCustomerAccountRequest;
use App\Http\Requests\Billing\CustomerAccountsRequest;
use App\Http\Requests\Billing\OpenCustomerAccountRequest;
use App\Http\Requests\FiscalReceipting\RecoverVfmsFilingRequest;
use App\Support\Money\Currency;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerAccountController extends Controller
{
    public function recover(RecoverVfmsFilingRequest $request, CustomerAccount $customerAccount, OutboxMessage $message, RecoverVfmsFiling $recover): RedirectResponse
    {
        $recover->handle($request->user(), $customerAccount, $message, $request->string('reason')->value(), refile: $request->routeIs('customer-accounts.vfms.refile'));

        return to_route('customer-accounts.show', $customerAccount)->with('toast', ['type' => 'success', 'message' => __('ZRA filing queued for delivery.')]);
    }

    public function index(CustomerAccountsRequest $request): Response
    {
        return Inertia::render('customer-accounts/index', [
            'accounts' => CustomerAccounts::listing($request->validated()),
            'filters' => $request->validated(),
            'canCreate' => Gate::allows('create', CustomerAccount::class),
            'extraCategories' => PostExtraCharge::CATEGORIES,
            'currencies' => array_map(fn (Currency $currency) => $currency->value, JurisdictionRules::allowedCurrencies(Tenancy::property())),
        ]);
    }

    public function show(CustomerAccount $customerAccount): Response
    {
        Gate::authorize('view', $customerAccount);

        return Inertia::render('customer-accounts/show', CustomerAccounts::page($customerAccount));
    }

    public function print(Request $request, CustomerAccount $customerAccount): Response
    {
        Gate::authorize('view', $customerAccount);
        $documents = PrintedDocuments::for($customerAccount, $request->string('document')->value() ?: null);
        abort_if($request->filled('document') && $documents === [], 404);

        return Inertia::render('fiscal-receipting/zra-document', [
            'documents' => $documents, 'sandbox' => collect($documents)->contains('mode', 'sandbox'),
            'property' => Tenancy::property()->only(['name']), 'branding' => Tenancy::property()->branding(),
            'reservationNumber' => $customerAccount->number, 'backUrl' => route('customer-accounts.show', $customerAccount),
        ]);
    }

    public function store(OpenCustomerAccountRequest $request, OpenCustomerAccount $open): RedirectResponse
    {
        $account = $open->handle($request->user(), $request->validated());

        return to_route('customer-accounts.show', $account);
    }

    public function close(CloseCustomerAccountRequest $request, CustomerAccount $customerAccount, CloseCustomerAccount $close): RedirectResponse
    {
        $close->handle($request->user(), $customerAccount);

        return to_route('customer-accounts.show', $customerAccount)->with('toast', ['type' => 'success', 'message' => __('Account closed. Any ZRA receipt is queued for filing.')]);
    }
}
