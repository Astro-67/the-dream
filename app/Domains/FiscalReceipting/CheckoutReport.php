<?php

namespace App\Domains\FiscalReceipting;

use App\Domains\Billing\FolioSummary;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
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
 * identity documents stay in encrypted attempts and are projected only for authorized viewers.
 */
final class CheckoutReport
{
    public const PORTAL_URL = 'https://portalvfms.zanrevenue.org/checkout-report/guest/';

    /** @return array<string, mixed> */
    public static function of(Reservation $reservation, bool $viewIdentity = false): array
    {
        $messages = OutboxMessage::query()->where('integration', VfmsAdapter::INTEGRATION)
            ->where('subject_type', $reservation->getMorphClass())->where('subject_id', $reservation->id)
            ->with(['attempts' => fn ($query) => $query->where('outcome', 'accepted')->orderByDesc('attempt_number')])
            ->orderBy('sequence')->get();
        $checkout = $messages->firstWhere('event', 'checkout');
        if ($checkout === null || $messages->contains(fn (OutboxMessage $message) => $message->status !== 'delivered')) {
            return [
                'status' => 'waiting', 'report' => null, 'portalUrl' => null, 'qrSvg' => null, 'guestReports' => [], 'business' => null, 'serviceReceipts' => [],
                'needsAttention' => $checkout !== null && $checkout->status === 'unknown',
            ];
        }
        $references = $checkout->getAttribute('provider_references');
        $payloads = app(VfmsPayloads::class);
        $currency = $payloads->fromVfmsCurrency($checkout->payload['body']['currency']);
        $summary = collect(FolioSummary::totals($reservation))->firstWhere('currency', $currency->value);
        $live = ($references['mode'] ?? null) === 'live';
        $url = $live ? self::PORTAL_URL.rawurlencode((string) $references['reportNo']) : null;
        $guestReports = [];
        $business = null;
        foreach ($messages->whereIn('event', ['checkout', 'guestDeparture']) as $message) {
            $attempt = $message->attempts->first();
            $body = $attempt?->response_body === null ? null : json_decode($attempt->response_body, true, flags: JSON_THROW_ON_ERROR)['body'] ?? null;
            if (! is_array($body) || app(CheckoutFigures::class)->of($body, $message->payload['body']) === null) {
                continue;
            }
            foreach ($body as $entry) {
                $number = $entry['reportNumber'];
                $guestUrl = self::viewerUrl(($message->getAttribute('provider_references')['mode'] ?? null) === 'live', 'checkout-report/guest', $number);
                $business ??= array_filter(array_intersect_key($entry, array_flip(['businessName', 'street', 'tinNumber', 'vrnNumber', 'znumber'])), is_scalar(...));
                $guestReports[] = [
                    'number' => $number,
                    'name' => $entry['guest']['fullName'] ?? null,
                    'identity' => $viewIdentity ? array_intersect_key($entry['guest'], array_flip(['documentName', 'country'])) : null,
                    'departedEarly' => $message->event === 'guestDeparture',
                    'portalUrl' => $guestUrl,
                    'qrSvg' => $guestUrl === null ? null : self::qr($guestUrl),
                ];
            }
        }

        return [
            'status' => $live ? 'live' : 'sandbox',
            'report' => [
                'number' => $references['reportNo'] ?? null, 'currency' => $currency->value,
                'room' => isset($references['roomAmount']) ? $payloads->minor($references['roomAmount'], $currency) : ($summary['room'] ?? 0),
                'extras' => isset($references['extrasAmount']) ? $payloads->minor($references['extrasAmount'], $currency) : ($summary['extras'] ?? 0),
                'vat' => $summary['vat'] ?? 0,
                'levy' => $summary['levy'] ?? 0, 'total' => $summary['charges'] ?? 0,
            ],
            'portalUrl' => $url,
            'qrSvg' => $url === null ? null : self::qr($url),
            'guestReports' => $guestReports,
            'business' => $business,
            'needsAttention' => false,
            'serviceReceipts' => Posting::query()->whereIn('folio_id', Folio::query()->select('id')->where('reservation_id', $reservation->id))
                ->where('currency', $currency->value)->whereNotNull('vfms_receipt_number')->orderBy('posted_at')->orderBy('id')->get()
                ->map(function (Posting $posting) use ($live) {
                    $receiptUrl = self::viewerUrl($live, 'receipt-form', $posting->vfms_receipt_number);

                    return [
                        'number' => $posting->vfms_receipt_number, 'description' => $posting->description, 'amount' => $posting->amount,
                        'portalUrl' => $receiptUrl, 'qrSvg' => $receiptUrl === null ? null : self::qr($receiptUrl),
                    ];
                })->all(),
        ];
    }

    private static function viewerUrl(bool $live, string $path, string $number): ?string
    {
        $base = $live ? 'https://portalvfms.zanrevenue.org' : config('services.vfms.test_viewer_url');
        if (blank($base) || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        return rtrim($base, '/').'/'.$path.'/'.rawurlencode($number);
    }

    private static function qr(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 4), new SvgImageBackEnd)))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
