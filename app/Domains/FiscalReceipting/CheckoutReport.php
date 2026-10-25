<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\FolioSummary;
use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Outbox\OutboxMessage;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The fiscal check-out report of a stay (glossary): VFMS's report number and the folio
 * as VFMS settled it. Shown only once every VFMS message of the stay is accepted (FSC-07);
 * a Sandbox report has no portal QR, so it can never pass for a fiscal one.
 */
final class CheckoutReport
{
    public const PORTAL_URL = 'https://portalvfms.zanrevenue.org/checkout-report/guest/';

    /** @return array{status: 'waiting'|'sandbox'|'live', report: array<string, mixed>|null, portalUrl: string|null, qrSvg: string|null} */
    public static function of(Reservation $reservation): array
    {
        $messages = OutboxMessage::query()->where('integration', VfmsAdapter::INTEGRATION)
            ->where('subject_type', $reservation->getMorphClass())->where('subject_id', $reservation->id)->get();
        $checkout = $messages->firstWhere('event', 'checkout');
        if ($checkout === null || $messages->contains(fn (OutboxMessage $message) => $message->status !== 'delivered')) {
            return ['status' => 'waiting', 'report' => null, 'portalUrl' => null, 'qrSvg' => null];
        }
        $references = $checkout->getAttribute('provider_references');
        $currency = app(VfmsPayloads::class)->fromVfmsCurrency($checkout->payload['body']['currency'])->value;
        $summary = collect(FolioSummary::totals($reservation))->firstWhere('currency', $currency);
        $live = ($references['mode'] ?? null) === 'live';
        $url = $live ? self::PORTAL_URL.rawurlencode((string) $references['reportNo']) : null;

        return [
            'status' => $live ? 'live' : 'sandbox',
            'report' => [
                'number' => $references['reportNo'] ?? null, 'currency' => $currency,
                'room' => $summary['room'] ?? 0, 'extras' => $summary['extras'] ?? 0, 'vat' => $summary['vat'] ?? 0,
                'levy' => $summary['levy'] ?? 0, 'total' => $summary['charges'] ?? 0,
            ],
            'portalUrl' => $url,
            'qrSvg' => $url === null ? null : trim(substr($svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($url), strpos($svg, "\n") + 1)),
        ];
    }
}
