<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\Reservations\BookingGroupMembers;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\OutboxAttempt;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The ZRA-style print of issued documents (spec #249): every figure is read from ZRA's stored answer
 * for that document, never recomputed from the folio, and no guest identity document is read.
 */
final class PrintedDocuments
{
    /**
     * One document, or (null) every issued document of the stay's booking group, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function for(Reservation|CustomerAccount $reservation, ?string $documentId): array
    {
        $account = $reservation instanceof CustomerAccount;
        $members = $account ? collect([$reservation]) : app(BookingGroupMembers::class)->reservations($reservation);
        $documents = IssuedDocument::query()->where('property_id', $reservation->property_id)->whereIn($account ? 'customer_account_id' : 'reservation_id', $members->pluck('id'))
            ->when($documentId !== null, fn ($query) => $query->whereKey($documentId))->orderBy('issued_at')->orderBy('id')->get();
        $property = Property::query()->findOrFail($reservation->property_id);
        $leads = $account ? collect() : Reservation::guestNames($members);

        return $documents->map(fn (IssuedDocument $document) => self::document($document, $property, $leads->get((string) $document->getAttribute('reservation_id'))))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private static function document(IssuedDocument $document, Property $property, ?string $lead): array
    {
        $url = $document->getAttribute('viewer_url');
        $answer = self::answer($document);
        $common = [
            'id' => $document->id, 'type' => $document->type, 'number' => $document->number, 'mode' => $document->mode,
            'currency' => $document->getAttribute('currency'), 'viewerUrl' => $url, 'qrSvg' => $url === null ? null : CheckoutReport::qr($url),
            'issuedAt' => $document->getAttribute('issued_at')?->toIso8601String(),
            'business' => ['name' => $property->name, 'street' => trim($property->address_line.', '.$property->city, ', '), 'phone' => $property->phone,
                'tin' => $property->tin, 'vrn' => $property->vrn, 'znumber' => $property->z_number],
            'customer' => null, 'items' => [], 'rooms' => [], 'taxExclusive' => null, 'tax' => null, 'levy' => null, 'total' => null,
        ];
        if ($answer === null) {
            return $common;
        }

        return $document->type === 'checkout_report'
            ? self::report($common, $answer, $document->number)
            : self::receipt($common, $answer, $document->type === 'general_receipt' ? null : $lead);
    }

    /**
     * A receipt: ZRA's service/ answer. A guest receipt names a document number, so it prints the guest's name instead.
     *
     * @param  array<string, mixed>  $common
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private static function receipt(array $common, array $answer, ?string $guestName): array
    {
        if (! in_array($answer['currency'] ?? $common['currency'], ['USD', 'EURO', 'EUR', 'TZS'], true)) {
            return $common;
        }
        $payloads = app(VfmsPayloads::class);
        $currency = $payloads->fromVfmsCurrency($answer['currency'] ?? $common['currency']);
        $minor = fn (mixed $value): ?int => is_numeric($value) ? $payloads->minor($value, $currency) : null;

        return [...$common, 'currency' => $currency->value, 'customer' => $common['type'] === 'general_receipt' ? ($answer['documentNumber'] ?? null) : $guestName,
            'issuedAt' => self::instant($answer['recordedAt'] ?? null) ?? $common['issuedAt'],
            'items' => array_map(fn (array $line) => [
                'name' => $line['itemName'] ?? __('Item'), 'quantity' => $line['quantity'] + 0, 'price' => $minor($line['price']), 'discount' => $minor($line['discount'] ?? 0),
                'amount' => $minor($line['price'] * $line['quantity'] - ($line['discount'] ?? 0)),
            ], array_values(array_filter($answer['service'] ?? [], is_array(...)))),
            'taxExclusive' => $minor($answer['taxExclusive'] ?? null), 'tax' => $minor($answer['tax'] ?? null), 'total' => $minor($answer['salesAmount'] ?? null) ?? $common['total']];
    }

    /**
     * A guest's check-out report, from the entry ZRA answered for that report number.
     *
     * @param  array<string, mixed>  $common
     * @param  array<string, mixed>  $answer
     * @return array<string, mixed>
     */
    private static function report(array $common, array $answer, string $number): array
    {
        $entry = collect($answer)->first(fn ($report) => is_array($report) && ($report['reportNumber'] ?? null) === $number);
        if (! is_array($entry)) {
            return $common;
        }
        $payloads = app(VfmsPayloads::class);
        $currency = $payloads->fromVfmsCurrency($entry['accommodation']['currency']);
        $minor = fn (mixed $value): ?int => is_numeric($value) ? $payloads->minor($value, $currency) : null;
        $business = [...$common['business'], ...array_filter([
            'name' => $entry['businessName'] ?? null, 'street' => $entry['street'] ?? null,
            'tin' => $entry['tinNumber'] ?? null, 'vrn' => $entry['vrnNumber'] ?? null, 'znumber' => $entry['znumber'] ?? null,
        ], is_string(...))];

        return [...$common, 'currency' => $currency->value, 'business' => $business, 'customer' => $entry['guest']['fullName'] ?? null,
            'rooms' => array_map(fn (array $line) => [
                'room' => $line['roomName'], 'days' => $line['days'] + 0, 'price' => $minor($line['accommodationPrice']), 'amount' => $minor($line['amount']), 'tax' => $minor($line['tax']),
            ], $entry['room']['room'] ?? []),
            'items' => array_map(fn (array $line) => [
                'name' => $line['itemName'] ?? __('Item'), 'quantity' => $line['quantity'] + 0, 'price' => $minor($line['price']), 'discount' => $minor($line['discount'] ?? 0), 'amount' => $minor($line['amount']),
            ], $entry['service']['service'] ?? []),
            'tax' => $minor($entry['total']['totalTax'] ?? null), 'levy' => $minor($entry['totalInfrastructure'] ?? null), 'total' => $minor($entry['total']['totalAmount'] ?? null) ?? $common['total']];
    }

    /** ZRA's accepted answer for the document, or null when it was never stored or no longer reads.
     * @return array<string|int, mixed>|null
     */
    private static function answer(IssuedDocument $document): ?array
    {
        $attempt = OutboxAttempt::query()->where('property_id', $document->property_id)->where('outbox_message_id', $document->getAttribute('outbox_message_id'))->where('outcome', 'accepted')->whereKey($document->getAttribute('outbox_attempt_id'))->first();
        $body = $attempt?->response_body === null ? null : json_decode($attempt->response_body, true)['body'] ?? null;

        return is_array($body) ? $body : null;
    }

    private static function instant(mixed $value): ?string
    {
        try {
            return is_string($value) ? CarbonImmutable::parse($value)->utc()->toIso8601String() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
