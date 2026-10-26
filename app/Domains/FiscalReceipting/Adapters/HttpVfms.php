<?php

namespace App\Domains\FiscalReceipting\Adapters;

use App\Domains\FiscalReceipting\CheckoutFigures;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Support\Outbox\DeliveryResult;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Posts to ZRA's test or production gateway, never mixing their credentials. */
final class HttpVfms
{
    /** @param array{mode: string, request_type: string, path: string, body: array<string, mixed>} $payload */
    public function send(array $payload, VfmsCredential $credential): DeliveryResult
    {
        $mode = VfmsMode::tryFrom($payload['mode']);
        if ($mode === null || $mode !== $credential->mode) {
            return new DeliveryResult('rejected', responseBody: ['error' => 'VFMS mode changed; review the queued filing before sending.']);
        }
        $live = $mode === VfmsMode::Live;
        $url = config($live ? 'services.vfms.live_url' : 'services.vfms.test_url');
        $integrationId = $live ? $credential->integration_id : config('services.vfms.test_integration_id');
        $token = $live ? $credential->token : config('services.vfms.test_token');
        if (blank($url) || blank($integrationId) || blank($token)) {
            return new DeliveryResult('rejected', responseBody: ['error' => 'VFMS endpoint or credentials are incomplete.']);
        }
        if ($live && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return new DeliveryResult('rejected', responseBody: ['error' => 'The Live VFMS endpoint must use HTTPS.']);
        }
        $body = $payload['body'];
        if (! $live) {
            if (blank($credential->property_id)) {
                return new DeliveryResult('rejected', responseBody: ['error' => 'A property is required for ZRA test filings.']);
            }
            // shortcut: 90,000 property prefixes share ZRA's test unit; use assigned ranges if collisions occur.
            $prefix = (string) (crc32($credential->property_id) % 90000 + 10000);
            $roomIds = isset($body['roomId']) ? [$body['roomId']] : [];
            foreach ($body['rooms'] ?? [] as $room) {
                $roomIds[] = $room['hotelRoomRoom'];
            }
            foreach ($roomIds as $roomId) {
                if (! is_int($roomId) || $roomId < 1 || $roomId > 9999) {
                    return new DeliveryResult('rejected', responseBody: ['error' => 'ZRA test room IDs must be between 1 and 9999.']);
                }
            }
            if (isset($body['roomId'])) {
                $body['roomId'] = (int) ($prefix.str_pad((string) $body['roomId'], 4, '0', STR_PAD_LEFT));
            }
            foreach ($body['rooms'] ?? [] as $index => $room) {
                $body['rooms'][$index]['hotelRoomRoom'] = (int) ($prefix.str_pad((string) $room['hotelRoomRoom'], 4, '0', STR_PAD_LEFT));
            }
            if (isset($body['referenceNumber'])) {
                $body['referenceNumber'] = 'T'.$prefix.'-'.$body['referenceNumber'];
            }
        }
        try {
            $response = Http::timeout(30)->withHeaders([
                'Content-type' => 'application/json',
                'vfms-request-type' => $payload['request_type'],
                // The guide's spelling, kept verbatim.
                'vfms-intergration-id' => $integrationId,
                'vfms-token-id' => $token,
            ])->post(rtrim($url, '/').'/'.$payload['path'], $body);
        } catch (ConnectionException) {
            return new DeliveryResult('unknown');
        }
        $status = $response->status();
        $data = $response->json();
        $responseBody = ['status' => $status, 'body' => $data ?? $response->body()];
        if ($live && $status === 401) {
            Tenancy::within(TenantContext::fromIds($credential->organization_id, $credential->property_id), fn () => $credential->forceFill(['credentials_refused_at' => now()])->save());
        }

        if (preg_match('/duplicate|already\s+exists/i', $response->body())) {
            return new DeliveryResult('unknown', responseBody: $responseBody);
        }
        if (in_array($status, [400, 401, 403, 404], true)
            || ($status >= 200 && $status < 300 && is_array($data) && in_array($data['statusCode'] ?? null, [5000, '5000'], true))) {
            return new DeliveryResult('rejected', responseBody: $responseBody);
        }
        $references = $status >= 200 && $status < 300 && is_array($data)
            && ($payload['request_type'] !== 'CHANGEROOM_PRICE' || is_array(json_decode($response->body())))
            ? $this->acceptedReferences($payload['request_type'], $body, $data)
            : null;

        return new DeliveryResult($references === null ? 'unknown' : 'accepted', $references ?? [], $responseBody);
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<array-key, mixed>  $response
     * @return array<string, scalar|null>|null
     */
    private function acceptedReferences(string $operation, array $request, array $response): ?array
    {
        if (array_key_exists('statusCode', $response)) {
            return null;
        }
        if (in_array($operation, ['ACCOMMODATION_REQUEST', 'ACCOMMODATION_CHANGE_ROOM', 'ACCOMMODATION_ADD_GUEST', 'GUEST_PROFILE'], true)) {
            return $this->number($response['responseNumber'] ?? null) ? ['responseNumber' => $response['responseNumber']] : null;
        }
        if ($operation === 'ACCOMMODATION_ADD_SERVICE') {
            if (! $this->number($response['receiptNumber'] ?? null)
                || (array_key_exists('responseNumber', $response) && ! $this->number($response['responseNumber']))) {
                return null;
            }

            return array_intersect_key($response, array_flip(['receiptNumber', 'responseNumber']));
        }
        if ($operation === 'ACCOMMODATION_CHECK_OUT') {
            if (array_is_list($response)) {
                $figures = app(CheckoutFigures::class)->of($response, $request);
                if ($figures === null) {
                    return null;
                }
                $payloads = app(VfmsPayloads::class);
                $currency = $payloads->fromVfmsCurrency($request['currency']);

                return [
                    'reportNo' => $figures['numbers'][0],
                    'reportNumbers' => json_encode($figures['numbers'], JSON_THROW_ON_ERROR),
                    'roomAmount' => $payloads->amount($figures['room'], $currency),
                    'extrasAmount' => $payloads->amount($figures['extras'], $currency),
                    'totalAmount' => $payloads->amount($figures['total'], $currency),
                    'totalTax' => $payloads->amount($figures['tax'], $currency),
                    'infrastructureTax' => $payloads->amount($figures['levy'], $currency),
                ];
            }
            if (! $this->number($response['reportNo'] ?? null)) {
                return null;
            }
            foreach (['totalAmount', 'totalTax', 'infrastructureTax'] as $field) {
                if (! $this->amount($response[$field] ?? null)) {
                    return null;
                }
            }

            return array_intersect_key($response, array_flip(['reportNo', 'totalAmount', 'totalTax', 'infrastructureTax']));
        }
        if ($operation === 'CURRENCY_EXCHANGE') {
            foreach (['rateUSD', 'rateEURO', 'rateUSDEURO'] as $field) {
                if (! $this->amount($response[$field] ?? null)) {
                    return null;
                }
            }

            return array_intersect_key($response, array_flip(['rateUSD', 'rateEURO', 'rateUSDEURO']));
        }
        if ($operation === 'CHANGEROOM_PRICE') {
            $rooms = $request['rooms'] ?? [];
            if ($rooms === [] || ! array_is_list($response) || count($rooms) !== count($response)) {
                return null;
            }
            foreach ($rooms as $index => $room) {
                $returned = $response[$index];
                if (! is_array($returned)
                    || ! (is_string($returned['hotelRoomRoom'] ?? null) || is_int($returned['hotelRoomRoom'] ?? null))
                    || ! preg_match('/^'.preg_quote((string) $room['hotelRoomRoom'], '/').'\d*$/D', (string) $returned['hotelRoomRoom'])
                    || ! $this->amount($returned['roomPrice'] ?? null)
                    || (float) $returned['roomPrice'] !== (float) $room['roomPrice']) {
                    return null;
                }
            }

            return [];
        }

        return null;
    }

    private function number(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function amount(mixed $value): bool
    {
        return (is_int($value) || is_float($value) || is_string($value))
            && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0;
    }
}
