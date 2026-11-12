<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Actions\IssueGeneralReceipt;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseCustomerAccount
{
    public function handle(User $actor, CustomerAccount $account): void
    {
        Audit::validationRefusal($actor, $account, 'Customer account close refused', fn () => DB::transaction(function () use ($actor, $account) {
            $account = CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($account->status === 'closed') {
                return;
            }
            foreach ($account->folios()->lockForUpdate()->get() as $folio) {
                if ($folio->balance()->amount !== 0) {
                    throw ValidationException::withMessages(['balance' => __('Settle every currency balance before closing this customer account.')]);
                }
            }
            app(IssueGeneralReceipt::class)->issue($actor, $account,
                (string) ($account->getAttribute('paying_institution_name') ?: $account->customer_name), self::held($account));
            $account->update(['status' => 'closed']);
            Audit::record($actor, 'closed', $account, 'Customer account closed');
        }));
    }

    /** @return Builder<Posting> */
    public static function held(CustomerAccount $account): Builder
    {
        return Posting::query()->where('postings.property_id', $account->property_id)
            ->whereIn('folio_id', Folio::query()->select('id')->where('property_id', $account->property_id)->where('customer_account_id', $account->id))
            ->heldExtras();
    }
}
