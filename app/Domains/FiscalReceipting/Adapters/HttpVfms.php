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

    /** @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function accommodationReport(array $body, VfmsCredential $credential): array
    {
        $evidence = 'VFMS accommodation report by reference';
        $unresolved = ['outcome' => 'unresolved', 'evidence' => $evidence];
        $result = $this->send([
            'mode' => 'live',
            'request_type' => 'ACCOMMODATION_REPORT',
            'path' => 'accommodationReport/',
            'body' => ['startDate' => $body['checkInDate'], 'endDate' => now()->toDateString(), 'refno' => $body['referenceNumber']],
        ], $credential);
        $rows = $result->responseBody['body'] ?? null;
        if ($result->outcome !== 'accepted' || ($result->responseBody['report_is_list'] ?? false) !== true || ! is_array($rows) || ! array_is_list($rows)) {
            return $unresolved;
        }
        // shortcut: guide 1.6 omits the response schema; unfamiliar report shapes stay unresolved until verified with VFMS.
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['referenceNumber'] ?? null) || trim($row['referenceNumber']) === '') {
                return $unresolved;
            }
        }
        $matches = array_values(array_filter($rows, fn (array $row): bool => $row['referenceNumber'] === $body['referenceNumber']));
        if ($matches === []) {
            return ['outcome' => 'not_found', 'evidence' => $evidence];
        }
        if (count($matches) !== 1 || ! is_string($matches[0]['receiptNumber'] ?? null) || trim($matches[0]['receiptNumber']) === '') {
            return $unresolved;
        }

        return ['outcome' => 'accepted', 'evidence' => $evidence, 'references' => ['receiptNumber' => $matches[0]['receiptNumber']]];
    }

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
        if ($payload['request_type'] === 'ACCOMMODATION_REPORT') {
            $body['report_is_list'] = is_array($response->object());
        }
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
