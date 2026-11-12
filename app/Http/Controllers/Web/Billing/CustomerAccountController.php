<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\CloseCustomerAccount;
use App\Domains\Billing\Actions\OpenCustomerAccount;
use App\Domains\Billing\CustomerAccounts;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CloseCustomerAccountRequest;
use App\Http\Requests\Billing\CustomerAccountsRequest;
use App\Http\Requests\Billing\OpenCustomerAccountRequest;
use App\Support\Money\Currency;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerAccountController extends Controller
{
    public function index(CustomerAccountsRequest $request): Response
    {
        return Inertia::render('customer-accounts/index', [
            'accounts' => CustomerAccounts::listing($request->validated()),
            'filters' => $request->validated(),
            'canCreate' => Gate::allows('create', CustomerAccount::class),
            'currencies' => array_map(fn (Currency $currency) => $currency->value, JurisdictionRules::allowedCurrencies(Tenancy::property())),
        ]);
    }

    public function show(CustomerAccount $customerAccount): Response
    {
        Gate::authorize('view', $customerAccount);

        return Inertia::render('customer-accounts/show', CustomerAccounts::page($customerAccount));
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
