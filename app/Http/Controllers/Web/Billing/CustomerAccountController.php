<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\OpenCustomerAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\OpenCustomerAccountRequest;
use Illuminate\Http\RedirectResponse;

class CustomerAccountController extends Controller
{
    public function store(OpenCustomerAccountRequest $request, OpenCustomerAccount $open): RedirectResponse
    {
        $account = $open->handle($request->user(), $request->validated());

        return to_route('customer-accounts.show', $account);
    }
}
