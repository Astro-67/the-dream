<?php

namespace App\Domains\FiscalReceipting\Adapters;

use App\Domains\Billing\Actions\PostVfmsAdjustment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\CheckoutFigures;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\FiscalReceipting\VfmsTranslator;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\OutboxCorrectionAdapter;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\RestoreReconciliationAdapter;
use RuntimeException;

/**
 * The outbox adapter for VFMS: one message per stay at a time, in order. A message
 * goes live only when it was recorded live and the property is still live.
 */
final class VfmsAdapter implements DeliveryAdapter, OutboxCorrectionAdapter, RestoreReconciliationAdapter
{
    public const INTEGRATION = 'vfms';

    public function __construct(private readonly HttpVfms $http, private readonly VfmsPayloads $payloads) {}

    public function policy(): DeliveryPolicy
    {
        return new DeliveryPolicy(unknownRetrySafe: false, maxBatchSize: 1, ordering: 'ordered');
    }

    /** A confirmed rejection keeps its outbox identity but gets a fresh wire reference. */
    public function changedPayloadKeyReuseSafe(): bool
    {
        return true;
    }

    public function correct(OutboxMessage $rejected, array $correction): array
    {
        $payload = $this->rebuild($rejected);
        if (isset($payload['body']['referenceNumber'])) {
            $payload['body']['referenceNumber'] .= 'R'.((int) $rejected->revisions()->max('revision') + 1);
        }

        return $payload;
    }

    public function rebuildSuccessor(OutboxMessage $successor, array $correctedRejectedPayload): array
    {
        return $this->rebuild($successor);
    }

    /** @return array<string, mixed> */
    private function rebuild(OutboxMessage $message): array
    {
        $reservation = Reservation::query()->where('property_id', $message->property_id)->findOrFail($message->subject_id);
        $property = Property::query()->findOrFail($message->property_id);
        if ($message->integration !== self::INTEGRATION || $message->subject_type !== $reservation->getMorphClass()
            || ($message->payload['reservation_id'] ?? null) !== $reservation->id
            || ($message->payload['mode'] ?? null) !== VfmsCredential::for($property)->mode->value) {
            throw new RuntimeException('The filing no longer matches this stay and VFMS mode.');
        }
        $event = ReservationLifecycleEvent::query()->where('reservation_id', $reservation->id)
            ->find(explode(':', $message->idempotency_key)[1] ?? null);
        $call = $event === null ? null : (VfmsTranslator::CALLS[$event->type] ?? null);
        if ($call === null || $call[2] !== $message->event
            || $call[0] !== ($message->payload['request_type'] ?? null) || $call[1] !== ($message->payload['path'] ?? null)) {
            throw new RuntimeException('The original lifecycle evidence is required to rebuild this filing.');
        }
        // shortcut: check-in has no guest-membership snapshot; reconstruct that history before supporting later guest changes.
        if ($message->event === 'accommodation' && $reservation->lifecycleEvents()->where('sequence', '>', $event->sequence)
            ->whereIn('type', ['guest_added', 'guest_profile_changed'])->exists()) {
            throw new RuntimeException('Later guest changes require reconciliation before re-filing this check-in.');
        }

        return [...$message->payload, 'body' => $this->payloads->{$call[2]}($event, $reservation, $property)];
    }

    public function send(array $messages): array
    {
        return array_map(function ($message) {
            $credential = VfmsCredential::for(Property::query()->findOrFail($message->property_id));
            if ($message->payload['mode'] !== $credential->mode->value) {
                return new DeliveryResult('rejected', responseBody: ['error' => 'VFMS mode changed; review the queued filing before sending.'], messageId: $message->id);
            }
            $live = $credential->mode === VfmsMode::Live;
            if ($live && ! $credential->isComplete()) {
                return new DeliveryResult('rejected', ['mode' => 'live'], ['error' => 'VFMS credentials are incomplete.'], messageId: $message->id);
            }
            $result = $this->http->send($message->payload, $credential);
            if ($message->event === 'services' && $result->outcome === 'accepted' && filled($result->references['receiptNumber'] ?? null)) {
                Posting::query()->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $message->subject_id))
                    ->findOrFail($message->payload['posting_id'])
                    ->recordFiscalReceipt((string) $result->references['receiptNumber']);
            }
            if ($message->event === 'checkout' && $result->outcome === 'accepted') {
                $reports = $result->responseBody['body'] ?? null;
                if (is_array($reports) && array_is_list($reports)) {
                    $request = $message->payload['body'];
                    $departures = OutboxMessage::query()->where('integration', self::INTEGRATION)
                        ->where('subject_type', $message->subject_type)->where('subject_id', $message->subject_id)
                        ->where('event', 'guestDeparture')->where('status', 'delivered')->get();
                    foreach ($departures as $departure) {
                        $attempt = $departure->attempts()->where('outcome', 'accepted')->orderByDesc('attempt_number')->first();
                        $previous = $attempt?->response_body === null ? null : json_decode($attempt->response_body, true, flags: JSON_THROW_ON_ERROR)['body'] ?? null;
                        if (! is_array($previous) || ! array_is_list($previous)
                            || ($departure->getAttribute('provider_references')['mode'] ?? null) !== $message->payload['mode']) {
                            return new DeliveryResult('unknown', responseBody: $result->responseBody, messageId: $message->id);
                        }
                        $reports = [...$previous, ...$reports];
                        $request['documentNumberList'] = [...$departure->payload['body']['documentNumberList'], ...$request['documentNumberList']];
                    }
                    $figures = app(CheckoutFigures::class)->of($reports, $request);
                    if ($figures === null) {
                        return new DeliveryResult('unknown', responseBody: $result->responseBody, messageId: $message->id);
                    }
                    $currency = $this->payloads->fromVfmsCurrency($request['currency']);
                    $result = new DeliveryResult('accepted', [
                        ...$result->references,
                        'reportNumbers' => json_encode($figures['numbers'], JSON_THROW_ON_ERROR),
                        'roomAmount' => $this->payloads->amount($figures['room'], $currency),
                        'extrasAmount' => $this->payloads->amount($figures['extras'], $currency),
                        'totalAmount' => $this->payloads->amount($figures['total'], $currency),
                        'totalTax' => $this->payloads->amount($figures['tax'], $currency),
                        'infrastructureTax' => $this->payloads->amount($figures['levy'], $currency),
                    ], $result->responseBody);
                }
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
        $currency = $this->payloads->fromVfmsCurrency($payload['body']['currency']);
        $minor = fn (string $field) => $this->payloads->minor((float) $report[$field], $currency);
        app(PostVfmsAdjustment::class)->handle(Reservation::query()->findOrFail($payload['reservation_id']), $currency, [
            'report_number' => (string) $report['reportNo'],
            'total' => $minor('totalAmount'), 'tax' => $minor('totalTax'), 'levy' => $minor('infrastructureTax'),
        ]);
    }

    public function reconcile(array $messages): array
    {
        $reports = [];
        foreach ($messages as $message) {
            $reports[$message->id] = ['outcome' => 'unresolved', 'evidence' => 'VFMS reference lookup and replay safety are not verified'];
        }

        return $reports;
    }
}
