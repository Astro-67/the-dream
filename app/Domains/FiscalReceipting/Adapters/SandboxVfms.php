<?php

namespace App\Domains\FiscalReceipting\Adapters;

use App\Domains\Billing\FolioSummary;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Contracts\VfmsGateway;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Money\Currency;
use App\Support\Outbox\DeliveryResult;

/** Answers like VFMS without leaving the server: fake SBX- numbers, refusing bodies missing guide-required fields. */
final class SandboxVfms implements VfmsGateway
{
    /** Guide-required body fields per request type. Later calls add theirs. */
    private const REQUIRED = [
        'ACCOMMODATION_REQUEST' => ['paymentPackage', 'checkInDate', 'guest', 'roomId', 'roomName', 'accommodationSource', 'accommodationPrice', 'accommodationDiscount', 'referenceNumber', 'currency'],
        'ACCOMMODATION_CHECK_OUT' => ['documentNumberList', 'currency'],
        'ACCOMMODATION_ADD_SERVICE' => ['documentNumber', 'service', 'referenceNumber', 'type', 'currency'],
        'ACCOMMODATION_CHANGE_ROOM' => ['roomName', 'roomId', 'documentNumber', 'referenceNumber', 'currency', 'accommodationPrice', 'accommodationDiscount'],
        'CHANGEROOM_PRICE' => ['rooms'],
        'ACCOMMODATION_ADD_GUEST' => ['firstGuestDocument', 'documentName', 'documentNo', 'arrivalDate', 'referenceNumber', 'country', 'fullName'],
        'GUEST_PROFILE' => ['oldDocumentNo', 'newDocumentNo', 'fullName', 'documentName', 'country', 'referenceNumber'],
    ];

    private const GUEST_REQUIRED = ['documentName', 'documentNo', 'country', 'fullName'];

    public function send(array $payload, VfmsCredential $credential): DeliveryResult
    {
        $body = $payload['body'];
        $missing = array_values(array_filter(self::REQUIRED[$payload['request_type']] ?? [], fn (string $field) => ! isset($body[$field]) || $body[$field] === '' || $body[$field] === []));
        if ($payload['request_type'] === 'CHANGEROOM_PRICE') {
            foreach ($body['rooms'] ?? [] as $index => $room) {
                foreach (['roomPrice', 'hotelRoomRoom'] as $field) {
                    if (! isset($room[$field]) || $room[$field] === '') {
                        $missing[] = "rooms.{$index}.{$field}";
                    }
                }
            }
        }
        foreach ($body['guest'] ?? [] as $index => $guest) {
            foreach (self::GUEST_REQUIRED as $field) {
                if (blank($guest[$field] ?? null)) {
                    $missing[] = "guest.{$index}.{$field}";
                }
            }
        }
        foreach ($payload['request_type'] === 'ACCOMMODATION_CHECK_OUT' ? $body['documentNumberList'] ?? [] : [] as $index => $document) {
            if (blank($document['documentNumber'] ?? null)) {
                $missing[] = "documentNumberList.{$index}.documentNumber";
            }
        }
        if (($body['accommodationSource'] ?? null) === 'TOUR OPERATOR') {
            $missing = [...$missing, ...array_values(array_filter(['tourRegNo', 'costOptions'], fn (string $field) => blank($body[$field] ?? null)))];
        }
        if ($payload['request_type'] === 'ACCOMMODATION_ADD_SERVICE') {
            $missing = [...$missing, ...$this->invalidServices($body)];
        }
        if ($missing !== []) {
            return new DeliveryResult('rejected', responseBody: ['status' => 400, 'missing' => $missing]);
        }
        if ($payload['request_type'] === 'ACCOMMODATION_CHECK_OUT') {
            return $this->checkoutReport($payload);
        }
        $receipt = 'SBX-'.strtoupper(substr(hash('sha256', $payload['request_type'].'|'.($body['referenceNumber'] ?? '')), 0, 12));

        return new DeliveryResult('accepted', ['receiptNumber' => $receipt], ['status' => 200, 'receiptNumber' => $receipt]);
    }

    /**
     * A fake check-out report echoing the folio as Thedream posted it, so the folio switch
     * and report run end to end without Sandbox inventing tax or debt on test stays.
     *
     * @param  array{mode: string, request_type: string, path: string, reservation_id?: string, body: array<string, mixed>}  $payload
     */
    private function checkoutReport(array $payload): DeliveryResult
    {
        $reservation = Reservation::query()->findOrFail($payload['reservation_id']);
        $currency = $payload['body']['currency'] === 'EURO' ? Currency::EUR : Currency::from($payload['body']['currency']);
        $posted = FolioSummary::of(Posting::query()->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $reservation->id)->where('currency', $currency->value))->get());
        $payloads = app(VfmsPayloads::class);
        $report = [
            'reportNo' => 'SBX-'.strtoupper(substr(hash('sha256', 'checkout|'.$reservation->id), 0, 12)),
            'totalAmount' => $payloads->amount($posted['charges'], $currency),
            'totalTax' => $payloads->amount($posted['vat'], $currency),
            'infrastructureTax' => $payloads->amount($posted['levy'], $currency),
        ];

        return new DeliveryResult('accepted', $report, ['status' => 200, ...$report]);
    }

    /** @param array<string, mixed> $body
     * @return list<string>
     */
    private function invalidServices(array $body): array
    {
        $invalid = [];
        if (($body['type'] ?? null) !== 'CS') {
            $invalid[] = 'type';
        }
        if (! is_array($body['service'] ?? null) || ! array_is_list($body['service'])) {
            return [...$invalid, 'service'];
        }
        foreach ($body['service'] as $index => $item) {
            if (! is_array($item) || ! is_string($item['itemName'] ?? null) || blank($item['itemName'])) {
                $invalid[] = "service.{$index}.itemName";
            }
            if (! is_array($item) || ! is_int($item['quantity'] ?? null) || $item['quantity'] < 1) {
                $invalid[] = "service.{$index}.quantity";
            }
            foreach (['price', 'discount'] as $field) {
                $value = is_array($item) ? ($item[$field] ?? null) : null;
                if ((! is_float($value) && ! is_int($value)) || $value < 0) {
                    $invalid[] = "service.{$index}.{$field}";
                }
            }
        }

        return $invalid;
    }
}
