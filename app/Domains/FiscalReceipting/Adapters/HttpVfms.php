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
use Illuminate\Http\Client\Response;
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
        if (in_array($payload['request_type'], ['ERROR_SAVE', 'ERRORS_MANAGEMENTS'], true)) {
            $path = $payload['request_type'] === 'ERROR_SAVE' ? 'new' : 'errors';
            if (! $live || $payload['path'] !== $path || ($credential->unit_id ?? 0) < 1
                || ($payload['request_type'] === 'ERROR_SAVE'
                    && (! is_int($payload['body']['unitId'] ?? null) || $payload['body']['unitId'] !== $credential->unit_id))) {
                return new DeliveryResult('rejected', responseBody: ['error' => 'Error management requires Live mode, a valid business-unit ID and the fixed operation target.']);
            }
            $origin = parse_url($url);
            if (! is_array($origin) || ($origin['scheme'] ?? null) !== 'https' || empty($origin['host']) || isset($origin['user']) || isset($origin['pass'])) {
                return new DeliveryResult('rejected', responseBody: ['error' => 'Error management requires an HTTPS gateway origin without URL credentials.']);
            }
            $url = 'https://'.$origin['host'].(isset($origin['port']) ? ':'.$origin['port'] : '').'/vfms/error-management/';
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
                $roomIds[] = $room[$payload['request_type'] === 'RESERVATION_BOOKING' ? 'roomId' : 'hotelRoomRoom'];
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
                $field = $payload['request_type'] === 'RESERVATION_BOOKING' ? 'roomId' : 'hotelRoomRoom';
                $body['rooms'][$index][$field] = (int) ($prefix.str_pad((string) $room[$field], 4, '0', STR_PAD_LEFT));
            }
            if (isset($body['referenceNumber'])) {
                $body['referenceNumber'] = 'T'.$prefix.'-'.$body['referenceNumber'];
            }
        }
        try {
            $response = $this->post($url, $payload['request_type'], $payload['path'], $integrationId, $token, $body);
        } catch (ConnectionException) {
            return new DeliveryResult('unknown');
        }
        $status = $response->status();
        $data = $response->json();
        $responseBody = ['status' => $status, 'body' => $data ?? $response->body()];
        if ($live && $status === 401) {
            $this->markRefused($credential);
        }

        if ($payload['request_type'] === 'ERROR_SAVE') {
            // Section 3's 200/201 interpretation is unverified against an authenticated acknowledgement.
            if (is_array($data) && preg_match('/duplicate|already\s+exists/i', json_encode($data, JSON_THROW_ON_ERROR))) {
                return new DeliveryResult('unknown', responseBody: $responseBody);
            }
            if ($status >= 500 || ! is_array($data) || array_is_list($data)) {
                return new DeliveryResult('unknown', responseBody: $responseBody);
            }
            $applicationStatus = $data['status'] ?? null;
            $success = in_array($applicationStatus, [200, 201, '200', '201'], true);
            if ($status >= 200 && $status < 300 && $success && ! array_key_exists('statusCode', $data)
                && ! isset($data['error']) && ! isset($data['errors'])
                && ! preg_match('/fail|reject|refus|invalid|error|unsuccess/i', json_encode([$data['statusMessage'] ?? '', $data['message'] ?? ''], JSON_THROW_ON_ERROR))) {
                return new DeliveryResult('accepted', responseBody: $responseBody);
            }
            if (! $success && (in_array($status, [400, 401, 403, 404], true)
                || in_array($applicationStatus, [400, 401, 403, 404, 5000, '400', '401', '403', '404', '5000'], true)
                || in_array($data['statusCode'] ?? null, [5000, '5000'], true))) {
                return new DeliveryResult('rejected', responseBody: $responseBody);
            }

            return new DeliveryResult('unknown', responseBody: $responseBody);
        }

        $errors = is_array($data)
            ? ($status >= 400 ? $data : array_intersect_key($data, array_flip(['statusMessage', 'error', 'errors'])))
            : ($status >= 400 ? $response->body() : '');
        if (preg_match('/duplicate|already\s+exists/i', json_encode($errors, JSON_THROW_ON_ERROR))) {
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
     * Checks the property's own credentials with ZRA's production gateway by its read-only
     * exchange-rate request, whatever the property's mode. Files nothing.
     *
     * @return 'accepted'|'refused'|'unreachable'
     */
    public function checkCredentials(VfmsCredential $credential): string
    {
        $url = config('services.vfms.live_url');
        if (blank($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! $credential->isComplete()) {
            return 'unreachable';
        }
        try {
            $response = $this->post($url, 'CURRENCY_EXCHANGE', 'currencyExchangeRate/', (string) $credential->integration_id, (string) $credential->token, []);
        } catch (ConnectionException) {
            return 'unreachable';
        }
        $data = $response->json();
        if (in_array($response->status(), [401, 403], true)
            || ($response->successful() && is_array($data) && in_array($data['statusCode'] ?? null, [5000, '5000'], true))) {
            return 'refused';
        }

        return $response->successful() && is_array($data) && $this->acceptedReferences('CURRENCY_EXCHANGE', [], $data) !== null
            ? 'accepted'
            : 'unreachable';
    }

    /**
     * ZRA's current exchange rates through one property's Live credentials (read-only, files nothing),
     * or null when ZRA refuses, cannot be reached or replies without every rate.
     *
     * @return array{rateUSD: float|int|string, rateEURO: float|int|string, rateUSDEURO: float|int|string}|null
     */
    public function exchangeRates(VfmsCredential $credential): ?array
    {
        $url = config('services.vfms.live_url');
        if (blank($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! $credential->isComplete()) {
            return null;
        }
        try {
            $response = $this->post($url, 'CURRENCY_EXCHANGE', 'currencyExchangeRate/', (string) $credential->integration_id, (string) $credential->token, []);
        } catch (ConnectionException) {
            return null;
        }
        $data = $response->json();

        return $response->successful() && is_array($data) ? $this->acceptedReferences('CURRENCY_EXCHANGE', [], $data) : null;
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $url, string $requestType, string $path, string $integrationId, string $token, array $body): Response
    {
        return Http::timeout(30)->withoutRedirecting()->withHeaders([
            'Content-type' => 'application/json',
            'vfms-request-type' => $requestType,
            // The guide's spelling, kept verbatim.
            'vfms-intergration-id' => $integrationId,
            'vfms-token-id' => $token,
        ])->post(rtrim($url, '/').'/'.$path, $body);
    }

    private function markRefused(VfmsCredential $credential): void
    {
        Tenancy::within(TenantContext::fromIds($credential->organization_id, $credential->property_id), fn () => $credential->forceFill(['credentials_refused_at' => now()])->save());
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
        // ZRA nests the booking: {"receiptNumber": …, "referenceNumber": …, "booking": {"bookingNumber": …}} (test server, 3 Nov 2026).
        $booking = is_array($response['booking'] ?? null) ? ($response['booking']['bookingNumber'] ?? null) : null;
        $reference = $this->number($response['referenceNumber'] ?? null) ? ['referenceNumber' => $response['referenceNumber']] : [];
        if ($operation === 'RESERVATION_BOOKING') {
            return $this->number($booking) && $this->number($response['receiptNumber'] ?? null)
                ? ['bookingNumber' => $booking, 'receiptNumber' => $response['receiptNumber'], ...$reference]
                : null;
        }
        // The advance check-in returns no responseNumber; it echoes the booking it linked.
        if ($operation === 'ACCOMMODATION_BOOKING_REQUEST') {
            return $this->number($booking) && $booking === ($request['bookingNumber'] ?? null) ? ['bookingNumber' => $booking, ...$reference] : null;
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

            return null;
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
