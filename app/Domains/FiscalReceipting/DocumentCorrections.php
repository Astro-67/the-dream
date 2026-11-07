<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\FiscalReceipting\Actions\RecordDocumentCorrection;
use App\Domains\FiscalReceipting\Models\DocumentCorrection;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Gate;

final class DocumentCorrections
{
    /** @return array<string, array<string, mixed>> */
    public static function forReservation(Reservation $reservation, bool $prepare = false): array
    {
        $documents = IssuedDocument::query()->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)->get();
        $annotations = self::annotations(Property::query()->findOrFail($reservation->property_id), $documents->modelKeys());

        return $documents->mapWithKeys(function (IssuedDocument $document) use ($prepare, $annotations): array {
            $history = $annotations[$document->id] ?? [];
            $allowed = $prepare && $history === [] && Gate::allows('correct', $document);

            return [$document->id => [
                'document' => $document->only(['id', 'number', 'type', 'issued_at', 'amount', 'currency', 'mode']),
                'annotations' => $history,
                'submitUrl' => $allowed ? route('issued-documents.corrections.store', $document) : null,
                ...($allowed ? app(RecordDocumentCorrection::class)->prepare($document) : ['replacements' => [], 'business' => [], 'refusal' => null]),
            ]];
        })->all();
    }

    /** @param list<string> $ids
     * @return array<string, list<array<string, mixed>>>
     */
    public static function annotations(Property $property, array $ids): array
    {
        $pairs = DocumentCorrection::query()->where('property_id', $property->id)
            ->where(fn ($query) => $query->whereIn('original_document_id', $ids)->orWhereIn('replacement_document_id', $ids))
            ->orderBy('id')->get();
        $documents = IssuedDocument::query()->where('property_id', $property->id)
            ->whereIn('id', $pairs->pluck('original_document_id')->merge($pairs->pluck('replacement_document_id')))->get()->keyBy('id');
        $reservations = Reservation::query()->where('property_id', $property->id)
            ->whereIn('id', $documents->pluck('reservation_id'))->get()->keyBy('id');
        $messages = OutboxMessage::query()->where('property_id', $property->id)->whereIn('id', $pairs->pluck('outbox_message_id'))->get()->keyBy('id');
        $result = [];
        foreach ($pairs as $pair) {
            $decision = $pair->decision();
            foreach ([$pair->original_document_id, $pair->replacement_document_id] as $id) {
                $original = $id === $pair->original_document_id;
                $other = $documents->get($original ? $pair->replacement_document_id : $pair->original_document_id);
                if ($other === null || ! $documents->has($id)) {
                    continue;
                }
                $stay = $reservations->get($other->getAttribute('reservation_id'));
                $result[$id][] = [
                    'label' => $original
                        ? ($decision === 'approved' ? __('Replaced by') : __('Proposed to be replaced by'))
                        : ($decision === 'approved' ? __('Replacement for') : __('Proposed replacement for')),
                    'number' => $other->number,
                    'url' => $stay !== null && Gate::allows('viewFiscalReport', $stay)
                        ? IssuedDocuments::reportUrl($stay, $other->type, $other->number) : null,
                    'delivery' => $messages->get($pair->outbox_message_id)?->status,
                    'decision' => $decision,
                    'refreshUrl' => Gate::allows('refresh', $pair) ? route('document-corrections.refresh', $pair) : null,
                ];
            }
        }

        return $result;
    }
}
