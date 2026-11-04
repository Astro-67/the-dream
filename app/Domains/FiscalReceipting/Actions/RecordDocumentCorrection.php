<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\FiscalReceipting\CheckoutFigures;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\DocumentCorrection;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
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
                    app(RestoreGate::class)->assertOpen($property->id);
                    $stay = Reservation::query()->where('property_id', $property->id)->whereKey($document->reservation_id)->lockForUpdate()->firstOrFail();
                    $credential = VfmsCredential::for($property);
                    if ($credential->mode === VfmsMode::Sandbox) {
                        throw new RuntimeException('ZRA does not provide error correction in Sandbox.');
                    }
                    if ($property->demo || $property->archived_at !== null || ! JurisdictionRules::usesVfms($property)) {
                        throw new RuntimeException('Issued-document corrections require an active real Zanzibar property.');
                    }
                    if (trim($reason) === '' || mb_strlen($reason) > 500) {
                        throw new RuntimeException('A reason of at most 500 characters is required.');
                    }
                    $documents = IssuedDocument::query()->where('property_id', $property->id)->whereIn('id', [$document->id, $replacementId])->orderBy('id')->lockForUpdate()->get();
                    $old = $documents->find($document->id);
                    $new = $documents->find($replacementId);
                    if ($old === null || $new === null || $old->id === $new->id || $old->number === $new->number
                        || $old->mode !== 'live' || $new->mode !== 'live' || $old->type !== $new->type
                        || $old->reservation_id !== $stay->id || $new->reservation_id !== $stay->id) {
                        throw new RuntimeException('Select two distinct issued Live documents of the same property, type and transaction.');
                    }
                    // Keep all submissions, even refused/unknown ones: none grants permission for another write.
                    if (DocumentCorrection::query()->where('property_id', $property->id)->where(function ($query) use ($old, $new) {
                        $query->whereIn('original_document_id', [$old->id, $new->id])->orWhere('replacement_document_id', $new->id);
                    })->lockForUpdate()->exists()) {
                        throw new RuntimeException('A document is already linked to a correction; reconcile the existing submission.');
                    }
                    [$oldTransaction, $business] = $this->evidence($old, $stay, $credential);
                    [$newTransaction] = $this->evidence($new, $stay, $credential);
                    if ($oldTransaction !== $newTransaction) {
                        throw new RuntimeException('Both issued documents must represent the same service or guest transaction.');
                    }
                    $id = (string) Str::ulid();
                    $message = app(VfmsTranslator::class)->recordCorrection($id, $stay, $property, $old, $new, trim($reason), $business);
                    $correction = DocumentCorrection::query()->create([
                        'id' => $id, 'original_document_id' => $old->id, 'replacement_document_id' => $new->id,
                        'original_number' => $old->number, 'replacement_number' => $new->number,
                        'actor_id' => $actor->id, 'reason' => trim($reason), 'mode' => 'live', 'unit_id' => $credential->unit_id,
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

    /** @return array{string, array<string, mixed>} */
    private function evidence(IssuedDocument $document, Reservation $stay, VfmsCredential $credential): array
    {
        $message = OutboxMessage::query()->where('property_id', $stay->property_id)->whereKey($document->outbox_message_id)->lockForUpdate()->firstOrFail();
        $references = $message->getAttribute('provider_references') ?? [];
        $payload = $message->payload;
        $attempt = $message->attempts()->whereKey($document->outbox_attempt_id)->where('outcome', 'accepted')->lockForUpdate()->first();
        if ($message->integration !== VfmsAdapter::INTEGRATION || $message->subject_type !== $stay->getMorphClass()
            || $message->subject_id !== $stay->id || $message->status !== 'delivered' || $attempt === null
            || ($payload['mode'] ?? null) !== 'live' || ($payload['reservation_id'] ?? null) !== $stay->id
            || ($references['mode'] ?? null) !== 'live' || ! is_int($references['unitId'] ?? null)
            || $references['unitId'] !== $credential->unit_id) {
            throw new RuntimeException('Accepted Live evidence with the same recorded business unit is required for both documents.');
        }
        $response = json_decode($attempt->response_body ?? '', true)['body'] ?? null;
        if ($document->type === 'services_receipt' && $message->event === 'services'
            && ($references['receiptNumber'] ?? null) === $document->number
            && is_array($response) && ($response['receiptNumber'] ?? null) === $document->number
            && filled($document->posting_id) && $document->posting_id === ($payload['posting_id'] ?? null)) {
            return ['service:'.$document->posting_id, $response];
        }
        if ($document->type === 'checkout_report' && in_array($message->event, ['checkout', 'guestDeparture'], true)
            && is_array($response) && app(CheckoutFigures::class)->of($response, $payload['body']) !== null) {
            $reports = collect($response)->where('reportNumber', $document->number)->values();
            $identity = $reports->count() === 1 ? ($reports[0]['guest']['documentNo'] ?? null) : null;
            if (is_string($identity) && $identity !== '') {
                return ['guest:'.$identity, $reports[0]];
            }
        }

        throw new RuntimeException('The selected document lacks uniquely matched accepted transaction evidence.');
    }
}
