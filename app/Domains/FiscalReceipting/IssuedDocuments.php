<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use stdClass;

/**
 * Issued documents (spec #228): projects each fiscal document VFMS issued from delivered outbox
 * evidence, and reads a property's register of those beside its non-fiscal receipts. Rows hold
 * only issue-time evidence; what the evidence lacks stays null, never rebuilt from today's data.
 */
final class IssuedDocuments
{
    public const TYPES = ['non_fiscal', 'services_receipt', 'checkout_report'];

    public const ZRA_STATUSES = ['live', 'sandbox', 'not_applicable'];

    private const EVENTS = ['services' => 'services_receipt', 'checkout' => 'checkout_report', 'guestDeparture' => 'checkout_report'];

    /** Idempotent: an existing document (same property, mode, type and number) is left as first projected. */
    public function project(OutboxMessage $message): void
    {
        $type = self::EVENTS[$message->event] ?? null;
        $references = $message->getAttribute('provider_references') ?? [];
        $mode = $references['mode'] ?? null;
        if ($message->integration !== VfmsAdapter::INTEGRATION || $message->status !== 'delivered' || $type === null || ! in_array($mode, ['live', 'sandbox'], true)) {
            return;
        }
        $attempt = $message->attempts()->where('outcome', 'accepted')->orderByDesc('attempt_number')->first();
        $common = [
            'outbox_message_id' => $message->id, 'outbox_attempt_id' => $attempt?->id, 'reservation_id' => $message->subject_id,
            'type' => $type, 'mode' => $mode, 'issued_at' => $message->delivered_at ?? $message->updated_at,
        ];
        $documents = $type === 'services_receipt' ? $this->receipt($message, $references) : $this->reports($message, $references, $attempt?->response_body);
        // All of a message's documents or none, so the catch-up can skip any message with a row.
        DB::transaction(function () use ($documents, $message, $mode, $type, $common): void {
            foreach ($documents as $document) {
                IssuedDocument::query()->firstOrCreate(
                    ['property_id' => $message->property_id, 'mode' => $mode, 'type' => $type, 'number' => $document['number']],
                    [...$common, ...$document],
                );
            }
        });
    }

    /** Projects every delivered VFMS filing of every property, in chunks; safe to rerun after a crash. */
    public function catchUp(): int
    {
        $properties = Tenancy::within(TenantContext::platform('issued document catch-up'), fn () => Property::query()->get());
        $messages = 0;
        foreach ($properties as $property) {
            Tenancy::within(TenantContext::forProperty($property), function () use ($property, &$messages): void {
                OutboxMessage::query()->where('property_id', $property->id)->where('integration', VfmsAdapter::INTEGRATION)
                    ->where('status', 'delivered')->whereIn('event', array_keys(self::EVENTS))
                    ->whereNotExists(fn (Builder $query) => $query->from('issued_documents')->whereColumn('issued_documents.outbox_message_id', 'outbox_messages.id'))
                    ->chunkById(200, function ($chunk) use (&$messages): void {
                        foreach ($chunk as $message) {
                            $this->project($message);
                            $messages++;
                        }
                    });
            });
        }

        return $messages;
    }

    /**
     * The property's issued documents, newest first, 50 a page.
     *
     * @param  array<string, mixed>  $filters  from/to (property-local Y-m-d, inclusive), type, zra_status
     * @return LengthAwarePaginator<int|string, array{id: string, type: string, number: string, issuedAt: string, guest: mixed, amount: int|null, currency: mixed, zraStatus: string, viewerUrl: mixed, reservationId: string}> guest, currency and viewerUrl are strings or null
     */
    public function register(Property $property, array $filters): LengthAwarePaginator
    {
        $filters = Validator::make($filters, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', 'in:'.implode(',', self::TYPES)],
            'zra_status' => ['nullable', 'in:'.implode(',', self::ZRA_STATUSES)],
        ])->validate();
        $receipts = Receipt::query()->where('receipts.property_id', $property->id)
            ->join('postings', 'postings.id', '=', 'receipts.posting_id')->join('folios', 'folios.id', '=', 'postings.folio_id')
            ->where('postings.property_id', $property->id)->where('folios.property_id', $property->id)
            ->select('receipts.id', 'receipts.number', 'receipts.issued_at', 'folios.reservation_id', 'postings.currency')
            ->selectRaw("'non_fiscal' as type, -postings.amount as amount, 'not_applicable' as zra_status, null as guest_name, null as viewer_url")
            ->toBase();
        $fiscal = IssuedDocument::query()->where('property_id', $property->id)
            ->select('id', 'number', 'issued_at', 'reservation_id', 'currency', 'type', 'amount')
            ->selectRaw('mode as zra_status')->addSelect('guest_name', 'viewer_url')
            ->toBase();
        $local = fn (string $date) => CarbonImmutable::parse($date, $property->timezone)->utc();

        $page = DB::query()->fromSub($receipts->unionAll($fiscal), 'documents')
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('issued_at', '>=', $local($from)))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('issued_at', '<', $local($to)->addDay()))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['zra_status'] ?? null, fn (Builder $query, string $status) => $query->where('zra_status', $status))
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate(50)->withQueryString();
        // Payers of non-fiscal receipts: the stay's lead guest, or its booker (as payment reports show them).
        $names = Reservation::guestNames(Reservation::query()->where('property_id', $property->id)
            ->whereIn('id', collect($page->items())->where('type', 'non_fiscal')->pluck('reservation_id'))->get());

        // Raw union rows are untyped; every column but amount/guest/currency/viewer is non-null in both sources.
        return $page->through(fn (stdClass $row): array => [
            'id' => (string) $row->id, 'type' => (string) $row->type, 'number' => (string) $row->number,
            'issuedAt' => CarbonImmutable::parse((string) $row->issued_at, 'UTC')->toIso8601String(),
            'guest' => $row->type === 'non_fiscal' ? $names->get((string) $row->reservation_id) : $row->guest_name,
            'amount' => $row->amount === null ? null : (int) $row->amount, 'currency' => $row->currency,
            'zraStatus' => (string) $row->zra_status, 'viewerUrl' => $row->viewer_url, 'reservationId' => (string) $row->reservation_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $references
     * @return list<array<string, mixed>>
     */
    private function receipt(OutboxMessage $message, array $references): array
    {
        $number = $references['receiptNumber'] ?? null;
        if (! is_string($number) || $number === '') {
            return [];
        }
        $body = $message->payload['body'];
        $payloads = app(VfmsPayloads::class);
        $currency = $payloads->fromVfmsCurrency($body['currency']);
        $reservation = Reservation::query()->where('property_id', $message->property_id)->find($message->subject_id);

        return [[
            'number' => $number, 'posting_id' => $message->payload['posting_id'] ?? null,
            // The amount filed, as the ZRA filings report shows it.
            'amount' => $payloads->minor(array_sum(array_map(fn (array $item) => $item['price'] * $item['quantity'] - $item['discount'], $body['service'])), $currency),
            'currency' => $currency->value,
            'guest_name' => $reservation === null ? null : Reservation::guestNames(collect([$reservation]))->get($reservation->id),
            'viewer_url' => is_string($references['receiptUrl'] ?? null) ? $references['receiptUrl'] : null,
        ]];
    }

    /**
     * One row per guest report: from the accepted response when it still validates, otherwise
     * the stored report numbers alone, with amount, guest and currency unavailable.
     *
     * @param  array<string, mixed>  $references
     * @return list<array<string, mixed>>
     */
    private function reports(OutboxMessage $message, array $references, ?string $responseBody): array
    {
        $urls = is_string($references['reportUrls'] ?? null) ? json_decode($references['reportUrls'], true) : null;
        $url = fn (string $number) => is_array($urls) && is_string($urls[$number] ?? null) ? $urls[$number] : null;
        $body = $responseBody === null ? null : json_decode($responseBody, true)['body'] ?? null;
        if (is_array($body) && app(CheckoutFigures::class)->of($body, $message->payload['body']) !== null) {
            $payloads = app(VfmsPayloads::class);

            return array_map(function (array $report) use ($payloads, $url) {
                $currency = $payloads->fromVfmsCurrency($report['accommodation']['currency']);

                // Each guest's own report total, never the stay's aggregate repeated per guest.
                return [
                    'number' => $report['reportNumber'], 'amount' => $payloads->minor($report['total']['totalAmount'], $currency),
                    'currency' => $currency->value, 'guest_name' => $report['guest']['fullName'], 'viewer_url' => $url($report['reportNumber']),
                ];
            }, $body);
        }
        $numbers = is_string($references['reportNumbers'] ?? null) ? json_decode($references['reportNumbers'], true) : null;
        $numbers = is_array($numbers) ? $numbers : [$references['reportNo'] ?? null];

        return array_values(array_map(
            fn (string $number) => ['number' => $number, 'amount' => null, 'currency' => null, 'guest_name' => null, 'viewer_url' => $url($number)],
            array_filter($numbers, fn ($number) => is_string($number) && $number !== ''),
        ));
    }
}
