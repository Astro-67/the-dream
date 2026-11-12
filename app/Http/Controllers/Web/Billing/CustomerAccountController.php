<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\CloseCustomerAccount;
use App\Domains\Billing\Actions\OpenCustomerAccount;
use App\Domains\Billing\Models\CustomerAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CloseCustomerAccountRequest;
use App\Http\Requests\Billing\OpenCustomerAccountRequest;
use Illuminate\Http\RedirectResponse;

class CustomerAccountController extends Controller
{
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
