<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\FiscalReceipting\Actions\RecordDocumentCorrection;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Support\Facades\Gate;

final class DocumentCorrections
{
    /** @return array<string, array<string, mixed>> */
    public static function forReservation(Reservation $reservation): array
    {
        return IssuedDocument::query()->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)->get()->mapWithKeys(function (IssuedDocument $document): array {
                $allowed = Gate::allows('correct', $document);

                return [$document->id => [
                    'document' => $document->only(['id', 'number', 'type', 'issued_at', 'amount', 'currency', 'mode']),
                    'submitUrl' => $allowed ? route('issued-documents.corrections.store', $document) : null,
                    ...($allowed ? app(RecordDocumentCorrection::class)->prepare($document) : ['replacements' => [], 'business' => [], 'refusal' => null]),
                ]];
            })->all();
    }
}
