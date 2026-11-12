<?php

namespace App\Domains\FiscalReceipting\Adapters;

use App\Domains\Billing\Actions\CloseCustomerAccount;
use App\Domains\Billing\Actions\PostVfmsAdjustment;
use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Actions\IssueGeneralReceipt;
use App\Domains\FiscalReceipting\CheckoutFigures;
use App\Domains\FiscalReceipting\CheckoutReport;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\IssuedDocuments;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\FiscalReceipting\VfmsTranslator;
use App\Domains\Reservations\BookingGroupMembers;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\ReservationLifecycleEvent;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\AcceptanceProjector;
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
final class VfmsAdapter implements AcceptanceProjector, DeliveryAdapter, OutboxCorrectionAdapter, RestoreReconciliationAdapter
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
        if ($message->event === 'errorSave') {
            throw new RuntimeException('Issued-document corrections require reconciliation, not filing recovery.');
        }
        $property = Property::query()->findOrFail($message->property_id);
        if ($message->event === 'generalReceipt') {
            $subject = $message->subject_type === (new CustomerAccount)->getMorphClass()
                ? CustomerAccount::query()->where('property_id', $message->property_id)->findOrFail($message->subject_id)
                : Reservation::query()->where('property_id', $message->property_id)->findOrFail($message->subject_id);

            return $this->rebuildGeneralReceipt($message, $subject, $property);
        }
        $reservation = Reservation::query()->where('property_id', $message->property_id)->findOrFail($message->subject_id);
        if ($message->integration !== self::INTEGRATION || $message->subject_type !== $reservation->getMorphClass()
            || ($message->payload['reservation_id'] ?? null) !== $reservation->id
            || ($message->payload['mode'] ?? null) !== VfmsCredential::for($property)->mode->value) {
            throw new RuntimeException('The filing no longer matches this stay and VFMS mode.');
        }
        $event = ReservationLifecycleEvent::query()->where('reservation_id', $reservation->id)
            ->find(explode(':', $message->idempotency_key)[1] ?? null);
        $call = $event === null ? null : VfmsTranslator::callFor($event);
        if ($call === null || $call[2] !== $message->event
            || $call[0] !== ($message->payload['request_type'] ?? null) || $call[1] !== ($message->payload['path'] ?? null)) {
            throw new RuntimeException('The original lifecycle evidence is required to rebuild this filing.');
        }
        // shortcut: profiles have no historical target snapshot; reconcile later identity changes before re-filing.
        if ($reservation->lifecycleEvents()->where('sequence', '>', $event->sequence)
            ->where('type', 'guest_profile_changed')->exists()) {
            throw new RuntimeException('Later guest profile changes require reconciliation before re-filing this operation.');
        }
        // shortcut: check-in has no guest-membership snapshot; reconstruct that history before supporting later guest changes.
        if (in_array($message->event, ['accommodation', 'advanceAccommodation'], true) && $reservation->lifecycleEvents()->where('sequence', '>', $event->sequence)
            ->whereIn('type', ['guest_added', 'guest_profile_changed'])->exists()) {
            throw new RuntimeException('Later guest changes require reconciliation before re-filing this check-in.');
        }

        $body = $this->payloads->{$call[2]}($event, $reservation, $property);
        if ($message->event === 'advanceBooking'
            && array_diff_key($body, ['referenceNumber' => true]) != array_diff_key($message->payload['body'], ['referenceNumber' => true])) {
            throw new RuntimeException('Changed advance booking history requires reconciliation before re-filing.');
        }
        if (($message->event === 'changeRoom' && array_intersect_key($body, array_flip(['accommodationPrice', 'currency', 'accommodationDiscount']))
                != array_intersect_key($message->payload['body'], array_flip(['accommodationPrice', 'currency', 'accommodationDiscount'])))
            || ($message->event === 'changeRoomPrice' && $body['rooms'][0]['roomPrice'] != $message->payload['body']['rooms'][0]['roomPrice'])) {
            throw new RuntimeException('Changed financial history requires reconciliation before re-filing this operation.');
        }

        return [...$message->payload, 'body' => $body];
    }

    /**
     * Re-filing a refused general receipt reclaims its own postings, which the refusal released;
     * one another receipt has claimed, receipted, reversed or moved since cannot be re-sent.
     *
     * @return array<string, mixed>
     */
    private function rebuildGeneralReceipt(OutboxMessage $message, Reservation|CustomerAccount $reservation, Property $property): array
    {
        $ownerKey = $reservation instanceof CustomerAccount ? 'customer_account_id' : 'reservation_id';
        if ($message->subject_type !== $reservation->getMorphClass() || ($message->payload[$ownerKey] ?? null) !== $reservation->id
            || ($message->payload['mode'] ?? null) !== VfmsCredential::for($property)->mode->value) {
            throw new RuntimeException('The filing no longer matches this stay and VFMS mode.');
        }
        $ids = $message->payload['posting_ids'] ?? [];
        if ($message->status === 'rejected') {
            $held = $reservation instanceof CustomerAccount
                ? CloseCustomerAccount::held($reservation)
                : IssueGeneralReceipt::held(app(BookingGroupMembers::class)->reservations($reservation, lock: true));
            $ids = $held->where('currency', $this->payloads->fromVfmsCurrency($message->payload['body']['currency']))->lockForUpdate()->pluck('id')->all();
            if ($ids === []) {
                throw new RuntimeException('Nothing is waiting for a ZRA receipt.');
            }
        }
        $postings = Posting::query()->where('property_id', $property->id)->whereKey($ids)->orderBy('posted_at')->orderBy('id')->lockForUpdate()->get();
        if ($postings->count() !== count($ids) || $postings->contains(fn (Posting $posting) => $posting->vfms_receipt_number !== null
            || ! in_array($posting->general_receipt_id, [null, $message->id], true)
            || Posting::query()->where('related_posting_id', $posting->id)->exists())) {
            throw new RuntimeException('Some of these extras were receipted, reversed or moved since; issue a new ZRA receipt instead.');
        }
        Posting::query()->whereKey($ids)->toBase()->update(['general_receipt_id' => $message->id]);

        return [...$message->payload, 'posting_ids' => $ids, 'body' => $this->payloads->generalReceipt($message->payload['body']['documentNumber'], $postings, $message->payload['body']['referenceNumber'])];
    }

    public function send(array $messages): array
    {
        return array_map(function ($message) {
            $result = $this->deliver($message);
            if ($message->event === 'generalReceipt') {
                $claimed = Posting::query()->where('property_id', $message->property_id)->where('general_receipt_id', $message->id)->whereKey($message->payload['posting_ids']);
                if ($result->outcome === 'accepted' && filled($result->references['receiptNumber'] ?? null)) {
                    $claimed->get()->each->recordFiscalReceipt((string) $result->references['receiptNumber']);
                } elseif ($result->outcome === 'rejected') {
                    // ZRA kept nothing, so the extras go back to "not yet receipted"; unknown keeps them claimed.
                    $claimed->toBase()->update(['general_receipt_id' => null]);
                }
            }

            return $result;
        }, $messages);
    }

    private function deliver(OutboxMessage $message): DeliveryResult
    {
        $property = Property::query()->findOrFail($message->property_id);
        if ($message->event === 'errorSave' && ($property->demo || $property->archived_at !== null
            || ! JurisdictionRules::usesVfms($property))) {
            return new DeliveryResult('rejected', responseBody: ['error' => 'Issued-document corrections require an active real Zanzibar property.'], messageId: $message->id);
        }
        $credential = VfmsCredential::for($property);
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

        return new DeliveryResult($result->outcome, ['mode' => $credential->mode->value, ...(isset($message->payload['unit_id']) ? ['unitId' => $message->payload['unit_id']] : []), ...$result->references, ...$this->links($live, $result)], $result->responseBody, $result->retryAt, $message->id);
    }

    /**
     * The ZRA viewer links of an accepted filing, fixed when it is accepted so every reprint shows the
     * QR first printed: one per check-out report number (as JSON, references being scalars) or the receipt's.
     *
     * @return array<string, string>
     */
    private function links(bool $live, DeliveryResult $result): array
    {
        if ($result->outcome !== 'accepted') {
            return [];
        }
        $numbers = is_string($result->references['reportNumbers'] ?? null) ? json_decode($result->references['reportNumbers'], true) : null;
        if (is_array($numbers)) {
            $urls = array_filter(array_combine($numbers, array_map(fn (string $number) => CheckoutReport::viewerUrl($live, 'checkout-report/guest', $number), $numbers)));

            return $urls === [] ? [] : ['reportUrls' => json_encode($urls, JSON_THROW_ON_ERROR)];
        }
        $receipt = $result->references['receiptNumber'] ?? null;
        $url = is_string($receipt) && $receipt !== '' ? CheckoutReport::viewerUrl($live, 'receipt-form', $receipt) : null;

        return $url === null ? [] : ['receiptUrl' => $url];
    }

    /**
     * Posts the difference between Karafuu's folio and VFMS's check-out report (ADR 0015).
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

    public function projectAccepted(OutboxMessage $message): void
    {
        app(IssuedDocuments::class)->project($message);
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
