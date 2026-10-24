<?php

namespace App\Domains\FiscalReceipting\Adapters;

use App\Domains\Billing\Actions\PostVfmsAdjustment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Money\Currency;
use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\RestoreReconciliationAdapter;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/**
 * The outbox adapter for VFMS: one message per stay at a time, in order. A message
 * goes live only when it was recorded live and the property is still live.
 */
final class VfmsAdapter implements DeliveryAdapter, RestoreReconciliationAdapter
{
    public const INTEGRATION = 'vfms';

    public function __construct(private readonly SandboxVfms $sandbox, private readonly HttpVfms $http, private readonly VfmsPayloads $payloads) {}

    public function policy(): DeliveryPolicy
    {
        return new DeliveryPolicy(unknownRetrySafe: false, maxBatchSize: 1, ordering: 'ordered');
    }

    public function send(array $messages): array
    {
        return array_map(function ($message) {
            $credential = VfmsCredential::for(Property::query()->findOrFail($message->property_id));
            $live = $message->payload['mode'] === VfmsMode::Live->value && $credential->mode === VfmsMode::Live;
            if ($live && ! $credential->isComplete()) {
                return new DeliveryResult('rejected', ['mode' => 'live'], ['error' => 'VFMS credentials are incomplete.'], messageId: $message->id);
            }
            $result = ($live ? $this->http : $this->sandbox)->send($message->payload, $credential);
            if ($live && $result->outcome === 'unknown') {
                $report = $this->reconcile([$message])[$message->id];
                if ($report['outcome'] === 'accepted') {
                    $result = new DeliveryResult('accepted', $report['references']);
                } elseif ($report['outcome'] === 'not_found') {
                    $result = $this->http->send($message->payload, $credential);
                }
            }
            if ($message->event === 'services' && $result->outcome === 'accepted' && filled($result->references['receiptNumber'] ?? null)) {
                Posting::query()->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $message->subject_id))
                    ->findOrFail($message->payload['posting_id'])
                    ->recordFiscalReceipt((string) $result->references['receiptNumber']);
            }
            if ($message->event === 'checkout' && $result->outcome === 'accepted') {
                $this->adjustFolio($message->payload, $result->references);
            }

            return new DeliveryResult($result->outcome, ['mode' => $live ? 'live' : 'sandbox', ...$result->references], $result->responseBody, $result->retryAt, $message->id);
        }, $messages);
    }

    /**
     * Posts the difference between Thedream's folio and VFMS's check-out report (ADR 0015).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, scalar|null>  $report
     */
    private function adjustFolio(array $payload, array $report): void
    {
        $currency = $payload['body']['currency'] === 'EURO' ? Currency::EUR : Currency::from($payload['body']['currency']);
        $minor = fn (string $field) => $this->payloads->minor((float) ($report[$field] ?? 0), $currency);
        app(PostVfmsAdjustment::class)->handle(Reservation::query()->findOrFail($payload['reservation_id']), $currency, [
            'report_number' => (string) ($report['reportNo'] ?? ''), 'total' => $minor('totalAmount'), 'tax' => $minor('totalTax'), 'levy' => $minor('infrastructureTax'),
        ]);
    }

    public function reconcile(array $messages): array
    {
        $reports = [];
        foreach ($messages as $message) {
            $reports[$message->id] = Tenancy::within(TenantContext::fromIds($message->organization_id, $message->property_id), function () use ($message): array {
                $property = Property::query()->findOrFail($message->property_id);
                $credential = VfmsCredential::for($property);
                if ($message->payload['request_type'] !== 'ACCOMMODATION_REQUEST'
                    || $message->payload['mode'] !== VfmsMode::Live->value
                    || $credential->mode !== VfmsMode::Live
                    || ! $credential->isComplete()) {
                    return ['outcome' => 'unresolved', 'evidence' => 'No live accommodation report available for this operation'];
                }
                $report = $this->http->accommodationReport($message->payload['body'], $credential, $property->timezone);
                if ($report['outcome'] === 'accepted') {
                    $report['references']['mode'] = 'live';
                }

                return $report;
            });
        }

        return $reports;
    }
}
