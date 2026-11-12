<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Actions\CloseCustomerAccount;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\VfmsFilings;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class CustomerAccounts
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function listing(array $filters): LengthAwarePaginator
    {
        return CustomerAccount::query()->with(['folios' => fn ($query) => $query->withSum('postings', 'amount')])
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(50)->withQueryString()
            ->through(fn (CustomerAccount $account) => [
                ...$account->only(['id', 'number', 'kind', 'customer_name', 'starts_on', 'ends_on', 'status']),
                'balances' => $account->folios->map(fn (Folio $folio) => ['currency' => $folio->currency->value, 'amount' => (int) $folio->getAttribute('postings_sum_amount')]),
                'receiptStatus' => self::receiptStatus(VfmsFilings::of($account, false)),
            ]);
    }

    /** @param list<array<string, mixed>> $filings */
    private static function receiptStatus(array $filings): string
    {
        $statuses = array_column($filings, 'status');
        if (array_intersect($statuses, ['refused', 'unknown']) !== []) {
            return __('Needs attention');
        }
        if (array_intersect($statuses, ['waiting']) !== []) {
            return __('Waiting');
        }

        return $filings === [] ? __('Not yet receipted') : __('Issued');
    }

    /** @return array<string, mixed> */
    public static function page(CustomerAccount $account): array
    {
        $property = Property::query()->findOrFail($account->property_id);
        $folios = $account->folios()->orderBy('currency')->get();
        $held = CloseCustomerAccount::held($account)->get();
        $fiscal = ! $property->isDemo() && $property->isLive() && JurisdictionRules::usesVfms($property);

        return [
            'account' => $account->only(['id', 'number', 'kind', 'customer_name', 'paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number', 'starts_on', 'ends_on', 'note', 'status']),
            'folios' => $folios->map(function (Folio $folio) {
                $summary = FolioSummary::of($folio->postings()->get());

                return [
                    'id' => $folio->id, 'currency' => $folio->currency->value,
                    'summary' => $summary,
                    'total' => [...$summary, 'currency' => $folio->currency->value, 'due' => $summary['balance'], 'estimate' => null,
                        'status' => $summary['balance'] <= 0 ? 'paid' : ($summary['net_payments'] > 0 ? 'partially_paid' : 'unpaid')],
                    'items' => $folio->postings()->whereNotIn('type', ['payment', 'refund'])->paginate(50, ['*'], 'items_'.$folio->id)->withQueryString(),
                    'payments' => $folio->postings()->whereIn('type', ['payment', 'refund'])->with('receipt')->paginate(50, ['*'], 'payments_'.$folio->id)->withQueryString(),
                ];
            }),
            'canPost' => $account->status === 'open' && Gate::allows('close', $account),
            'canClose' => $account->status === 'open' && Gate::allows('close', $account),
            'acceptedPaymentMethods' => $property->settings()['payment_settings']['accepted_methods'],
            'extraCategories' => PostExtraCharge::CATEGORIES,
            'fiscalEnabled' => $fiscal,
            'filings' => VfmsFilings::of($account, Gate::allows('recoverVfms', $account)),
            'documents' => IssuedDocument::query()->where('property_id', $account->property_id)->where('customer_account_id', $account->id)->orderBy('issued_at')->get(),
            'generalReceipt' => [
                'customer' => $account->getAttribute('paying_institution_name') ?: $account->customer_name,
                'items' => $held->map(fn (Posting $posting) => [
                    ...$posting->only(['id', 'description', 'category', 'quantity', 'unit_price', 'discount', 'amount']), 'currency' => $posting->currency->value,
                ]),
                'totals' => $held->groupBy(fn (Posting $posting) => $posting->currency->value)
                    ->map(fn ($postings, string $currency) => ['currency' => $currency, 'amount' => (int) $postings->sum('amount')])->values(),
                'url' => route('customer-accounts.close', $account),
            ],
        ];
    }
}
