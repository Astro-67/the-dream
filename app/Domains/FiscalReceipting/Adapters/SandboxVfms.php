<?php

namespace App\Domains\FiscalReceipting\Adapters;

use App\Domains\FiscalReceipting\Contracts\VfmsGateway;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Support\Outbox\DeliveryResult;

/** Answers like VFMS without leaving the server: fake SBX- numbers, refusing bodies missing guide-required fields. */
final class SandboxVfms implements VfmsGateway
{
    /** Guide-required body fields per request type. Later calls add theirs. */
    private const REQUIRED = [
        'ACCOMMODATION_REQUEST' => ['paymentPackage', 'checkInDate', 'guest', 'roomId', 'roomName', 'accommodationSource', 'accommodationPrice', 'accommodationDiscount', 'referenceNumber', 'currency'],
        'ACCOMMODATION_CHANGE_ROOM' => ['roomName', 'roomId', 'documentNumber', 'referenceNumber', 'currency', 'accommodationPrice', 'accommodationDiscount'],
        'CHANGEROOM_PRICE' => ['rooms'],
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
        if (($body['accommodationSource'] ?? null) === 'TOUR OPERATOR') {
            $missing = [...$missing, ...array_values(array_filter(['tourRegNo', 'costOptions'], fn (string $field) => blank($body[$field] ?? null)))];
        }
        if ($missing !== []) {
            return new DeliveryResult('rejected', responseBody: ['status' => 400, 'missing' => $missing]);
        }
        $receipt = 'SBX-'.strtoupper(substr(hash('sha256', $payload['request_type'].'|'.($body['referenceNumber'] ?? '')), 0, 12));

        return new DeliveryResult('accepted', ['receiptNumber' => $receipt], ['status' => 200, 'receiptNumber' => $receipt]);
    }
}
