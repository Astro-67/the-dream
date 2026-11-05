<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\CheckoutFigures;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\DocumentCorrection;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\FiscalReceipting\VfmsTranslator;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\RestoreGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class RecordDocumentCorrection
{
    private const OTHER_MODE = 'Both documents must have been filed in the property\'s current VFMS mode.';

    private const UNIT_EVIDENCE = 'Accepted evidence with the same recorded business unit is required for both documents.';

    /** The one guard for recording and refreshing corrections: only active real Zanzibar properties correct their filings. */
    public static function assertCorrectable(Property $property): void
    {
        if ($property->demo || $property->archived_at !== null || ! JurisdictionRules::usesVfms($property)) {
            throw new RuntimeException('Issued-document corrections require an active real Zanzibar property.');
        }
    }

    /** @return array{business: array<string, mixed>, replacements: list<array<string, mixed>>, refusal: string|null} */
    public function prepare(IssuedDocument $document): array
    {
        $property = Property::query()->findOrFail($document->property_id);
        $credential = VfmsCredential::for($property);
        $empty = ['business' => [], 'replacements' => []];
        try {
            if ($document->mode !== $credential->mode->value) {
                throw new RuntimeException(self::OTHER_MODE);
            }
            self::assertCorrectable($property);
            $stay = Reservation::query()->where('property_id', $property->id)->findOrFail($document->reservation_id);
            [$transaction, $business, $unit] = $this->evidence($document, $stay, $credential);
            $identity = app(VfmsPayloads::class)->correctionIdentity($property, $business);
            $linked = DocumentCorrection::query()->where('property_id', $property->id)->get();
            $used = $linked->pluck('original_document_id')->merge($linked->pluck('replacement_document_id'));
            $replacements = [];
            foreach (IssuedDocument::query()->where('property_id', $property->id)
                ->where('reservation_id', $stay->id)->where('type', $document->type)->where('mode', $credential->mode->value)
                ->whereKeyNot($document->id)->whereNotIn('id', $used)->orderByDesc('issued_at')->get() as $candidate) {
                try {
                    [$candidateTransaction, , $candidateUnit] = $this->evidence($candidate, $stay, $credential);
                    if ($candidateTransaction === $transaction && $candidateUnit === $unit) {
                        $replacements[] = $candidate->only(['id', 'number', 'type', 'issued_at', 'amount', 'currency', 'mode']);
                    }
                } catch (RuntimeException) {
                    continue;
                }
            }

            return ['business' => ['name' => $identity['b_unit_name'], 'email' => $identity['email'], 'phone' => $identity['phone_no'], 'znumber' => $identity['zrb_number'], 'unitId' => $identity['unitId']],
                'replacements' => $replacements, 'refusal' => null];
        } catch (RuntimeException|ValidationException $refused) {
            return [...$empty, 'refusal' => __($refused->getMessage())];
        }
    }

    public function handle(User $actor, IssuedDocument $document, string $replacementId, string $reason): DocumentCorrection
    {
        return Audit::validationRefusal($actor, $document, 'Issued-document correction refused', function () use ($actor, $document, $replacementId, $reason): DocumentCorrection {
            $authorization = Gate::forUser($actor)->inspect('correct', $document);
            if ($authorization->denied()) {
                Audit::record($actor, 'refused', $document, 'Issued-document correction refused', ['result' => 'refused', 'stage' => 'authorization']);
            }
            $authorization->authorize();
            try {
                return DB::transaction(function () use ($actor, $document, $replacementId, $reason): DocumentCorrection {
                    $property = Property::query()->whereKey($document->property_id)->lockForUpdate()->firstOrFail();
                    if (app(RestoreGate::class)->isClosed($property->id)) {
                        throw new RuntimeException('Outbox restore gate is closed for this property.');
                    }
                    $stay = Reservation::query()->where('property_id', $property->id)->whereKey($document->reservation_id)->lockForUpdate()->firstOrFail();
                    $credential = VfmsCredential::for($property);
                    self::assertCorrectable($property);
                    $documents = IssuedDocument::query()->where('property_id', $property->id)->whereIn('id', [$document->id, $replacementId])->orderBy('id')->lockForUpdate()->get();
                    $old = $documents->find($document->id);
                    $new = $documents->find($replacementId);
                    if ($old === null || $new === null || $old->id === $new->id || $old->number === $new->number
                        || $old->type !== $new->type || $old->reservation_id !== $stay->id || $new->reservation_id !== $stay->id) {
                        throw new RuntimeException('Select two distinct issued documents of the same property, type and stay.');
                    }
                    if ($old->mode !== $credential->mode->value || $new->mode !== $credential->mode->value) {
                        throw new RuntimeException(self::OTHER_MODE);
                    }
                    // Keep all submissions, even refused/unknown ones: none grants permission for another write.
                    if (DocumentCorrection::query()->where('property_id', $property->id)->where(function ($query) use ($old, $new) {
                        $query->whereIn('original_document_id', [$old->id, $new->id])->orWhere('replacement_document_id', $new->id);
                    })->lockForUpdate()->exists()) {
                        throw new RuntimeException('A document is already linked to a correction; reconcile the existing submission.');
                    }
                    [$oldTransaction, $business, $oldUnit] = $this->evidence($old, $stay, $credential);
                    [$newTransaction, , $newUnit] = $this->evidence($new, $stay, $credential);
                    if ($oldTransaction !== $newTransaction) {
                        throw new RuntimeException('Both issued documents must be for the same service or the same guest.');
                    }
                    if ($oldUnit !== $newUnit) {
                        throw new RuntimeException(self::UNIT_EVIDENCE);
                    }
                    $id = (string) Str::ulid();
                    $message = app(VfmsTranslator::class)->recordCorrection($id, $stay, $property, $old, $new, trim($reason), $business);
                    $correction = DocumentCorrection::query()->create([
                        'id' => $id, 'original_document_id' => $old->id, 'replacement_document_id' => $new->id,
                        'original_number' => $old->number, 'replacement_number' => $new->number,
                        'actor_id' => $actor->id, 'reason' => trim($reason), 'mode' => $credential->mode->value, 'unit_id' => $message->payload['body']['unitId'],
                        'outbox_message_id' => $message->id,
                    ]);
                    // The reason is encrypted on the pair; ordinary audit properties contain identifiers only.
                    Audit::record($actor, 'document_correction_recorded', $correction, 'Recorded issued-document correction', [
                        'original_document_id' => $old->id, 'replacement_document_id' => $new->id, 'outbox_message_id' => $message->id,
                    ]);

                    return $correction;
                });
            } catch (RuntimeException $refused) {
                throw ValidationException::withMessages(['correction' => __($refused->getMessage())]);
            }
        });
    }

    /**
     * The accepted filing's transaction key, ZRA's business header and its business unit: the property's
     * snapshotted Live unit, or in Sandbox the shared test unit ZRA returned as businessUnitId.
     *
     * @return array{string, array<string, mixed>, int}
     */
    private function evidence(IssuedDocument $document, Reservation $stay, VfmsCredential $credential): array
    {
        $mode = $credential->mode->value;
        $message = OutboxMessage::query()->where('property_id', $stay->property_id)->whereKey($document->outbox_message_id)->lockForUpdate()->firstOrFail();
        $references = $message->getAttribute('provider_references') ?? [];
        $payload = $message->payload;
        $attempt = $message->attempts()->whereKey($document->outbox_attempt_id)->where('outcome', 'accepted')->lockForUpdate()->first();
        if ($message->integration !== VfmsAdapter::INTEGRATION || $message->subject_type !== $stay->getMorphClass()
            || $message->subject_id !== $stay->id || $message->status !== 'delivered' || $attempt === null
            || ($payload['mode'] ?? null) !== $mode || ($payload['reservation_id'] ?? null) !== $stay->id
            || ($references['mode'] ?? null) !== $mode
            || ($mode === VfmsMode::Live->value && (! is_int($references['unitId'] ?? null) || $references['unitId'] !== $credential->unit_id))) {
            throw new RuntimeException(self::UNIT_EVIDENCE);
        }
        $response = json_decode($attempt->response_body ?? '', true)['body'] ?? null;
        $unit = fn (array $business): int => $mode === VfmsMode::Live->value ? $references['unitId']
            : (is_int($business['businessUnitId'] ?? null) ? $business['businessUnitId'] : throw new RuntimeException(self::UNIT_EVIDENCE));
        if ($document->type === 'services_receipt' && $message->event === 'services'
            && ($references['receiptNumber'] ?? null) === $document->number
            && is_array($response) && ($response['receiptNumber'] ?? null) === $document->number
            && filled($document->posting_id) && $document->posting_id === ($payload['posting_id'] ?? null)) {
            return ['service:'.$document->posting_id, $response, $unit($response)];
        }
        if ($document->type === 'checkout_report' && in_array($message->event, ['checkout', 'guestDeparture'], true)
            && is_array($response) && app(CheckoutFigures::class)->of($response, $payload['body']) !== null) {
            $reports = collect($response)->where('reportNumber', $document->number)->values();
            $identity = $reports->count() === 1 ? ($reports[0]['guest']['documentNo'] ?? null) : null;
            if (is_string($identity) && $identity !== '') {
                return ['guest:'.$identity, $reports[0], $unit($reports[0])];
            }
        }

        throw new RuntimeException('The selected document has no accepted VFMS answer that matches it uniquely.');
    }
}
