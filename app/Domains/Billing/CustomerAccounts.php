<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Actions\CloseCustomerAccount;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\CheckoutReport;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsFilings;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\Tenancy;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class CustomerAccounts
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, array{id: string, number: string, kind: string, customer_name: string, starts_on: string|null, ends_on: string|null, status: string, balances: Collection<int, array{currency: string, amount: int}>, receiptStatus: string}>
     */
    public static function listing(array $filters): LengthAwarePaginator
    {
        $property = Tenancy::property();
        $fiscal = ! $property->isDemo() && $property->isLive() && JurisdictionRules::usesVfms($property);

        return CustomerAccount::query()->with(['folios' => fn ($query) => $query->withSum('postings', 'amount')])
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(50)->withQueryString()
            ->through(fn (CustomerAccount $account) => [
                ...$account->only(['id', 'number', 'kind', 'customer_name', 'starts_on', 'ends_on', 'status']),
                'balances' => $account->folios->map(fn (Folio $folio) => ['currency' => $folio->currency->value, 'amount' => (int) $folio->getAttribute('postings_sum_amount')]),
                'receiptStatus' => self::receiptStatus($account, $fiscal),
            ]);
    }

    private static function receiptStatus(CustomerAccount $account, bool $fiscal): string
    {
        if (! $fiscal) {
            return __('Not applicable');
        }
        $filings = VfmsFilings::of($account, false);
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
            'zraDocuments' => self::zraDocuments($account),
            'account' => $account->only(['id', 'number', 'kind', 'customer_name', 'paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number', 'starts_on', 'ends_on', 'note', 'status']),
            'folios' => $folios->map(function (Folio $folio) {
                $summary = FolioSummary::of($folio->postings()->get());

                return [
                    'id' => $folio->id, 'currency' => $folio->currency->value,
                    'summary' => $summary,
                    'total' => [...$summary, 'currency' => $folio->currency->value, 'due' => $summary['balance'], 'estimate' => null,
                        'status' => $summary['balance'] <= 0 ? 'paid' : ($summary['net_payments'] > 0 ? 'partially_paid' : 'unpaid')],
                    'items' => $folio->postings()->whereNot(fn ($query) => $query->moneyMovements())->paginate(50, ['*'], 'items_'.$folio->id)->withQueryString(),
                    'payments' => $folio->postings()->moneyMovements()->with('receipt')->paginate(50, ['*'], 'payments_'.$folio->id)->withQueryString(),
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

    /** Shared ZRA documents sections for an account, with the same status and print actions as a stay.
     * @return array<string, mixed>
     */
    private static function zraDocuments(CustomerAccount $account): array
    {
        $held = CloseCustomerAccount::held($account)->get();
        $documents = IssuedDocument::query()->where('property_id', $account->property_id)->where('customer_account_id', $account->id)->get();
        $receipts = OutboxMessage::query()->where('property_id', $account->property_id)->where('integration', 'vfms')
            ->where('subject_type', $account->getMorphClass())->where('subject_id', $account->id)->where('event', 'generalReceipt')->orderBy('sequence')->get()
            ->map(function (OutboxMessage $message) use ($account, $documents) {
                $document = $documents->firstWhere('outbox_message_id', $message->id);
                $url = $document?->getAttribute('viewer_url');

                return [
                    'number' => $document?->number, 'customer' => $message->payload['body']['documentNumber'], 'items' => count($message->payload['body']['service']),
                    ...CheckoutReport::filed($message), 'mode' => $message->payload['mode'], 'issuedAt' => $document?->getAttribute('issued_at')?->toIso8601String(),
                    'portalUrl' => $url, 'qrSvg' => $url === null ? null : CheckoutReport::qr($url),
                    ...CheckoutReport::state($message, $account),
                    'printUrl' => $document === null ? null : route('customer-accounts.print', [$account, 'document' => $document->id]),
                ];
            })->all();

        return [
            'status' => 'waiting', 'sandbox' => VfmsCredential::for(Property::query()->findOrFail($account->property_id))->mode === VfmsMode::Sandbox,
            'rooms' => 0, 'payingInstitution' => null, 'summary' => $account->folios()->with('postings')->get()->map(fn (Folio $folio) => [
                'currency' => $folio->currency->value, ...FolioSummary::of($folio->postings), 'held' => (int) $held->where('currency', $folio->currency)->sum('amount'),
            ])->all(),
            'held' => $held->map(fn (Posting $posting) => [...$posting->only(['id', 'description', 'category', 'quantity', 'unit_price', 'discount', 'amount']),
                'currency' => $posting->currency->value, 'room' => null, 'postedAt' => $posting->posted_at->toIso8601String(),
            ])->all(),
            'generalReceipts' => $receipts, 'serviceReceipts' => [], 'guestReports' => [], 'guests' => [], 'generalReceipt' => null,
            'revealIdentityUrl' => null, 'reservationUrl' => null, 'printAllUrl' => route('customer-accounts.print', $account),
            'stay' => ['reservation_number' => $account->number, 'room' => null, 'room_type' => '', 'arrival' => '', 'departure' => '', 'nights' => 0],
        ];
    }
}
