<?php

namespace App\Domains\FiscalReceipting\Adapters;

use App\Domains\FiscalReceipting\Contracts\VfmsGateway;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Support\Outbox\DeliveryResult;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Posts to ZRA's production VFMS gateway (guide §1.4); there is no test server. */
final class HttpVfms implements VfmsGateway
{
    public const BASE_URL = 'https://gateway.zanrevenue.org/api/';

    public function send(array $payload, VfmsCredential $credential): DeliveryResult
    {
        try {
            $response = Http::timeout(30)->withHeaders([
                'Content-type' => 'application/json',
                'vfms-request-type' => $payload['request_type'],
                // The guide's spelling, kept verbatim.
                'vfms-intergration-id' => $credential->integration_id,
                'vfms-token-id' => $credential->token,
            ])->post(self::BASE_URL.$payload['path'], $payload['body']);
        } catch (ConnectionException) {
            return new DeliveryResult('unknown');
        }
        $status = $response->status();
        $body = ['status' => $status, 'body' => $response->json() ?? $response->body()];
        if ($status === 401) {
            Tenancy::within(TenantContext::fromIds($credential->organization_id, $credential->property_id), fn () => $credential->forceFill(['credentials_refused_at' => now()])->save());
        }

        return match (true) {
            in_array($status, [200, 201], true) => new DeliveryResult('accepted', array_filter(['receiptNumber' => $response->json('receiptNumber')]), $body),
            in_array($status, [400, 401, 403, 404], true) => new DeliveryResult('rejected', responseBody: $body),
            // 5xx and anything unexpected leave acceptance uncertain.
            default => new DeliveryResult('unknown', responseBody: $body),
        };
    }
}
